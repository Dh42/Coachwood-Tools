<?php

namespace Tests\Unit\Services\Feedback;

use App\Models\FeedbackRawSnapshot;
use App\Models\FeedbackSnippet;
use App\Models\FeedbackSubtopic;
use App\Models\FeedbackTopic;
use App\Models\Product;
use App\Models\ScProduct;
use App\Services\Amazon\SpApiClient;
use App\Services\Feedback\FeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedbackServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): FeedbackService
    {
        return new FeedbackService(new SpApiClient);
    }

    private function sampleBody(): array
    {
        return [
            'countryCode' => 'US',
            'dateRange' => ['startDate' => '2026-07-01T00:00:00Z', 'endDate' => '2026-07-31T00:00:00Z'],
            'topics' => [
                'negativeTopics' => [[
                    'topic' => 'Taste',
                    'asinMetrics' => ['numberOfMentions' => 40, 'occurrencePercentage' => 20.0, 'starRatingImpact' => -0.8],
                    'browseNodeMetrics' => ['occurrencePercentage' => ['allProducts' => 12.0], 'starRatingImpact' => ['allProducts' => -0.3]],
                    'parentAsinMetrics' => ['numberOfMentions' => 60, 'occurrencePercentage' => 18.0, 'starRatingImpact' => -0.7],
                    'childAsinMetrics' => [
                        'mostMentions' => ['asin' => 'B0CHILD1', 'numberOfMentions' => 25],
                        'leastMentions' => ['asin' => 'B0CHILD2', 'numberOfMentions' => 2],
                    ],
                    'reviewSnippets' => ['Tastes off to me.'],
                    'subtopics' => [[
                        'subtopic' => 'Chalky texture',
                        'metrics' => ['numberOfMentions' => 15, 'occurrencePercentage' => 7.5],
                        'reviewSnippets' => ['Too chalky.', 'Gritty aftertaste.'],
                    ]],
                ]],
                'positiveTopics' => [[
                    'topic' => 'Value',
                    'asinMetrics' => ['numberOfMentions' => 80, 'occurrencePercentage' => 40.0, 'starRatingImpact' => 0.5],
                    'browseNodeMetrics' => ['occurrencePercentage' => ['allProducts' => 30.0], 'starRatingImpact' => ['allProducts' => 0.4]],
                    'reviewSnippets' => [],
                    'subtopics' => [],
                ]],
            ],
        ];
    }

    public function test_get_combined_catalog_tags_vc_sc_and_both(): void
    {
        Product::factory()->create(['asin' => 'B-VC-ONLY']);
        ScProduct::create(['asin' => 'B-SC-ONLY', 'sku' => 'SKU-SC-ONLY', 'title' => 'SC Only', 'synced_at' => now()]);
        Product::factory()->create(['asin' => 'B-BOTH']);
        ScProduct::create(['asin' => 'B-BOTH', 'sku' => 'SKU-BOTH', 'title' => 'Both', 'synced_at' => now()]);

        $catalog = collect($this->service()->getCombinedCatalog())->keyBy('asin');

        $this->assertSame('vc', $catalog['B-VC-ONLY']['source_catalog']);
        $this->assertSame('sc', $catalog['B-SC-ONLY']['source_catalog']);
        $this->assertSame('both', $catalog['B-BOTH']['source_catalog']);
    }

    public function test_implied_review_count_computes_from_mentions_and_occurrence_pct(): void
    {
        // 40 mentions at 20% occurrence -> ~200 reviews behind that topic.
        $this->assertSame(200, $this->service()->impliedReviewCount(40, 20.0));
    }

    public function test_implied_review_count_returns_null_without_both_inputs(): void
    {
        $service = $this->service();
        $this->assertNull($service->impliedReviewCount(null, 20.0));
        $this->assertNull($service->impliedReviewCount(40, null));
        $this->assertNull($service->impliedReviewCount(40, 0.0));
    }

    public function test_ingest_response_parses_topics_subtopics_and_snippets_on_a_200(): void
    {
        $this->service()->ingestResponse('B1', 'both', ['status' => 200, 'body' => $this->sampleBody()], '2026-08-01');

        $raw = FeedbackRawSnapshot::where('asin', 'B1')->first();
        $this->assertSame(200, $raw->http_status);
        $this->assertSame('US', $raw->country_code);
        $this->assertNotNull($raw->raw_response_gz);

        $negative = FeedbackTopic::where('raw_snapshot_id', $raw->id)->where('polarity', 'negative')->first();
        $this->assertSame('Taste', $negative->topic);
        $this->assertSame(40, $negative->number_of_mentions);
        $this->assertSame('B0CHILD1', $negative->child_most_mentions_asin);

        $subtopic = FeedbackSubtopic::where('topic_id', $negative->id)->first();
        $this->assertSame('Chalky texture', $subtopic->subtopic);

        $this->assertSame(1, FeedbackSnippet::where('topic_id', $negative->id)->whereNull('subtopic_id')->count());
        $this->assertSame(2, FeedbackSnippet::where('subtopic_id', $subtopic->id)->count());

        $this->assertSame(1, FeedbackTopic::where('raw_snapshot_id', $raw->id)->where('polarity', 'positive')->count());
    }

    public function test_ingest_response_records_a_non_200_outcome_without_parsing_anything(): void
    {
        $this->service()->ingestResponse('B2', 'sc', ['status' => 204, 'body' => null], '2026-08-01');

        $raw = FeedbackRawSnapshot::where('asin', 'B2')->first();
        $this->assertSame(204, $raw->http_status);
        $this->assertNull($raw->raw_response_gz);
        $this->assertSame(0, FeedbackTopic::where('raw_snapshot_id', $raw->id)->count());
    }

    public function test_reparse_raw_snapshot_replaces_rather_than_duplicates_parsed_rows(): void
    {
        $service = $this->service();
        $service->ingestResponse('B1', 'vc', ['status' => 200, 'body' => $this->sampleBody()], '2026-08-01');
        $raw = FeedbackRawSnapshot::where('asin', 'B1')->first();
        $this->assertSame(2, FeedbackTopic::where('raw_snapshot_id', $raw->id)->count());

        $service->reparseRawSnapshot($raw->fresh());

        $this->assertSame(2, FeedbackTopic::where('raw_snapshot_id', $raw->id)->count()); // replaced, not doubled
    }

    public function test_get_latest_summary_uses_only_the_latest_snapshot_per_asin_and_finds_the_top_topic(): void
    {
        Product::factory()->create(['asin' => 'B1', 'title' => 'Widget']);

        // Older snapshot for B1 — must be ignored in favor of the newer one below.
        $service = $this->service();
        $service->ingestResponse('B1', 'vc', ['status' => 200, 'body' => $this->sampleBody()], '2026-07-01');
        FeedbackRawSnapshot::where('asin', 'B1')->update(['pulled_at' => now()->subWeek()]);

        // Newer snapshot, different top negative topic (higher occurrence).
        $newerBody = $this->sampleBody();
        $newerBody['topics']['negativeTopics'][0]['topic'] = 'Packaging';
        $newerBody['topics']['negativeTopics'][0]['asinMetrics']['occurrencePercentage'] = 55.0;
        $service->ingestResponse('B1', 'vc', ['status' => 200, 'body' => $newerBody], '2026-08-01');

        $summary = collect($service->getLatestSummary())->keyBy('asin');

        $this->assertSame('Widget', $summary['B1']['title']);
        $this->assertSame('Packaging', $summary['B1']['top_negative_topic']->topic);
        $this->assertSame(1, (int) $summary['B1']['negative_count']);
        $this->assertSame(1, (int) $summary['B1']['positive_count']);
    }

    public function test_get_asin_detail_returns_null_when_never_pulled(): void
    {
        $this->assertNull($this->service()->getAsinDetail('B-NEVER-PULLED'));
    }

    public function test_get_asin_detail_returns_the_full_topic_tree(): void
    {
        $this->service()->ingestResponse('B1', 'both', ['status' => 200, 'body' => $this->sampleBody()], '2026-08-01');

        $detail = $this->service()->getAsinDetail('B1');

        $this->assertNotNull($detail);
        $this->assertSame(200, $detail['raw']->http_status);
        $this->assertCount(2, $detail['topics']);

        $negative = $detail['topics']->firstWhere('topic', 'Taste');
        $this->assertCount(1, $negative->subtopics);
        $this->assertCount(1, $negative->snippets);
        $this->assertCount(2, $negative->subtopics->first()->snippets);
    }
}
