<?php

namespace App\Services\Feedback;

use App\Models\FeedbackRawSnapshot;
use App\Models\FeedbackTopic;
use App\Models\FeedbackTopicTrend;
use App\Models\FeedbackTrendRawSnapshot;
use App\Models\Product;
use App\Models\ScProduct;
use App\Services\Amazon\SpApiClient;
use Illuminate\Support\Facades\DB;

/**
 * US Product Reviews — Amazon's own review-topic classification (Customer
 * Feedback API), for every BioSteel ASIN across both the VC (.ca) and SC
 * (US) catalogs, queried against the US marketplace — the only one this
 * API supports that we actually operate in. Data Kiosk's own
 * customerSatisfaction domain (the originally-proposed source) is
 * structurally CA-blocked at the marketplace level — confirmed live,
 * Amazon.ca isn't in this API family's own marketplaceId enum at all.
 *
 * Confirmed live: this data is scoped by ASIN + marketplace, NOT by which
 * of our two SP-API accounts asks — the exact same response comes back for
 * the same ASIN under both tokens, including for ASINs that only exist in
 * one of our two catalogs. This is brand-level Amazon data, not
 * account-scoped — SpApiClient's Customer Feedback methods always use the
 * seller-scoped token regardless.
 *
 * Ported function-for-function from the legacy feedback_lib.php.
 */
class FeedbackService
{
    /**
     * $connection is for `compare:us-product-reviews`, which runs
     * getLatestSummary() against the old database too; null is this app's.
     */
    public function __construct(private SpApiClient $client, private ?string $connection = null) {}

    /**
     * Every distinct ASIN across both our catalogs, tagged with which
     * one(s) it belongs to. This tag is a property of our own business
     * (which catalog lists it right now), not of Amazon's review data —
     * confirmed live the review data itself is identical either way.
     */
    public function getCombinedCatalog(): array
    {
        $vc = Product::query()->pluck('asin')->all();
        $sc = ScProduct::query()->pluck('asin')->all();
        $vcSet = array_flip($vc);
        $scSet = array_flip($sc);
        $all = array_unique(array_merge($vc, $sc));

        $out = [];
        foreach ($all as $asin) {
            $inVc = isset($vcSet[$asin]);
            $inSc = isset($scSet[$asin]);
            $out[] = ['asin' => $asin, 'source_catalog' => $inVc && $inSc ? 'both' : ($inVc ? 'vc' : 'sc')];
        }

        return $out;
    }

    /**
     * Stores one pull's result. Always writes a raw_snapshots row (whatever
     * the HTTP outcome — 204/403/404 are meaningful data, not just
     * failures to discard). Only parses topics/subtopics/snippets on a
     * real 200.
     */
    public function ingestResponse(string $asin, string $sourceCatalog, array $apiResult, string $snapshotDate): void
    {
        $status = $apiResult['status'];
        $body = $apiResult['body'];

        $raw = FeedbackRawSnapshot::create([
            'snapshot_date' => $snapshotDate, 'asin' => $asin, 'source_catalog' => $sourceCatalog, 'http_status' => $status,
            'country_code' => $body['countryCode'] ?? null,
            'data_start_date' => isset($body['dateRange']['startDate']) ? substr($body['dateRange']['startDate'], 0, 10) : null,
            'data_end_date' => isset($body['dateRange']['endDate']) ? substr($body['dateRange']['endDate'], 0, 10) : null,
            'raw_response_gz' => $body !== null ? gzcompress(json_encode($body, JSON_UNESCAPED_SLASHES), 9) : null,
            'pulled_at' => now(),
        ]);

        if ($status !== 200 || $body === null) {
            return;
        }

        $this->parseTopicsIntoDb($raw->id, $snapshotDate, $asin, $body);
    }

