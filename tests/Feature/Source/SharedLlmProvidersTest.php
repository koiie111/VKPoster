<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Ai\AiWorkspaceSettings;
use App\Domain\Content\Processing\TextSettings;
use App\Domain\Content\Processing\ContentProcessor;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Content\Processing\TextProcessor;
use App\Domain\Content\Operations\ProviderQuota;
use App\Domain\Source\Selection\SelectionRules;
use App\Domain\Source\Selection\SelectionService;
use App\Domain\Source\SourceService;
use App\Integrations\Ai\Llm\LlmChain;
use App\Integrations\Ai\Llm\LlmSettings;
use App\Integrations\Ai\SharedLlmTextProvider;
use App\Integrations\Selection\SemanticSelectionInput;
use App\Integrations\Selection\SharedLlmSemanticSelectionProvider;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Kernel\Config;
use App\Tests\Support\MockHttpClient;
use App\Tests\Support\SourceSelectionTestCase;
use App\Tests\Support\TestEnv;

/** Shared source adapters use the editor's encrypted provider registry and workspace BYOK. */
final class SharedLlmProvidersTest extends SourceSelectionTestCase
{
    private MockHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new MockHttpClient();
        $this->app->container()->instance(HttpClientInterface::class, $this->http);
        $this->db->execute('DELETE FROM ai_chain_steps');
        $this->db->execute('DELETE FROM ai_providers');
    }

    protected function tearDown(): void
    {
        $this->db->execute('DELETE FROM ai_chain_steps');
        $this->db->execute('DELETE FROM ai_providers');
        parent::tearDown();
    }

    public function testOwnKeyIsIsolatedAndOperationMappingUsesSharedChain(): void
    {
        [, $ownWorkspace] = $this->ownerWithWorkspace();
        [, $serviceWorkspace] = $this->ownerWithWorkspace('source-llm-second@example.com');
        $c = $this->app->container();
        self::assertSame([], $c->get(LlmSettings::class)->save('openai', ['enabled' => '1', 'default' => '1', 'api_key' => 'sk-service-secret'], null));
        self::assertSame([], $c->get(AiWorkspaceSettings::class)->saveOwn($ownWorkspace->id, ['enabled' => '1', 'base_url' => 'https://own.example.org/v1', 'model' => 'own-model', 'api_key' => 'sk-own-secret', 'auth' => 'api-key']));
        self::assertSame([], $c->get(LlmChain::class)->save(null, ['provider' => 'openai', 'model' => '', 'actions' => ['source_text'], 'enabled' => '1', 'charge_credits' => '1'], null));

        $this->http->expect('POST', 'https://own.example.org/v1/chat/completions', 200, json_encode(['choices' => [['message' => ['content' => 'Свой текст']]]], JSON_THROW_ON_ERROR));
        $this->http->expect('POST', 'https://api.openai.com/v1/chat/completions', 200, json_encode(['choices' => [['message' => ['content' => 'Общий текст']]]], JSON_THROW_ON_ERROR));
        $provider = $c->get(SharedLlmTextProvider::class);
        $settings = TextSettings::fromInput(['mode' => 'rewrite']);
        self::assertTrue($provider->usesOwnKey($ownWorkspace->id));
        self::assertFalse($provider->usesOwnKey($serviceWorkspace->id));
        self::assertSame('Свой текст', $provider->generateForWorkspace($ownWorkspace->id, 'Вход', $settings));
        self::assertSame('Общий текст', $provider->generateForWorkspace($serviceWorkspace->id, 'Вход', $settings));
        self::assertSame('Api-Key sk-own-secret', $this->http->requests[0]['options']['headers']['Authorization']);
        self::assertSame('Bearer sk-service-secret', $this->http->requests[1]['options']['headers']['Authorization']);
        $this->http->assertAllConsumed();
    }

    public function testWorkspaceHttpProcessingUsesConfiguredSharedProvider(): void
    {
        $this->app->container()->instance(Config::class, TestEnv::config(['CONTENT_TEXT_PROVIDER' => 'llm']));
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $c = $this->app->container();
        self::assertSame([], $c->get(LlmSettings::class)->save('openai', ['enabled' => '1', 'default' => '1', 'api_key' => 'sk-service-secret'], null));
        $source = $c->get(SourceService::class)->create($ctx, 'News', 'telegram', '@shared_llm_http', true);
        $this->ingest($source, [$this->message(10, 'Исходный текст')]);
        $item = $this->db->table('source_items')->first() ?? throw new \LogicException('Missing material');
        $c->get(SelectionService::class)->decide($ctx, $source, (string) $item['public_id'], true);
        $revision = MaterialRepository::revision($item, $c->get(MaterialRepository::class)->messages($ctx, $source, (int) $item['id']));
        $this->http->expect('POST', 'https://api.openai.com/v1/chat/completions', 200, json_encode(['choices' => [['message' => ['content' => 'Обработанный текст']]]], JSON_THROW_ON_ERROR));
        $this->actAs($owner);
        $url = $this->base($workspace) . '/sources/' . $source->publicId . '/items/' . $item['public_id'];
        self::assertSame($url, $this->post($url . '/process', ['revision' => $revision, 'mode' => 'rewrite'])->header('Location'));
        self::assertStringContainsString('Обработанный текст', $this->get($url)->body);
        self::assertSame('completed', $this->db->table('source_text_processings')->first()['status'] ?? null);
        $this->http->assertAllConsumed();
    }

    public function testSemanticJsonIsStrictAndUsesSharedDefault(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $c = $this->app->container();
        self::assertSame([], $c->get(LlmSettings::class)->save('openai', ['enabled' => '1', 'default' => '1', 'api_key' => 'sk-service-secret'], null));
        $this->http->expect('POST', 'https://api.openai.com/v1/chat/completions', 200, json_encode(['choices' => [['message' => ['content' => json_encode(['decision' => 'approved', 'score' => 84, 'confidence' => 0.92, 'reason' => 'Подходит', 'matched_criteria' => ['тема'], 'review_flags' => []], JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR));
        $result = $c->get(SharedLlmSemanticSelectionProvider::class)->evaluateForWorkspace($workspace->id, new SemanticSelectionInput('Новость', null, [], 'тема', hash('sha256', 'revision')));
        self::assertSame('approved', $result->decision);
        self::assertSame(84, $result->score);
        $this->http->assertAllConsumed();
    }

    public function testMissingModelDoesNotChargeBeforeTextAttempt(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $c = $this->app->container();
        $source = $c->get(SourceService::class)->create($ctx, 'News', 'telegram', '@shared_llm_news', true);
        $c->get(SelectionService::class)->saveRules($ctx, $source, SelectionRules::fromInput([]));
        $this->ingest($source, [$this->message(10, 'News')]);
        $item = $c->get(MaterialRepository::class)->item($ctx, $source, (string) ($this->db->table('source_items')->first()['public_id'] ?? ''));
        $revision = MaterialRepository::revision($item, $c->get(MaterialRepository::class)->messages($ctx, $source, (int) $item['id']));
        $processor = new ContentProcessor($this->db, $this->clock, $c->get(MaterialRepository::class), new TextProcessor($c->get(SharedLlmTextProvider::class)), $c->get(\App\Domain\Audit\AuditLog::class), new ProviderQuota($this->db, $c->get(\App\Domain\Billing\Entitlements::class), TestEnv::config(['CONTENT_TEXT_PROVIDER' => 'llm']), $this->clock, $c->get(\Redis::class)));
        self::assertSame('failed', $processor->process($ctx, $source, (string) $item['public_id'], $revision, TextSettings::fromInput(['mode' => 'rewrite'])));
        self::assertSame(0, (int) ($this->db->select("SELECT COALESCE(SUM(value),0) AS n FROM usage_counters WHERE workspace_id=? AND metric='ai_credits'", [$workspace->id])[0]['n'] ?? -1));
        self::assertSame([], $this->http->requests);
    }

    public function testFreeChainStepReturnsReservedCredit(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $c = $this->app->container();
        self::assertSame([], $c->get(LlmSettings::class)->save('openai', ['enabled' => '1', 'default' => '1', 'api_key' => 'sk-service-secret'], null));
        self::assertSame([], $c->get(LlmChain::class)->save(null, ['provider' => 'openai', 'actions' => ['source_text'], 'enabled' => '1', 'charge_credits' => '0'], null));
        $this->http->expect('POST', 'https://api.openai.com/v1/chat/completions', 200, json_encode(['choices' => [['message' => ['content' => 'Готово']]]], JSON_THROW_ON_ERROR));
        $provider = $c->get(SharedLlmTextProvider::class);
        $quota = new ProviderQuota($this->db, $c->get(\App\Domain\Billing\Entitlements::class), TestEnv::config(['CONTENT_TEXT_PROVIDER' => 'llm']), $this->clock, $c->get(\Redis::class));
        $answer = $quota->run($workspace->id, 'text', fn (): string => $provider->generateForWorkspace($workspace->id, 'Текст', TextSettings::fromInput(['mode' => 'rewrite'])), fn (): bool => ($provider->metadata()['charge_credits'] ?? true) === false);
        self::assertSame('Готово', $answer);
        self::assertSame(0, $quota->usage($workspace->id)['used']);
        $this->http->assertAllConsumed();
    }
}
