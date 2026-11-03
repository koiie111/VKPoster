<?php

declare(strict_types=1);

namespace App\Tests\Feature\Ai;

use App\Domain\Ai\AiAssistant;
use App\Domain\Ai\AiCredits;
use App\Integrations\Ai\Llm\LlmChain;
use App\Integrations\Ai\Llm\LlmSettings;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Tests\Support\BillingTestCase;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The owner's chain of models: free models of one gateway first with daily request caps, a paid one after; a step that is spent,
 * parked after an error or unconfigured is skipped, a refusal of the text stops the walk, and credits are taken once, after an answer.
 */
#[CoversClass(LlmChain::class)]
#[CoversClass(AiAssistant::class)]
final class AiChainTest extends BillingTestCase
{
    private const URL = 'https://api.tokenator.cloud/v1/chat/completions';

    private MockHttpClient $llm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llm = new MockHttpClient();
        $this->app->container()->instance(HttpClientInterface::class, $this->llm);
        $this->clean();
        $settings = $this->app->container()->get(LlmSettings::class);
        self::assertSame([], $settings->save('tokenator', ['enabled' => '1', 'api_key' => 'sk-tok-secret'], null));
    }

    protected function tearDown(): void
    {
        $this->clean();
        parent::tearDown();
    }

    private function clean(): void
    {
        $this->db->execute('DELETE FROM ai_providers');
        $this->db->execute('DELETE FROM ai_chain_steps');
        $redis = $this->app->container()->get(\Redis::class);
        foreach ($redis->keys('ai-chain:*') as $key) {
            $redis->del($key);
        }
    }

    private function chain(): LlmChain
    {
        return $this->app->container()->get(LlmChain::class);
    }

    private function step(string $model, int $limit = 0, string $provider = 'tokenator'): void
    {
        self::assertSame([], $this->chain()->save(null, ['provider' => $provider, 'model' => $model, 'daily_requests' => (string) $limit, 'charge_credits' => '1', 'enabled' => '1'], null));
    }

    private function answer(string $text): void
    {
        $this->llm->expect('POST', self::URL, 200, json_encode(['choices' => [['message' => ['content' => $text]]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3]], JSON_THROW_ON_ERROR));
    }

    /** @return array{\App\Domain\User\User, \App\Domain\Workspace\Workspace} */
    private function actor(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'pro', ['ai_credits' => 20]);
        $this->actAs($owner);

        return [$owner, $workspace];
    }

    private function ask(\App\Domain\Workspace\Workspace $workspace): \App\Kernel\Http\Response
    {
        return $this->post($this->base($workspace) . '/ai/run', ['action' => 'rewrite', 'text' => 'Привет', 'brief' => '', 'platform' => 'telegram', 'limit' => '500'], ['Accept' => 'application/json']);
    }

    /** @return list<string> */
    private function models(): array
    {
        return array_map(static fn (array $r): string => (string) $r['options']['json']['model'], $this->llm->requests);
    }

    public function testFreeStepAnswersFirstAndTheStepModelIsSent(): void
    {
        $this->step('free-glm-5.3-flash', 60);
        $this->step('deepseek-v4.1-flash-1017');
        [, $workspace] = $this->actor();
        $this->answer('Готово');

        self::assertSame(200, $this->ask($workspace)->status);

        self::assertSame(['free-glm-5.3-flash'], $this->models());
        self::assertSame('Bearer sk-tok-secret', $this->llm->requests[0]['options']['headers']['Authorization']);
        self::assertSame(1, $this->chain()->usedToday($this->chain()->all()[0]['id']));
        self::assertSame(2, $this->app->container()->get(AiCredits::class)->summary($workspace->id, $workspace->publicId)['used']);
    }

    public function testASpentDailyCapSendsTheRequestToTheNextStep(): void
    {
        $this->step('free-glm-5.3-flash', 1);
        $this->step('deepseek-v4.1-flash-1017');
        [, $workspace] = $this->actor();
        $this->answer('Один');
        $this->answer('Два');

        $first = $this->ask($workspace);
        $second = $this->ask($workspace);

        self::assertSame([200, 200], [$first->status, $second->status]);
        self::assertSame(['free-glm-5.3-flash', 'deepseek-v4.1-flash-1017'], $this->models());
    }

    public function testAFailureFallsThroughLogsTheFailedStepAndParksIt(): void
    {
        $this->step('free-glm-5.3-flash', 60);
        $this->step('deepseek-v4.1-flash-1017');
        [, $workspace] = $this->actor();
        $this->llm->expect('POST', self::URL, 429, '{"error":"quota sk-tok-secret"}');
        $this->answer('Из запасной');
        $this->answer('Снова запасная');

        $first = $this->ask($workspace);
        $second = $this->ask($workspace);

        self::assertSame(200, $first->status);
        self::assertStringContainsString('Из запасной', $first->body);
        self::assertSame(200, $second->status);
        self::assertSame(['free-glm-5.3-flash', 'deepseek-v4.1-flash-1017', 'deepseek-v4.1-flash-1017'], $this->models(), 'the parked step is not asked again');
        self::assertTrue($this->chain()->cooling($this->chain()->all()[0]['id']));
        $statuses = array_column($this->db->select('SELECT status FROM ai_usage WHERE workspace_id = ? ORDER BY id', [$workspace->id]), 'status');
        self::assertSame(['rate_limited', 'ok', 'ok'], $statuses);
        self::assertStringNotContainsString('sk-tok-secret', $first->body);
        self::assertSame(4, $this->app->container()->get(AiCredits::class)->summary($workspace->id, $workspace->publicId)['used'], 'credits only for the two answers');
    }

    public function testATransientErrorGivesTheRequestBackToTheDay(): void
    {
        $this->step('free-glm-5.3-flash', 60);
        $this->step('deepseek-v4.1-flash-1017');
        [, $workspace] = $this->actor();
        $this->llm->expect('POST', self::URL, 503, '');
        $this->answer('Запасная');

        $this->ask($workspace);

        self::assertSame(0, $this->chain()->usedToday($this->chain()->all()[0]['id']));
    }

    public function testWhenEveryStepIsSpentNothingIsChargedAndNoCallIsMade(): void
    {
        $this->step('free-glm-5.3-flash', 1);
        [, $workspace] = $this->actor();
        $this->answer('Один');
        $this->ask($workspace);
        $before = count($this->llm->requests);

        $response = $this->ask($workspace);

        self::assertSame(502, $response->status);
        self::assertStringContainsString('исчерпали суточный лимит', $response->body);
        self::assertCount($before, $this->llm->requests);
        self::assertSame(2, $this->app->container()->get(AiCredits::class)->summary($workspace->id, $workspace->publicId)['used']);
    }

    public function testARefusalOfTheTextDoesNotTryOtherModels(): void
    {
        $this->step('free-glm-5.3-flash', 60);
        $this->step('deepseek-v4.1-flash-1017');
        [, $workspace] = $this->actor();
        $this->llm->expect('POST', self::URL, 200, json_encode(['choices' => [['message' => ['content' => ''], 'finish_reason' => 'content_filter']]], JSON_THROW_ON_ERROR));

        $response = $this->ask($workspace);

        self::assertNotSame(200, $response->status);
        self::assertSame(['free-glm-5.3-flash'], $this->models());
    }

    public function testDisabledAndKeylessStepsAreSkippedAndAnEmptyChainKeepsTheDefault(): void
    {
        $this->step('free-glm-5.3-flash', 60, 'openai');
        $this->step('free-minimax-m3.1-flash', 50);
        $chain = $this->chain();
        $off = $chain->all()[1];
        self::assertSame([], $chain->save($off['id'], ['provider' => 'tokenator', 'model' => 'free-minimax-m3.1-flash', 'enabled' => '0'], null));

        self::assertSame([], $chain->usable(), 'openai has no key, the other step is off');

        $this->db->execute('DELETE FROM ai_chain_steps');
        $settings = $this->app->container()->get(LlmSettings::class);
        $settings->save('tokenator', ['enabled' => '1', 'default' => '1', 'api_key' => ''], null);
        [, $workspace] = $this->actor();
        $this->answer('По умолчанию');

        self::assertSame(200, $this->ask($workspace)->status);
        self::assertSame(['free-glm-5.3-flash'], $this->models(), 'the preset model of the default provider');
    }

    public function testActionsChargesAndCooldownAreConfigurable(): void
    {
        $chain = $this->chain();
        self::assertSame([], $chain->save(null, ['provider' => 'tokenator', 'model' => 'free-glm-5.3-flash', 'actions' => ['spellcheck'], 'charge_credits' => '0', 'cooldown_seconds' => '5', 'reset_offset_minutes' => '180', 'enabled' => '1'], null));
        $this->step('deepseek-v4.1-flash-1017');
        $step = $chain->all()[0];
        self::assertSame([['spellcheck'], false, 5, 180], [$step['actions'], $step['charge_credits'], $step['cooldown_seconds'], $step['reset_offset_minutes']]);
        self::assertCount(2, $chain->usable('spellcheck'));
        self::assertCount(1, $chain->usable('rewrite'), 'the step serves only spell checks');

        [, $workspace] = $this->actor();
        $chain->acquire($step);
        $chain->failed($step, 'unavailable');
        self::assertTrue($chain->cooling($step['id']));
        $this->answer('Из платной');
        $this->ask($workspace);
        self::assertSame(['deepseek-v4.1-flash-1017'], $this->models());
        self::assertSame(2, $this->app->container()->get(AiCredits::class)->summary($workspace->id, $workspace->publicId)['used']);
    }

    public function testAStepThatDoesNotChargeCostsTheUserNothing(): void
    {
        self::assertSame([], $this->chain()->save(null, ['provider' => 'tokenator', 'model' => 'free-glm-5.3-flash', 'charge_credits' => '0', 'enabled' => '1'], null));
        [, $workspace] = $this->actor();
        $this->answer('Бесплатно');

        $this->ask($workspace);

        self::assertSame(0, $this->app->container()->get(AiCredits::class)->summary($workspace->id, $workspace->publicId)['used']);
    }

    public function testSaveValidatesAndCapsTheChain(): void
    {
        $chain = $this->chain();

        self::assertNotSame([], $chain->save(null, ['provider' => 'nope', 'model' => '', 'daily_requests' => '5'], null));
        self::assertNotSame([], $chain->save(null, ['provider' => 'tokenator', 'model' => '<script>', 'daily_requests' => '5'], null));
        self::assertNotSame([], $chain->save(null, ['provider' => 'tokenator', 'model' => '', 'daily_requests' => '-3'], null));
        self::assertNotSame([], $chain->save(null, ['provider' => 'tokenator', 'model' => '', 'cooldown_seconds' => '99999999'], null));
        self::assertNotSame([], $chain->save(null, ['provider' => 'tokenator', 'model' => '', 'reset_offset_minutes' => '9000'], null));
        self::assertNotSame([], $chain->save(null, ['provider' => 'tokenator', 'model' => '', 'actions' => ['nope']], null));
        self::assertNotSame([], $chain->save(999999, ['provider' => 'tokenator', 'model' => '', 'daily_requests' => '3'], null));
        for ($i = 0; $i < LlmChain::MAX_STEPS; ++$i) {
            $this->step('m' . $i);
        }
        self::assertNotSame([], $chain->save(null, ['provider' => 'tokenator', 'model' => 'extra', 'daily_requests' => '3'], null));
        self::assertCount(LlmChain::MAX_STEPS, $chain->all());
    }
}