    /**
     * The actual topic/subtopic/snippet parsing, split out from
     * ingestResponse() so it can also be re-run against an already-stored
     * raw response (reparseRawSnapshot()) without a new API call — e.g.
     * when a new field is added to what's extracted.
     */
    public function parseTopicsIntoDb(int $rawSnapshotId, string $snapshotDate, string $asin, array $body): void
    {
        foreach (['positive' => 'positiveTopics', 'negative' => 'negativeTopics'] as $polarity => $key) {
            foreach ($body['topics'][$key] ?? [] as $t) {
                $topic = FeedbackTopic::create([
                    'raw_snapshot_id' => $rawSnapshotId, 'snapshot_date' => $snapshotDate, 'asin' => $asin,
                    'polarity' => $polarity, 'topic' => $t['topic'] ?? 'Unknown',
                    'number_of_mentions' => $t['asinMetrics']['numberOfMentions'] ?? null,
                    'occurrence_percentage' => $t['asinMetrics']['occurrencePercentage'] ?? null,
                    'star_rating_impact' => $t['asinMetrics']['starRatingImpact'] ?? null,
                    'browse_node_occurrence_pct' => $t['browseNodeMetrics']['occurrencePercentage']['allProducts'] ?? null,
                    'browse_node_star_impact' => $t['browseNodeMetrics']['starRatingImpact']['allProducts'] ?? null,
                    'parent_asin_mentions' => $t['parentAsinMetrics']['numberOfMentions'] ?? null,
                    'parent_asin_occurrence_pct' => $t['parentAsinMetrics']['occurrencePercentage'] ?? null,
                    'parent_asin_star_impact' => $t['parentAsinMetrics']['starRatingImpact'] ?? null,
                    'child_most_mentions_asin' => $t['childAsinMetrics']['mostMentions']['asin'] ?? null,
                    'child_most_mentions_count' => $t['childAsinMetrics']['mostMentions']['numberOfMentions'] ?? null,
                    'child_least_mentions_asin' => $t['childAsinMetrics']['leastMentions']['asin'] ?? null,
                    'child_least_mentions_count' => $t['childAsinMetrics']['leastMentions']['numberOfMentions'] ?? null,
                ]);

                // Topic-level snippets (not tied to a specific subtopic).
                foreach ($t['reviewSnippets'] ?? [] as $snippet) {
                    DB::table('feedback_snippets')->insert(['topic_id' => $topic->id, 'subtopic_id' => null, 'snippet' => substr($snippet, 0, 500)]);
                }

                foreach ($t['subtopics'] ?? [] as $st) {
                    $subtopicId = DB::table('feedback_subtopics')->insertGetId([
                        'topic_id' => $topic->id, 'subtopic' => $st['subtopic'] ?? 'Unknown',
                        'number_of_mentions' => $st['metrics']['numberOfMentions'] ?? null,
                        'occurrence_percentage' => $st['metrics']['occurrencePercentage'] ?? null,
                    ]);
                    foreach ($st['reviewSnippets'] ?? [] as $snippet) {
                        DB::table('feedback_snippets')->insert(['topic_id' => $topic->id, 'subtopic_id' => $subtopicId, 'snippet' => substr($snippet, 0, 500)]);
                    }
                }
            }
        }
    }

    /**
     * Re-parses one already-stored raw response (decompressing
     * raw_response_gz) into feedback_topics/subtopics/snippets — no new
     * API call. Deletes this raw snapshot's existing parsed rows first
     * (cascades to subtopics/snippets via the FK) so re-running is a clean
     * replace, not a duplicate.
     */
    public function reparseRawSnapshot(FeedbackRawSnapshot $raw): void
    {
        if ($raw->http_status != 200 || $raw->raw_response_gz === null) {
            return;
        }
        $body = json_decode(gzuncompress($raw->raw_response_gz), true);
        if ($body === null) {
            return;
        }

        FeedbackTopic::where('raw_snapshot_id', $raw->id)->delete();

        $this->parseTopicsIntoDb($raw->id, $raw->snapshot_date->toDateString(), $raw->asin, $body);
    }

    /**
     * Runs the full catalog through the API, one call at a time with
     * pacing (rate limit not published as a fixed number for this
     * endpoint — 1 request/second is a conservative default matching this
     * project's existing sleep-based-pacing convention). $logger, if
     * given, is called with a one-line status string per ASIN.
     */
    public function runCollector(?callable $logger = null, int $paceSeconds = 1): array
    {
        $catalog = $this->getCombinedCatalog();
        $snapshotDate = now()->toDateString();
        $counts = ['200' => 0, '204' => 0, 'other' => 0, 'error' => 0];

        foreach ($catalog as $i => $entry) {
            $asin = $entry['asin'];
            try {
                $result = $this->client->getCustomerFeedbackTopics($asin);
                $this->ingestResponse($asin, $entry['source_catalog'], $result, $snapshotDate);
                $key = $result['status'] === 200 ? '200' : ($result['status'] === 204 ? '204' : 'other');
                $counts[$key]++;
                if ($logger) {
                    $logger(sprintf('[%d/%d] %s (%s): HTTP %d', $i + 1, count($catalog), $asin, $entry['source_catalog'], $result['status']));
                }
            } catch (\Throwable $e) {
                $counts['error']++;
                if ($logger) {
                    $logger(sprintf('[%d/%d] %s (%s): ERROR %s', $i + 1, count($catalog), $asin, $entry['source_catalog'], $e->getMessage()));
                }
            }
            if ($i < count($catalog) - 1) {
                sleep($paceSeconds);
            }
        }

        return $counts;
    }

