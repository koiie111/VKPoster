<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai;

use App\Integrations\Ai\Llm\AnthropicProvider;
use App\Integrations\Ai\Llm\LlmException;
use App\Integrations\Ai\Llm\LlmRequest;
use App\Integrations\Ai\Llm\LlmTransport;
use App\Integrations\Ai\Llm\OpenAiCompatibleProvider;
use App\Kernel\Exception\SsrfException;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The two wire formats on recorded-style responses: request shape, key placement, token counts and every failure mapping. */
#[CoversClass(OpenAiCompatibleProvider::class)]
#[CoversClass(AnthropicProvider::class)]
#[CoversClass(LlmTransport::class)]
final class LlmProvidersTest extends TestCase
{
    private function openai(MockHttpClient $http, string $auth = 'bearer', string $key = 'sk-secret-key'): OpenAiCompatibleProvider
    {
        return new OpenAiCompatibleProvider('openai', new LlmTransport($http), 'https://api.example.com/v1/', 'gpt-test', $key, $auth);
    }

    public function testOpenAiCompatibleRequestAndAnswer(): void
    {
        $http = (new MockHttpClient())->expect('POST', 'https://api.example.com/v1/chat/completions', 200, json_encode([
            'model' => 'gpt-test-2024', 'choices' => [['message' => ['role' => 'assistant', 'content' => "  Привет, мир!\n"], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 5],
        ], JSON_THROW_ON_ERROR));

        $result = $this->openai($http)->complete(new LlmRequest('system rules', 'user data', 300, 0.5));

        self::assertSame('Привет, мир!', $result->text);
        self::assertSame('gpt-test-2024', $result->model);
        self::assertSame([12, 5], [$result->tokensIn, $result->tokensOut]);
        $sent = $http->requests[0]['options'];
        self::assertSame('Bearer sk-secret-key', $sent['headers']['Authorization']);
        self::assertTrue($sent['user_url'], 'the configured address goes through the SSRF guard');
        self::assertFalse($sent['follow_redirects']);
        self::assertSame('system', $sent['json']['messages'][0]['role']);
        self::assertSame('user data', $sent['json']['messages'][1]['content']);
        self::assertSame(300, $sent['json']['max_tokens']);
        self::assertFalse($sent['json']['stream']);
    }

    public function testApiKeySchemeForYandex(): void
    {
        $http = (new MockHttpClient())->expect('POST', 'https://api.example.com/v1/chat/completions', 200, json_encode(['choices' => [['message' => ['content' => 'ok']]]], JSON_THROW_ON_ERROR));

        $this->openai($http, 'api-key')->complete(new LlmRequest('s', 'u'));

        self::assertSame('Api-Key sk-secret-key', $http->requests[0]['options']['headers']['Authorization']);
    }

    public function testNotConfiguredNeverCallsTheNetwork(): void
    {
        $http = new MockHttpClient();
        foreach ([$this->openai($http, 'bearer', ''), new OpenAiCompatibleProvider('x', new LlmTransport($http), 'ftp://nope', 'm', 'k')] as $provider) {
            try {
                $provider->complete(new LlmRequest('s', 'u'));
                self::fail('expected not_configured');
            } catch (LlmException $e) {
                self::assertSame('not_configured', $e->reason);
            }
        }
        self::assertSame([], $http->requests);
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function failures(): array
    {
        return [
            'bad key' => [401, '{"error":{"message":"Incorrect API key sk-secret-key"}}', 'credentials'],
            'forbidden' => [403, '{}', 'credentials'],
            'limit' => [429, '{}', 'rate_limited'],
            'outage' => [503, '{}', 'unavailable'],
            'bad request' => [400, '{}', 'bad_request'],
            'not json' => [200, 'oops', 'bad_response'],
            'no choices' => [200, '{"choices":[]}', 'bad_response'],
            'empty text' => [200, '{"choices":[{"message":{"content":"  "}}]}', 'bad_response'],
            'filtered' => [200, '{"choices":[{"message":{"content":null},"finish_reason":"content_filter"}]}', 'refused'],
            'list body' => [200, '[1,2]', 'bad_response'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testFailuresBecomeStableReasonsWithoutProviderText(int $status, string $body, string $reason): void
    {
        $http = (new MockHttpClient())->expect('POST', 'https://api.example.com/v1/chat/completions', $status, $body);

        try {
            $this->openai($http)->complete(new LlmRequest('s', 'u'));
            self::fail('expected a failure');
        } catch (LlmException $e) {
            self::assertSame($reason, $e->reason);
            self::assertStringNotContainsString('sk-secret-key', $e->getMessage());
        }
    }

    public function testForbiddenAddressIsReported(): void
    {
        $http = new class () implements \App\Kernel\HttpClient\HttpClientInterface {
            public function request(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
            {
                throw new SsrfException('private address');
            }
        };

        try {
            (new OpenAiCompatibleProvider('own', new LlmTransport($http), 'https://intranet.example/v1', 'm', 'k'))->complete(new LlmRequest('s', 'u'));
            self::fail('expected forbidden_url');
        } catch (LlmException $e) {
            self::assertSame('forbidden_url', $e->reason);
        }
    }

    public function testAnthropicMessagesFormat(): void
    {
        $http = (new MockHttpClient())->expect('POST', 'https://api.anthropic.com/v1/messages', 200, json_encode([
            'model' => 'claude-x', 'content' => [['type' => 'text', 'text' => 'Первая часть. '], ['type' => 'tool_use', 'id' => 'a'], ['type' => 'text', 'text' => 'Вторая.']],
            'usage' => ['input_tokens' => 20, 'output_tokens' => 7],
        ], JSON_THROW_ON_ERROR));

        $result = (new AnthropicProvider('anthropic', new LlmTransport($http), 'https://api.anthropic.com', 'claude-x', 'sk-ant-secret'))->complete(new LlmRequest('rules', 'data', 100, 1.8));

        self::assertSame('Первая часть. Вторая.', $result->text);
        self::assertSame([20, 7], [$result->tokensIn, $result->tokensOut]);
        $sent = $http->requests[0]['options'];
        self::assertSame('sk-ant-secret', $sent['headers']['x-api-key']);
        self::assertArrayNotHasKey('Authorization', $sent['headers']);
        self::assertSame('rules', $sent['json']['system']);
        self::assertSame([['role' => 'user', 'content' => 'data']], $sent['json']['messages']);
        self::assertSame(1.0, $sent['json']['temperature'], 'clamped to the API range');
    }

    public function testAnthropicRefusalAndEmpty(): void
    {
        foreach (['{"content":[],"stop_reason":"refusal"}' => 'refused', '{"content":[]}' => 'bad_response'] as $body => $reason) {
            $http = (new MockHttpClient())->expect('POST', 'https://api.anthropic.com/v1/messages', 200, $body);
            try {
                (new AnthropicProvider('a', new LlmTransport($http), 'https://api.anthropic.com', 'm', 'k'))->complete(new LlmRequest('s', 'u'));
                self::fail('expected ' . $reason);
            } catch (LlmException $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }
}
