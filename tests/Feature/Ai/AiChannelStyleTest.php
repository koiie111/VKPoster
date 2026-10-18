<?php

declare(strict_types=1);

namespace App\Tests\Feature\Ai;

use App\Domain\Ai\AiWorkspaceSettings;
use App\Domain\Ai\BrandVoice;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Ai\AiController;
use App\Http\Controllers\Ai\AiSettingsController;
use App\Integrations\Ai\Llm\LlmSettings;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Tests\Support\BillingTestCase;
use App\Tests\Support\MockHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;

/** A separate writing style per channel: set by admins, held by the assistant for that channel only, isolated between workspaces. */
#[CoversClass(AiSettingsController::class)]
#[CoversClass(AiController::class)]
#[CoversClass(AiWorkspaceSettings::class)]
#[CoversClass(BrandVoice::class)]
final class AiChannelStyleTest extends BillingTestCase
{
    private MockHttpClient $llm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llm = new MockHttpClient();
        $this->app->container()->instance(HttpClientInterface::class, $this->llm);
        $this->db->execute('DELETE FROM ai_providers');
        $this->app->container()->get(LlmSettings::class)->save('openai', ['enabled' => '1', 'default' => '1', 'api_key' => 'sk-x'], null);
    }

    protected function tearDown(): void
    {
        $this->db->execute('DELETE FROM ai_providers');
        parent::tearDown();
    }

    private function answer(): void
    {
        $this->llm->expect('POST', 'https://api.openai.com/v1/chat/completions', 200, json_encode(['choices' => [['message' => ['content' => 'ок']]]], JSON_THROW_ON_ERROR));
    }

    public function testChannelStyleIsHeldOnTopOfTheWorkspaceVoiceForThatChannelOnly(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'pro', ['ai_credits' => 50, 'channels' => 5]);
        $coffee = $this->fakeChannel($workspace, $owner, 'c1', 'Кофейня');
        $tech = $this->fakeChannel($workspace, $owner, 'c2', 'Техно');
        $this->actAs($owner);
        $base = $this->base($workspace);
        $this->post($base . '/settings/ai/voice', ['tone' => 'дружелюбно', 'forbidden' => 'дёшево', 'rules' => 'Пишем на «вы»']);
        $saved = $this->post($base . '/settings/ai/channels/' . $coffee->publicId, ['tone' => 'по-домашнему тепло', 'rules' => "Начинать с вопроса\nВ конце — «Ваша Кофемания»", 'forbidden' => 'скидка', 'example_1' => 'Какой кофе вы пьёте утром? Мы знаем.']);
        self::assertSame(302, $saved->status);

        $this->answer();
        $this->post($base . '/ai/run', ['action' => 'generate', 'brief' => 'новый сорт', 'channel' => $coffee->publicId], ['Accept' => 'application/json']);
        $this->answer();
        $json = json_decode($this->post($base . '/ai/run', ['action' => 'generate', 'brief' => 'новый сорт', 'channel' => $tech->publicId], ['Accept' => 'application/json'])->body, true);

        $forCoffee = $this->llm->requests[0]['options']['json']['messages'][1]['content'];
        foreach (['по-домашнему тепло', 'Начинать с вопроса', 'Ваша Кофемания', 'Пишем на «вы»', 'скидка', 'дёшево', 'Какой кофе вы пьёте утром'] as $needle) {
            self::assertStringContainsString($needle, $forCoffee);
        }
        self::assertStringNotContainsString('дружелюбно', $forCoffee, 'the channel tone wins over the workspace tone');
        $forTech = $this->llm->requests[1]['options']['json']['messages'][1]['content'];
        self::assertStringContainsString('дружелюбно', $forTech);
        self::assertStringNotContainsString('Кофемания', $forTech);
        self::assertSame('workspace', $json['style']);
        self::assertContains('ai.channel_style_updated', $this->auditActions($workspace));
    }

    public function testResponseNamesTheChannelStyleAndAnEmptyStyleClearsIt(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'pro', ['ai_credits' => 50]);
        $channel = $this->fakeChannel($workspace, $owner, 'c1', 'Кофейня');
        $this->actAs($owner);
        $url = $this->base($workspace) . '/settings/ai/channels/' . $channel->publicId;

        $this->post($url, ['rules' => 'Коротко']);
        $this->answer();
        $json = json_decode($this->post($this->base($workspace) . '/ai/run', ['action' => 'generate', 'brief' => 'x', 'channel' => $channel->publicId], ['Accept' => 'application/json'])->body, true);
        self::assertSame('channel', $json['style']);
        self::assertSame('Кофейня', $json['style_channel']);
        self::assertStringContainsString('Свой стиль задан', $this->get($this->base($workspace) . '/settings/ai')->body);

        $this->post($url, []);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM ai_channel_styles')[0]['n']);
    }

    public function testStylesAreIsolatedAndProtected(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        [$other, $otherWorkspace] = $this->ownerWithWorkspace('other@example.com', 'Другой');
        $this->givePlan($workspace, 'pro', ['ai_credits' => 50]);
        $mine = $this->fakeChannel($workspace, $owner, 'c1', 'Мой');
        $foreign = $this->fakeChannel($otherWorkspace, $other, 'c9', 'Чужой');
        $editor = $this->memberOf($workspace, 'editor@example.com', Role::Editor);

        $this->actAs($editor);
        self::assertSame(403, $this->post($this->base($workspace) . '/settings/ai/channels/' . $mine->publicId, ['rules' => 'x'])->status);
        $this->actAs($owner);
        self::assertSame(404, $this->post($this->base($workspace) . '/settings/ai/channels/' . $foreign->publicId, ['rules' => 'x'])->status, 'a foreign channel');
        $this->post($this->base($otherWorkspace) . '/settings/ai/channels/' . $foreign->publicId, ['rules' => 'x']);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM ai_channel_styles')[0]['n']);

        // A foreign channel id in the editor request is ignored: no style leaks into the prompt.
        $this->app->container()->get(AiWorkspaceSettings::class)->saveChannelStyle($otherWorkspace->id, $foreign->id, new BrandVoice(rules: 'СЕКРЕТНОЕ ПРАВИЛО ДРУГОГО'));
        $this->answer();
        $this->post($this->base($workspace) . '/ai/run', ['action' => 'generate', 'brief' => 'x', 'channel' => $foreign->publicId], ['Accept' => 'application/json']);
        self::assertStringNotContainsString('СЕКРЕТНОЕ', json_encode($this->llm->requests[0]['options']['json'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function testStyleIsBoundedAndDeletedWithTheChannel(): void
    {
        $voice = BrandVoice::fromArray(['rules' => str_repeat('я', 5000)]);
        self::assertSame(BrandVoice::MAX_RULES, mb_strlen($voice->rules));
        $merged = (new BrandVoice('A', ['x'], ['e1'], 'r1'))->withChannel(new BrandVoice('', ['y', 'x'], [], 'r2'));
        self::assertSame(['A', ['x', 'y'], ['e1'], "r1\nr2"], [$merged->tone, $merged->forbidden, $merged->examples, $merged->rules]);

        [$owner, $workspace] = $this->ownerWithWorkspace();
        $channel = $this->fakeChannel($workspace, $owner, 'c1', 'Канал');
        $settings = $this->app->container()->get(AiWorkspaceSettings::class);
        $settings->saveChannelStyle($workspace->id, $channel->id, new BrandVoice(rules: 'r'));
        self::assertSame([$channel->id], $settings->styledChannels($workspace->id));
        $this->db->execute('DELETE FROM channels WHERE id = ?', [$channel->id]);
        self::assertSame([], $settings->styledChannels($workspace->id));
    }
}