    /** Stores one trends pull. Same append-only, records-the-HTTP-outcome discipline as ingestResponse(). */
    public function ingestTrendResponse(string $asin, string $sourceCatalog, array $apiResult, string $snapshotDate): void
    {
        $status = $apiResult['status'];
        $body = $apiResult['body'];

        $raw = FeedbackTrendRawSnapshot::create([
            'snapshot_date' => $snapshotDate, 'asin' => $asin, 'source_catalog' => $sourceCatalog, 'http_status' => $status,
            'raw_response_gz' => $body !== null ? gzcompress(json_encode($body, JSON_UNESCAPED_SLASHES), 9) : null,
            'pulled_at' => now(),
        ]);

        if ($status !== 200 || $body === null) {
            return;
        }

        $this->parseTrendsIntoDb($raw->id, $snapshotDate, $asin, $body);
    }

    /** Parses reviewTrends.positiveTopics/negativeTopics — each topic's up-to-6 monthly points — into feedback_topic_trends. */
    public function parseTrendsIntoDb(int $rawTrendSnapshotId, string $snapshotDate, string $asin, array $body): void
    {
        foreach (['positive' => 'positiveTopics', 'negative' => 'negativeTopics'] as $polarity => $key) {
            foreach ($body['reviewTrends'][$key] ?? [] as $t) {
                $topic = $t['topic'] ?? 'Unknown';
                foreach ($t['trendMetrics'] ?? [] as $point) {
                    FeedbackTopicTrend::create([
                        'raw_trend_snapshot_id' => $rawTrendSnapshotId, 'snapshot_date' => $snapshotDate, 'asin' => $asin,
                        'polarity' => $polarity, 'topic' => $topic,
                        'month_start' => isset($point['dateRange']['startDate']) ? substr($point['dateRange']['startDate'], 0, 10) : null,
                        'month_end' => isset($point['dateRange']['endDate']) ? substr($point['dateRange']['endDate'], 0, 10) : null,
                        'asin_occurrence_pct' => $point['asinMetrics']['occurrencePercentage'] ?? null,
                        'parent_asin_occurrence_pct' => $point['parentAsinMetrics']['occurrencePercentage'] ?? null,
                        'browse_node_occurrence_pct' => $point['browseNodeMetrics']['occurrencePercentage']['allProducts'] ?? null,
                    ]);
                }
            }
        }
    }

    /**
     * Runs the trend pull for the full catalog — a SEPARATE collector from
     * runCollector(), by design: this is a second API call per ASIN
     * (doubles volume vs. the main topics pull), so it's meant to be
     * scheduled independently, not folded into the same cron tick.
     */
    public function runTrendCollector(?callable $logger = null, int $paceSeconds = 1): array
    {
        $catalog = $this->getCombinedCatalog();
        $snapshotDate = now()->toDateString();
        $counts = ['200' => 0, '204' => 0, 'other' => 0, 'error' => 0];

        foreach ($catalog as $i => $entry) {
            $asin = $entry['asin'];
            try {
                $result = $this->client->getCustomerFeedbackTrends($asin);
                $this->ingestTrendResponse($asin, $entry['source_catalog'], $result, $snapshotDate);
                $key = $result['status'] === 200 ? '200' : ($result['status'] === 204 ? '204' : 'other');
                $counts[$key]++;
                if ($logger) {
                    $logger(sprintf('[%d/%d] %s (%s): HTTP %d', $i + 1, count($catalog), $asin, $entry['source_catalog'], $result['status']));
                }
            } catch (\Throwable $e) {
                $counts['error']++;
                if ($logger) {
                    $logger(sprintf('[%d/%d] %s (%s): ERROR %s', $i + 1, count($catalog), $asin, $entry['source_catalog'], $e->getMessage()));
                }
            }
            if ($i < count($catalog) - 1) {
                sleep($paceSeconds);
            }
        }

        return $counts;
    }

    /** Latest trend series per topic for one ASIN, chronological (oldest first). Empty array if never pulled. */
    public function getAsinTrends(string $asin): array
    {
        $raw = FeedbackTrendRawSnapshot::where('asin', $asin)->orderByDesc('pulled_at')->first();
        if (! $raw || $raw->http_status != 200) {
            return [];
        }

        $rows = FeedbackTopicTrend::where('raw_trend_snapshot_id', $raw->id)->orderBy('topic')->orderBy('month_start')->get();

        $byTopic = [];
        foreach ($rows as $r) {
            $key = $r->polarity.'|'.$r->topic;
            $byTopic[$key]['polarity'] = $r->polarity;
            $byTopic[$key]['topic'] = $r->topic;
            $byTopic[$key]['points'][] = $r;
        }

        return array_values($byTopic);
    }

