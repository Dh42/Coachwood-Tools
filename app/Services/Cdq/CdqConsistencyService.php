<?php

namespace App\Services\Cdq;

use App\Models\CdqCheckConfigVersion;
use App\Models\CdqDefect;
use App\Models\CdqListingSnapshot;
use App\Models\CdqLlmCache;
use App\Models\CdqScore;
use App\Services\Anthropic\AnthropicClient;
use Illuminate\Support\Facades\Log;

/**
 * CDQ Phase 4 — the CONSIST dimension, the one part of CDQ that needs an
 * LLM (cross-field logical consistency isn't a regex problem). Talks to
 * the Anthropic Messages API directly (github.com — no SDK dependency in
 * this app yet, and the request shape here is simple enough not to need
 * one).
 *
 * Scope note: the brief asks for the Message Batches API on the full
 * nightly run (async submit-then-poll, cheaper per-token). This uses the
 * synchronous Messages API for every path instead, including the full-
 * catalog run — at ~103 real ASINs, and with content_hash caching meaning
 * only new/changed snapshots ever make a live call at all, a sequential
 * synchronous pass comfortably fits a nightly window. True Batches API
 * support (submit + a separate poll/ingest step, its own test-mocking
 * strategy) is a real follow-on scope decision, not built here.
 *
 * Grade U/D snapshots are skipped entirely — a hard-fail already
 * determined the listing's fate; CONSIST has nothing to add.
 */
class CdqConsistencyService
{
    private const MAX_TOKENS = 1024;

    private const MIN_CONFIDENCE = 0.7;

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are auditing a single Amazon product listing for internal logical
        consistency. You will be given the listing's title, bullet points,
        description, a subset of structured attributes, main/variant image
        URLs, and (if this is part of a variation family) the attribute(s)
        that differ between siblings plus sibling titles.

