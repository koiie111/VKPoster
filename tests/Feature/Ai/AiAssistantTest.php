<?php

declare(strict_types=1);

namespace App\Tests\Feature\Ai;

use App\Domain\Ai\AiAssistant;
use App\Domain\Ai\AiCredits;
use App\Domain\Ai\AiException;
use App\Domain\Ai\AiAction;
use App\Domain\Ai\AiWorkspaceSettings;
use App\Domain\Ai\AiUsageLog;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Ai\AiController;
use App\Integrations\Ai\Llm\LlmSettings;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Tests\Support\BillingTestCase;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The editor's assistant end to end: who may use it, brand voice in the prompt, credits taken only after an answer, own key free of
 * charge, provider errors never cost anything, limits, and that nothing about a post is logged.
 */
#[CoversClass(AiAssistant::class)]
#[CoversClass(AiController::class)]
#[CoversClass(AiCredits::class)]
#[CoversClass(AiUsageLog::class)]
#[CoversClass(AiWorkspaceSettings::class)]
final class AiAssistantTest extends BillingTestCase
{
    private MockHttpClient $llm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llm = new MockHttpClient();
        $this->app->container()->instance(HttpClientInterface::class, $this->llm);
        $this->db->execute('DELETE FROM ai_providers');
    }

    protected function tearDown(): void
    {
        $this->db->execute('DELETE FROM ai_providers');
        parent::tearDown();
    }

    private function connect(): void
    {
        $settings = $this->app->container()->get(LlmSettings::class);
        self::assertSame([], $settings->save('openai', ['enabled' => '1', 'default' => '1', 'api_key' => 'sk-owner-secret'], null));
    }

    private function answer(string $text, int $in = 11, int $out = 7): void
    {
        $this->llm->expect('POST', 'https://api.openai.com/v1/chat/completions', 200, json_encode([
            'model' => 'gpt-4o-mini', 'choices' => [['message' => ['content' => $text]]], 'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{\App\Domain\User\User, \App\Domain\Workspace\Workspace}
     */
    private function workspaceWithCredits(int $monthly): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'pro', ['ai_credits' => $monthly]);

        return [$owner, $workspace];
    }

    private function ask(\App\Domain\User\User $user, \App\Domain\Workspace\Workspace $workspace, string $action = 'rewrite', string $text = 'Привет, мир'): \App\Kernel\Http\Response
    {
        return $this->post($this->base($workspace) . '/ai/run', ['action' => $action, 'text' => $text, 'brief' => '', 'platform' => 'telegram', 'limit' => '500'], ['Accept' => 'application/json']);
    }

    public function testGuestAndNonWritersAreRefused(): void
    {
        [$owner, $workspace] = $this->workspaceWithCredits(10);
        $viewer = $this->memberOf($workspace, 'viewer@example.com', Role::Viewer);
        $author = $this->memberOf($workspace, 'author@example.com', Role::Author);
        $stranger = $this->createUser('stranger@example.com');
        $this->app->container()->get(\App\Domain\Workspace\WorkspaceService::class)->createPersonal($stranger);

        $this->useBrowser();
        self::assertContains($this->ask($owner, $workspace)->status, [302, 401, 403], 'guest');
        $this->actAs($viewer);
        self::assertSame(403, $this->ask($viewer, $workspace)->status);
        $this->actAs($stranger);
        self::assertSame(404, $this->ask($stranger, $workspace)->status, 'foreign workspace');
        $this->connect();
        $this->answer('Готово');
        $this->actAs($author);
        self::assertSame(200, $this->ask($author, $workspace)->status);
    }

    public function testSuccessSpendsCreditsAfterTheAnswerAndLogsOnlyMetrics(): void
    {
        [$owner, $workspace] = $this->workspaceWithCredits(10);
        $this->connect();
        $this->answer('Новый **текст**', 40, 15);
        $this->actAs($owner);

        $response = $this->ask($owner, $workspace, 'rewrite', 'СЕКРЕТНЫЙ текст поста про запуск');

        self::assertSame(200, $response->status);
        $json = json_decode($response->body, true);
        self::assertTrue($json['ok']);
        self::assertSame('Новый **текст**', $json['text']);
        self::assertSame(2, $json['credits']);
        self::assertSame(8, $json['credits_left']);
        $summary = $this->app->container()->get(AiCredits::class)->summary($workspace->id, $workspace->publicId);
        self::assertSame(2, $summary['used']);
        $row = $this->db->select('SELECT * FROM ai_usage WHERE workspace_id = ?', [$workspace->id])[0];
        self::assertSame('ok', $row['status']);
        self::assertSame('openai', $row['provider']);
        self::assertSame([40, 15, 2], [(int) $row['tokens_in'], (int) $row['tokens_out'], (int) $row['credits']]);
        self::assertStringNotContainsString('СЕКРЕТНЫЙ', json_encode($row, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('СЕКРЕТНЫЙ', json_encode($this->llm->requests[0]['options']['json'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $audit = json_encode($this->db->select('SELECT meta_json FROM audit_log'), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('СЕКРЕТНЫЙ', (string) $audit);
        self::assertSame('Bearer sk-owner-secret', $this->llm->requests[0]['options']['headers']['Authorization']);
    }

    public function testProviderFailureCostsNothing(): void
    {
        [$owner, $workspace] = $this->workspaceWithCredits(10);
        $this->connect();
        $this->llm->expect('POST', 'https://api.openai.com/v1/chat/completions', 401, '{"error":"bad key sk-owner-secret"}');
        $this->actAs($owner);

        $response = $this->ask($owner, $workspace, 'generate', 'про кофе');

        self::assertSame(502, $response->status);
        self::assertStringNotContainsString('sk-owner-secret', $response->body);
        self::assertSame(0, $this->app->container()->get(AiCredits::class)->summary($workspace->id, $workspace->publicId)['used']);
        self::assertSame('credentials', $this->db->select('SELECT status FROM ai_usage WHERE workspace_id = ?', [$workspace->id])[0]['status']);
    }

    public function testNoCreditsMeansRefusalBeforeAnyCall(): void
    {
        [$owner, $workspace] = $this->workspaceWithCredits(1);
        $this->connect();
        $this->actAs($owner);

        $response = $this->ask($owner, $workspace, 'generate', 'про кофе');

        self::assertSame(402, $response->status);
        self::assertSame([], $this->llm->requests, 'the model is not asked when the credits cannot pay for it');
        $json = json_decode($response->body, true);
        self::assertSame('no_credits', $json['reason']);
        self::assertStringContainsString('/billing/plans', (string) $json['upgrade']);
    }

    public function testBoughtCreditsPayAfterTheMonthlyAllowance(): void
    {
        [$owner, $workspace] = $this->workspaceWithCredits(2);
        $this->app->container()->get(\App\Domain\Billing\Ledger::class)->grant($workspace->publicId, 'credits', 5, 'test pack');
        $this->connect();
        $this->actAs($owner);
        $credits = $this->app->container()->get(AiCredits::class);
        self::assertSame(7, $credits->summary($workspace->id, $workspace->publicId)['left']);

        $this->answer('один');
        $this->ask($owner, $workspace, 'generate', 'тема');

        $summary = $credits->summary($workspace->id, $workspace->publicId);
        self::assertSame(2, $summary['used'], 'the allowance goes first');
        self::assertSame(4, $summary['wallet'], 'one credit of the pack was needed for the third');
        self::assertSame(4, $summary['left']);
        self::assertSame(1, (int) $this->db->select("SELECT COUNT(*) AS n FROM ledger_entries WHERE ref_type = 'ai_spend'")[0]['n'] / 2);
    }

    public function testOwnModelIsFreeAndNeverUsesTheOwnerKey(): void
    {
        [$owner, $workspace] = $this->workspaceCredits0();
        $this->connect();
        $settings = $this->app->container()->get(AiWorkspaceSettings::class);
        self::assertSame([], $settings->saveOwn($workspace->id, ['enabled' => '1', 'base_url' => 'https://llm.example.org/v1', 'model' => 'my-model', 'api_key' => 'sk-own-secret', 'auth' => 'api-key']));
        $this->llm->expect('POST', 'https://llm.example.org/v1/chat/completions', 200, json_encode(['choices' => [['message' => ['content' => 'своё']]]], JSON_THROW_ON_ERROR));
        $this->actAs($owner);

        $response = $this->ask($owner, $workspace, 'generate', 'тема');

        self::assertSame(200, $response->status, 'no credits, but own key works');
        $json = json_decode($response->body, true);
        self::assertSame(0, $json['credits']);
        self::assertSame('Api-Key sk-own-secret', $this->llm->requests[0]['options']['headers']['Authorization']);
        self::assertSame(1, (int) $this->db->select('SELECT byok FROM ai_usage WHERE workspace_id = ?', [$workspace->id])[0]['byok']);
        $stored = (string) $this->db->select('SELECT byok_enc FROM ai_workspace_settings WHERE workspace_id = ?', [$workspace->id])[0]['byok_enc'];
        self::assertStringNotContainsString('sk-own-secret', $stored);
        self::assertTrue($settings->ownForForm($workspace->id)['key_set']);
        self::assertArrayNotHasKey('api_key', $settings->ownForForm($workspace->id));
    }

    /**
     * @return array{\App\Domain\User\User, \App\Domain\Workspace\Workspace}
     */
    private function workspaceCredits0(): array
    {
        return $this->workspaceWithCredits(0);
    }

    public function testOwnModelRefusesInsecureOrIncompleteSettings(): void
    {
        [, $workspace] = $this->workspaceWithCredits(5);
        $settings = $this->app->container()->get(AiWorkspaceSettings::class);

        self::assertNotSame([], $settings->saveOwn($workspace->id, ['enabled' => '1', 'base_url' => 'http://plain.example/v1', 'model' => 'm', 'api_key' => 'k']));
        self::assertNotSame([], $settings->saveOwn($workspace->id, ['enabled' => '1', 'base_url' => 'https://u:p@host.example/v1', 'model' => 'm', 'api_key' => 'k']));
        self::assertNotSame([], $settings->saveOwn($workspace->id, ['enabled' => '1', 'base_url' => 'https://host.example/v1?key=1', 'model' => 'm', 'api_key' => 'k']));
        self::assertNotSame([], $settings->saveOwn($workspace->id, ['enabled' => '1', 'base_url' => 'https://host.example/v1', 'model' => '', 'api_key' => 'k']));
        self::assertNull($settings->own($workspace->id));
    }

    public function testBrandVoiceReachesTheModel(): void
    {
        [$owner, $workspace] = $this->workspaceWithCredits(10);
        $this->connect();
        $this->actAs($owner);
        $this->post($this->base($workspace) . '/settings/ai/voice', ['tone' => 'строго и коротко', 'forbidden' => 'дёшево', 'example_1' => 'Мы открылись.']);
        $this->answer('ок');

        $this->ask($owner, $workspace);

        $user = $this->llm->requests[0]['options']['json']['messages'][1]['content'];
        self::assertStringContainsString('строго и коротко', $user);
        self::assertStringContainsString('дёшево', $user);
        self::assertContains('ai.voice_updated', $this->auditActions($workspace));
    }

    public function testValidationAndLimits(): void
    {
        [$owner, $workspace] = $this->workspaceWithCredits(10);
        $this->connect();
        $this->actAs($owner);

        self::assertSame(422, $this->ask($owner, $workspace, 'nope')->status);
        self::assertSame(422, $this->ask($owner, $workspace, 'rewrite', '   ')->status, 'rewrite needs text');
        self::assertSame(422, $this->ask($owner, $workspace, 'rewrite', str_repeat('я', 7000))->status, 'input is capped');
        self::assertSame([], $this->llm->requests);
    }

    public function testNotConnectedInProductionLikeSetup(): void
    {
        [$owner, $workspace] = $this->workspaceWithCredits(10);
        $factory = new \App\Integrations\Ai\Llm\LlmFactory($this->app->container()->get(LlmSettings::class), new \App\Integrations\Ai\Llm\LlmTransport($this->llm), false);
        $assistant = new AiAssistant($factory, $this->app->container()->get(AiWorkspaceSettings::class), $this->app->container()->get(AiCredits::class), $this->app->container()->get(AiUsageLog::class), $this->app->container()->get(\App\Domain\Analytics\Analytics::class), $this->app->container()->get(\App\Kernel\Config::class), $this->clock, $this->app->container()->get(\Redis::class), $this->app->container()->get(\App\Integrations\Ai\Llm\LlmChain::class));
        $context = $this->contextFor($workspace, $owner);

        self::assertFalse($assistant->available($context));
        try {
            $assistant->run($context, AiAction::Generate, '', 'тема');
            self::fail('expected not_connected');
        } catch (AiException $e) {
            self::assertSame('not_connected', $e->reason);
        }
    }

    public function testFakeModelIsFreeOutsideProduction(): void
    {
        [$owner, $workspace] = $this->workspaceCredits0();
        $this->actAs($owner);

        $response = $this->ask($owner, $workspace, 'rewrite', 'Тест');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Тестовый ответ', (string) json_decode($response->body, true)['text']);
        self::assertSame([], $this->llm->requests);
    }

    public function testPerMinuteLimit(): void
    {
        [$owner, $workspace] = $this->workspaceCredits0();
        $this->actAs($owner);
        $last = 200;
        for ($i = 0; $i < 12; ++$i) {
            $last = $this->ask($owner, $workspace, 'rewrite', 'Тест')->status;
        }

        self::assertSame(429, $last);
    }

    public function testAnswerIsPlainTextAndFencesAreRemoved(): void
    {
        [$owner, $workspace] = $this->workspaceWithCredits(10);
        $this->connect();
        $this->answer("```\n<script>alert(1)</script> текст\n```");
        $this->actAs($owner);

        $json = json_decode($this->ask($owner, $workspace)->body, true);

        self::assertSame('<script>alert(1)</script> текст', $json['text'], 'the text is returned as is for textContent; the page never renders it as HTML');
        $editor = file_get_contents(dirname(__DIR__, 3) . '/public/assets/js/ai.js');
        self::assertStringNotContainsString('innerHTML', (string) $editor);
    }
}