    /**
     * Latest snapshot per ASIN (all in one query, not a per-ASIN loop —
     * same N+1 lesson learned porting Geo Sales Intelligence), joined with
     * topic counts and each ASIN's single highest-occurrence negative/
     * positive topic (the one thing worth surfacing in a list view without
     * opening the detail page). Also joins product titles from both
     * catalogs since either one (or neither, if the ASIN was later
     * delisted) may have the name.
     */
    public function getLatestSummary(): array
    {
        $rows = DB::connection($this->connection)->table('feedback_raw_snapshots as rs')
            ->joinSub(
                DB::connection($this->connection)->table('feedback_raw_snapshots')->selectRaw('asin, MAX(pulled_at) as max_pulled_at')->groupBy('asin'),
                'latest',
                fn ($j) => $j->on('latest.asin', '=', 'rs.asin')->on('latest.max_pulled_at', '=', 'rs.pulled_at')
            )
            ->leftJoinSub(DB::connection($this->connection)->table('products')->selectRaw('asin, MAX(title) as title')->groupBy('asin'), 'p', 'p.asin', '=', 'rs.asin')
            ->leftJoinSub(DB::connection($this->connection)->table('sc_products')->selectRaw('asin, MAX(title) as title')->groupBy('asin'), 'sp', 'sp.asin', '=', 'rs.asin')
            ->selectRaw('rs.id as raw_snapshot_id, rs.asin, rs.source_catalog, rs.http_status, rs.snapshot_date, rs.data_start_date, rs.data_end_date,
                COALESCE(p.title, sp.title) as title,
                (SELECT COUNT(*) FROM feedback_topics t WHERE t.raw_snapshot_id = rs.id AND t.polarity = \'positive\') as positive_count,
                (SELECT COUNT(*) FROM feedback_topics t WHERE t.raw_snapshot_id = rs.id AND t.polarity = \'negative\') as negative_count')
            ->orderByDesc('negative_count')->orderBy('rs.asin')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $topByPolarity = [];
        foreach (['negative', 'positive'] as $polarity) {
            $topByPolarity[$polarity] = DB::connection($this->connection)->table('feedback_topics as t')
                ->joinSub(
                    DB::connection($this->connection)->table('feedback_topics')->selectRaw('raw_snapshot_id, MAX(occurrence_percentage) as max_occ')->where('polarity', $polarity)->groupBy('raw_snapshot_id'),
                    'top',
                    fn ($j) => $j->on('top.raw_snapshot_id', '=', 't.raw_snapshot_id')->on('top.max_occ', '=', 't.occurrence_percentage')
                )
                ->where('t.polarity', $polarity)
                ->select('t.raw_snapshot_id', 't.topic', 't.occurrence_percentage', 't.number_of_mentions')
                ->groupBy('t.raw_snapshot_id', 't.topic', 't.occurrence_percentage', 't.number_of_mentions')
                ->get()->keyBy('raw_snapshot_id');
        }

        return $rows->map(function ($r) use ($topByPolarity) {
            $r = (array) $r;
            $r['top_negative_topic'] = $topByPolarity['negative']->get($r['raw_snapshot_id']);
            $r['top_positive_topic'] = $topByPolarity['positive']->get($r['raw_snapshot_id']);

            return $r;
        })->all();
    }

    /**
     * mentions ÷ occurrence% × 100 — Amazon documents occurrencePercentage
     * as "the percentage of reviews that mention the topic," so this is an
     * approximate count of reviews behind that percentage, not just a
     * mention tally. Null if either input is missing.
     */
    public function impliedReviewCount(?int $mentions, ?float $occurrencePct): ?int
    {
        if ($mentions === null || $occurrencePct === null || $occurrencePct <= 0) {
            return null;
        }

        return (int) round($mentions / ($occurrencePct / 100));
    }

    /** Full topic/subtopic/snippet tree for one ASIN's latest snapshot. Null if never pulled. */
    public function getAsinDetail(string $asin): ?array
    {
        $raw = FeedbackRawSnapshot::where('asin', $asin)->orderByDesc('pulled_at')->first();
        if (! $raw) {
            return null;
        }

        $topics = FeedbackTopic::where('raw_snapshot_id', $raw->id)
            ->orderByDesc('polarity')->orderByDesc('occurrence_percentage')
            ->with(['subtopics' => fn ($q) => $q->orderByDesc('occurrence_percentage'), 'subtopics.snippets', 'snippets'])
            ->get();

        return ['raw' => $raw, 'topics' => $topics];
    }
}
