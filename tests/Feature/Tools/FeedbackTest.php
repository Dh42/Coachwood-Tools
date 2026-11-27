<?php

namespace Tests\Feature\Tools;

use App\Models\Product;
use App\Models\Tool;
use App\Models\User;
use App\Services\Amazon\SpApiClient;
use App\Services\Feedback\FeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected Tool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
        $this->tool = Tool::where('slug', 'us-product-reviews')->firstOrFail();
    }

    protected function userWithAccess(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $user->givePermissionTo($this->tool->permissionName());

        return $user;
    }

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
                    'reviewSnippets' => ['Tastes off to me.'],
                    'subtopics' => [[
                        'subtopic' => 'Chalky texture',
                        'metrics' => ['numberOfMentions' => 15, 'occurrencePercentage' => 7.5],
                        'reviewSnippets' => ['Too chalky.'],
                    ]],
                ]],
                'positiveTopics' => [[
                    'topic' => 'Value',
                    'asinMetrics' => ['numberOfMentions' => 80, 'occurrencePercentage' => 40.0, 'starRatingImpact' => 0.5],
                    'reviewSnippets' => [],
                    'subtopics' => [],
                ]],
            ],
        ];
    }

    public function test_users_without_the_permission_are_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('tools.us-product-reviews.index'))->assertForbidden();
    }

    public function test_shows_empty_state_message_with_no_data(): void
    {
        $response = $this->actingAs($this->userWithAccess())->get(route('tools.us-product-reviews.index'));

        $response->assertOk()->assertSee('No feedback data yet');
    }

    public function test_summary_list_renders_with_real_data(): void
    {
        Product::factory()->create(['asin' => 'B1', 'title' => 'Widget']);
        $this->service()->ingestResponse('B1', 'vc', ['status' => 200, 'body' => $this->sampleBody()], '2026-08-01');

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.us-product-reviews.index'));

        $response->assertOk()->assertSee('Widget')->assertSee('Taste');
    }

    public function test_detail_view_renders_the_topic_tree_for_a_selected_asin(): void
    {
        $this->service()->ingestResponse('B1', 'both', ['status' => 200, 'body' => $this->sampleBody()], '2026-08-01');

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.us-product-reviews.index', ['asin' => 'B1']));

        $response->assertOk()->assertSee('Chalky texture')->assertSee('Too chalky.');
    }

    public function test_detail_view_shows_the_no_content_message_for_a_204_response(): void
    {
        $this->service()->ingestResponse('B2', 'sc', ['status' => 204, 'body' => null], '2026-08-01');

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.us-product-reviews.index', ['asin' => 'B2']));

        $response->assertOk()->assertSee('204')->assertSee('not enough US reviews yet');
    }

    public function test_detail_view_handles_an_asin_never_pulled(): void
    {
        // hasAnyData is true (some other ASIN was pulled), but this specific ASIN never was.
        $this->service()->ingestResponse('B1', 'vc', ['status' => 200, 'body' => $this->sampleBody()], '2026-08-01');

        $response = $this->actingAs($this->userWithAccess())->get(route('tools.us-product-reviews.index', ['asin' => 'B-NEVER']));

        $response->assertOk()->assertSee('No data pulled yet');
    }
}