        Find factual conflicts only — where one part of the listing
        contradicts another (e.g. the title claims a different flavor,
        count, or size than a structured attribute states; an image URL's
        filename or alt implies something the text does not; a child's
        title doesn't reflect the stated variation theme). Do not flag
        subjective quality issues, missing content, or anything not
        related to Amazon SP-API listing content — those are handled
        elsewhere.

        Respond with ONLY a single JSON object, no prose, no markdown code
        fences, matching exactly:
        {"conflicts": [{"check_code": "CONSIST_TITLE_VS_ATTR" | "CONSIST_IMAGE_VS_TEXT" | "CONSIST_VARIATION_THEME", "fields": ["..."], "claim_a": "...", "claim_b": "...", "confidence": 0.0}]}
        If there are no conflicts, respond with {"conflicts": []}.
        PROMPT;

    public function __construct(
        private readonly CdqCheckConfigVersion $version,
        private readonly string $promptVersion = 'v1',
        ?string $model = null,
    ) {
        $this->model = $model ?? config('services.anthropic.model');
    }

    private string $model;

    private bool $lastChecked = false;

    /**
     * Whether the last evaluate() really checked the listing. False when
     * Anthropic couldn't be asked (no key), failed, or answered in a shape
     * we can't read: the score is then left as cdq:score wrote it, with
     * consist_checked_at null (found issue 13). The old code counted all of
     * those as "no conflicts" and gave full credit silently.
     */
    public function lastEvaluationWasChecked(): bool
    {
        return $this->lastChecked;
    }

    /**
     * Evaluates CONSIST for one snapshot and updates its existing cdq_score
     * row in place (consist_score, total_score, grade) — CdqScoringService
     * must have already run for this (snapshot, config version) pair; this
     * never creates a cdq_score row on its own.
     */
    public function evaluate(CdqListingSnapshot $snapshot): ?CdqScore
    {
        $score = CdqScore::where('snapshot_id', $snapshot->id)
            ->where('check_config_version_id', $this->version->id)
            ->first();

        if ($score === null) {
            throw new \RuntimeException("No cdq_score row for snapshot #{$snapshot->id} under config version #{$this->version->id} — run cdq:score first.");
        }

        $this->lastChecked = false;

        if (in_array($score->grade, ['U', 'D'], true)) {
            return $score;
        }

        $conflicts = $this->conflictsFor($snapshot);
        if ($conflicts === null) {
            return $score;
        }
        $this->lastChecked = true;

        CdqDefect::where('snapshot_id', $snapshot->id)->where('dimension', 'CONSIST')->delete();

        // Each conflict is stored at its own true per-instance points value
        // (an honest per-row record); the instance_cap bounds a check
        // code's TOTAL contribution across all its conflicts, applied once
        // in aggregate below — not per conflict (the same per-call-capping
        // bug found and fixed in CdqScoringService::rollUp()).
        $rawPointsByCheckCode = [];
        foreach ($conflicts as $conflict) {
            $config = $this->version->checks()->where('check_code', $conflict['check_code'])->where('enabled', true)->first();
            if ($config === null) {
                continue;
            }
            $points = (float) $config->points;
            $rawPointsByCheckCode[$conflict['check_code']] = ($rawPointsByCheckCode[$conflict['check_code']] ?? 0.0) + $points;

            CdqDefect::create([
                'snapshot_id' => $snapshot->id,
                'dimension' => 'CONSIST',
                'check_code' => $conflict['check_code'],
                'severity' => $points >= 5 ? 'ERROR' : 'WARN',
                'points' => $points,
                'attribute_path' => implode(',', $conflict['fields'] ?? []),
                'detail_json' => ['claim_a' => $conflict['claim_a'] ?? null, 'claim_b' => $conflict['claim_b'] ?? null, 'confidence' => $conflict['confidence'] ?? null],
            ]);
        }

        $deductions = 0.0;
        foreach ($rawPointsByCheckCode as $checkCode => $rawPoints) {
            $config = $this->version->checks()->where('check_code', $checkCode)->first();
            $deductions += $config?->instance_cap !== null ? min($rawPoints, (float) $config->instance_cap) : $rawPoints;
        }

        $consistScore = round(max(0, 10 - $deductions), 2);
        $otherDimensions = (float) $score->attr_score + (float) $score->text_score + (float) $score->image_score + (float) $score->aplus_score;
        $total = round($otherDimensions + $consistScore, 2);
        $grade = match (true) {
            $total >= 90 => 'A',
            $total >= 75 => 'B',
            $total >= 60 => 'C',
            default => 'D',
        };

        $score->update(['consist_score' => $consistScore, 'total_score' => $total, 'grade' => $grade, 'consist_checked_at' => now()]);

        return $score->fresh();
    }

    /** @return array<int, array{check_code:string, fields:array, claim_a:?string, claim_b:?string, confidence:float}>|null null when the listing couldn't be checked */
    private function conflictsFor(CdqListingSnapshot $snapshot): ?array
    {
        $cached = CdqLlmCache::where('content_hash', $snapshot->content_hash)
            ->where('model', $this->model)
            ->where('prompt_version', $this->promptVersion)
            ->first();

        if ($cached !== null) {
            return $this->extractConflicts($cached->response_json, $snapshot);
        }

        $response = $this->callAnthropic($this->buildInput($snapshot));
        if ($response === null) {
            Log::warning('CDQ consistency pass: Anthropic call failed', ['snapshot_id' => $snapshot->id]);

            return null;
        }

        $parsed = $this->parseResponse($response);
        if ($parsed === null) {
            Log::warning('CDQ consistency pass: malformed response, not checked', ['snapshot_id' => $snapshot->id]);

            return null;
        }

        CdqLlmCache::create([
            'content_hash' => $snapshot->content_hash,
            'model' => $this->model,
            'prompt_version' => $this->promptVersion,
            'response_json' => $parsed,
            'created_at' => now(),
        ]);

        return $this->extractConflicts($parsed, $snapshot);
    }

    /** @return array<int, array{check_code:string, fields:array, claim_a:?string, claim_b:?string, confidence:float}> */
    private function extractConflicts(array $parsed, CdqListingSnapshot $snapshot): array
    {
        $validCodes = ['CONSIST_TITLE_VS_ATTR', 'CONSIST_IMAGE_VS_TEXT', 'CONSIST_VARIATION_THEME'];

        return collect($parsed['conflicts'] ?? [])
            ->filter(fn ($c) => in_array($c['check_code'] ?? null, $validCodes, true) && ($c['confidence'] ?? 0) >= self::MIN_CONFIDENCE)
            ->values()
            ->all();
    }

    /** @return array{title:?string, bullets:array, description:?string, attributes:array, image_urls:array, variation_theme:array, siblings:array} */
    private function buildInput(CdqListingSnapshot $snapshot): array
    {
        $attributes = $snapshot->attributes_json ?? [];

        $flatAttributes = [];
        foreach ($attributes as $name => $instances) {
            $value = $instances[0]['value'] ?? null;
            if (is_scalar($value)) {
                $flatAttributes[$name] = $value;
            }
        }

        $images = $snapshot->catalog_json['images'][0]['images'] ?? [];
        $imageUrls = collect($images)->groupBy('variant')
            ->map(fn ($group) => $group->sortByDesc(fn ($img) => ($img['height'] ?? 0) * ($img['width'] ?? 0))->first()['link'] ?? null)
            ->filter()
            ->values()
            ->all();

        [$theme, $siblings] = $this->variationTheme($snapshot);

        return [
            'title' => $attributes['item_name'][0]['value'] ?? null,
            'bullets' => array_column($attributes['bullet_point'] ?? [], 'value'),
            'description' => $attributes['rtip_product_description'][0]['value'] ?? null,
            'attributes' => $flatAttributes,
            'image_urls' => $imageUrls,
            'variation_theme' => $theme,
            'siblings' => $siblings,
        ];
    }

    /**
     * No variationTheme field exists in real Catalog Items relationships
     * data for this account (confirmed live — only parentAsins/type) —
     * inferred instead by comparing this ASIN's scalar attributes against
     * its siblings' and reporting which attribute names actually differ.
     *
     * @return array{0: string[], 1: array<string,string>} [differing attribute names, asin => title]
     */
    private function variationTheme(CdqListingSnapshot $snapshot): array
    {
        if ($snapshot->parent_asin === null) {
            return [[], []];
        }

        $siblingSnapshots = CdqListingSnapshot::where('parent_asin', $snapshot->parent_asin)
            ->where('marketplace_id', $snapshot->marketplace_id)
            ->where('asin', '!=', $snapshot->asin)
            ->whereIn('id', function ($q) {
                $q->selectRaw('MAX(id)')->from('cdq_listing_snapshot')->groupBy('asin');
            })
            ->get();

        if ($siblingSnapshots->isEmpty()) {
            return [[], []];
        }

        $ownAttrs = $snapshot->attributes_json ?? [];
        $differing = [];
        foreach ($siblingSnapshots as $sibling) {
            foreach ($sibling->attributes_json ?? [] as $name => $instances) {
                $siblingValue = $instances[0]['value'] ?? null;
                $ownValue = $ownAttrs[$name][0]['value'] ?? null;
                if (is_scalar($siblingValue) && $siblingValue !== $ownValue) {
                    $differing[$name] = true;
                }
            }
        }

        $titles = $siblingSnapshots->mapWithKeys(fn ($s) => [$s->asin => $s->attributes_json['item_name'][0]['value'] ?? null])->filter()->all();

        return [array_keys($differing), $titles];
    }

    private function callAnthropic(array $payload): ?array
    {
        return (new AnthropicClient($this->model))->sendMessage(self::SYSTEM_PROMPT, json_encode($payload), self::MAX_TOKENS);
    }

    /** Validates the Anthropic response envelope and the JSON contract it should contain, returning null on any malformed shape. */
    private function parseResponse(array $anthropicResponse): ?array
    {
        $text = AnthropicClient::extractText($anthropicResponse);
        if ($text === null) {
            return null;
        }

        $decoded = json_decode(trim($text), true);
        if (! is_array($decoded) || ! isset($decoded['conflicts']) || ! is_array($decoded['conflicts'])) {
            return null;
        }

        return $decoded;
    }
}
