<?php

namespace Tests\Feature\Tools;

use App\Models\FeedbackRawSnapshot;
use App\Models\FeedbackTopic;
use App\Models\ScheduledTask;
use App\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The rebuilt US Product Reviews page and registration, beyond the ported tests. */
class UsProductReviewsPageTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        $user = User::factory()->create()->assignRole('user');
        $user->givePermissionTo('tool.us-product-reviews');

        return $user;
    }

    private function snapshot(string $asin, int $status): FeedbackRawSnapshot
    {
        return FeedbackRawSnapshot::create(['snapshot_date' => '2026-11-22', 'asin' => $asin, 'source_catalog' => 'both', 'http_status' => $status, 'pulled_at' => now()]);
    }

    public function test_the_tool_and_its_weekly_collectors_are_registered(): void
    {
        $this->assertSame('Amazon · Listings', Tool::where('slug', 'us-product-reviews')->sole()->group);
        $this->assertSame('0 1 * * 0', ScheduledTask::where('command', 'feedback:collect')->sole()->cron_expression);
        $this->assertSame('0 2 * * 0', ScheduledTask::where('command', 'feedback:collect-trends')->sole()->cron_expression);
    }

    public function test_the_summary_labels_catalog_and_pull_status_in_words_and_offers_the_tutorial(): void
    {
        $this->snapshot('B0GOOD', 200);
        $this->snapshot('B0EMPTY', 204);
        $this->snapshot('B0DENIED', 403);

        $this->actingAs($this->viewer())->get(route('tools.us-product-reviews.index'))
            ->assertSee('Has data')
            ->assertSee('No content')
            ->assertSee('HTTP 403')
            ->assertSee('Both')
            ->assertSee('data-tour="feedback-tour-btn-steps"', false)
            ->assertSee('Scope — read this first');
    }

    public function test_each_topic_says_whether_it_is_positive_or_negative(): void
    {
        $raw = $this->snapshot('B0TOPIC', 200);
        foreach (['negative' => 'Made-up texture', 'positive' => 'Made-up taste'] as $polarity => $topic) {
            FeedbackTopic::create(['raw_snapshot_id' => $raw->id, 'snapshot_date' => '2026-11-22', 'asin' => 'B0TOPIC', 'polarity' => $polarity, 'topic' => $topic, 'number_of_mentions' => 4, 'occurrence_percentage' => 10]);
        }

        $this->actingAs($this->viewer())->get(route('tools.us-product-reviews.index', ['asin' => 'B0TOPIC']))
            ->assertSeeInOrder(['Made-up taste', 'Positive'])
            ->assertSeeInOrder(['Made-up texture', 'Negative'])
            ->assertSee('~40 reviews');
    }
}
