<?php

namespace Tests\Unit\Services\Anthropic;

use App\Services\Anthropic\AnthropicClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'test-key', 'services.anthropic.base_url' => 'https://api.anthropic.test', 'services.anthropic.model' => 'default-model']);
    }

    public function test_returns_null_when_no_api_key_configured(): void
    {
        config(['services.anthropic.api_key' => null]);
        Http::fake();

        $result = (new AnthropicClient)->sendMessage('system', 'user content');

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_sends_the_configured_model_and_returns_the_decoded_response(): void
    {
        Http::fake(['api.anthropic.test/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => 'hello']]])]);

        $result = (new AnthropicClient)->sendMessage('system prompt', 'user content', 500);

        $this->assertSame('hello', $result['content'][0]['text']);
        Http::assertSent(fn ($request) => $request['model'] === 'default-model' && $request['max_tokens'] === 500 && $request['system'] === 'system prompt');
    }

    public function test_explicit_model_overrides_the_config_default(): void
    {
        Http::fake(['api.anthropic.test/v1/messages' => Http::response(['content' => []])]);

        (new AnthropicClient('explicit-model'))->sendMessage('system', 'user content');

        Http::assertSent(fn ($request) => $request['model'] === 'explicit-model');
    }

    public function test_returns_null_on_a_non_successful_response(): void
    {
        Http::fake(['api.anthropic.test/v1/messages' => Http::response(['error' => 'bad'], 500)]);

        $this->assertNull((new AnthropicClient)->sendMessage('system', 'user content'));
    }

    public function test_extract_text_pulls_the_first_content_blocks_text(): void
    {
        $this->assertSame('hi there', AnthropicClient::extractText(['content' => [['type' => 'text', 'text' => 'hi there']]]));
    }

    public function test_extract_text_returns_null_for_an_unexpected_shape(): void
    {
        $this->assertNull(AnthropicClient::extractText(['content' => []]));
        $this->assertNull(AnthropicClient::extractText([]));
    }
}
