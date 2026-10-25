<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Admin\StaffRole;
use App\Http\Controllers\Admin\AiProvidersController;
use App\Integrations\Ai\Llm\LlmCatalog;
use App\Integrations\Ai\Llm\LlmFactory;
use App\Integrations\Ai\Llm\LlmSettings;
use App\Integrations\Ai\Llm\AnthropicProvider;
use App\Integrations\Ai\Llm\OpenAiCompatibleProvider;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/** The owner connects AI providers in the admin area: presets by key, custom OpenAI-compatible ones by address, secrets encrypted. */
#[CoversClass(AiProvidersController::class)]
#[CoversClass(LlmSettings::class)]
#[CoversClass(LlmFactory::class)]
#[CoversClass(LlmCatalog::class)]
final class AiProvidersTest extends AdminTestCase
{
    private function settings(): LlmSettings
    {
        return $this->app->container()->get(LlmSettings::class);
    }

    public function testOnlyTheOwnerOpensIt(): void
    {
        $this->staff(StaffRole::Finance);
        self::assertSame(403, $this->get('/admin/ai')->status);
        self::assertSame(403, $this->post('/admin/ai/openai', [])->status);
        $this->staff();
        $page = $this->get('/admin/ai');
        self::assertSame(200, $page->status);
        foreach (['OpenAI', 'Anthropic (Claude)', 'Google Gemini', 'OpenRouter', 'YandexGPT', 'DeepSeek', 'Groq', 'Mistral', 'Together AI', 'Tokenator', 'Цепочка моделей', 'Добавить свой OpenAI-совместимый'] as $label) {
            self::assertStringContainsString($label, $page->body);
        }
        self::assertStringContainsString('Данные остаются в РФ', $page->body);
    }

    public function testSavingNeedsACodeAndKeepsTheKeyEncryptedAndHidden(): void
    {
        $this->staff();
        $form = ['enabled' => '1', 'default' => '1', 'api_key' => 'sk-TOPSECRET', 'model' => 'gpt-4o'];

        $this->post('/admin/ai/openai', $form);
        self::assertSame([], $this->settings()->usable(), 'no code, nothing saved');

        $this->post('/admin/ai/openai', $this->confirm($form));

        self::assertSame('openai', $this->settings()->defaultCode());
        $stored = (string) $this->db->select("SELECT config_enc FROM ai_providers WHERE code = 'openai'")[0]['config_enc'];
        self::assertStringNotContainsString('TOPSECRET', $stored);
        $page = $this->get('/admin/ai');
        self::assertStringNotContainsString('TOPSECRET', $page->body);
        self::assertStringContainsString('Ключ сохранён', $page->body);
        $audit = (string) $this->db->select("SELECT meta_json FROM audit_log WHERE action = 'admin.ai_provider_updated' ORDER BY id DESC LIMIT 1")[0]['meta_json'];
        self::assertStringNotContainsString('TOPSECRET', $audit);
        self::assertStringNotContainsString('gpt-4o', $audit);

        $this->post('/admin/ai/openai', $this->confirm(['enabled' => '1', 'default' => '1', 'api_key' => '']));
        self::assertSame('sk-TOPSECRET', $this->settings()->usable()['openai']['values']['api_key'], 'an empty key field keeps the saved one');
    }

    public function testOnlyOneDefaultAndTheFactoryBuildsTheRightClient(): void
    {
        $this->staff();
        $this->post('/admin/ai/openai', $this->confirm(['enabled' => '1', 'default' => '1', 'api_key' => 'k1']));
        $this->post('/admin/ai/anthropic', $this->confirm(['enabled' => '1', 'default' => '1', 'api_key' => 'k2']));

        self::assertSame('anthropic', $this->settings()->defaultCode());
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS n FROM ai_providers WHERE is_default = 1')[0]['n']);
        $factory = $this->app->container()->get(LlmFactory::class);
        self::assertInstanceOf(AnthropicProvider::class, $factory->default());
        self::assertInstanceOf(OpenAiCompatibleProvider::class, $factory->forCode('openai'));
        self::assertNull($factory->forCode('groq'), 'not connected');
    }

    public function testYandexNeedsAModelWithTheFolderId(): void
    {
        $this->staff();
        $this->post('/admin/ai/yandex', $this->confirm(['enabled' => '1', 'default' => '1', 'api_key' => 'AQVN-key']));
        self::assertArrayNotHasKey('yandex', $this->settings()->usable(), 'the preset model carries a placeholder folder');

        $this->post('/admin/ai/yandex', $this->confirm(['enabled' => '1', 'default' => '1', 'api_key' => '', 'model' => 'gpt://b1gabc/yandexgpt/latest']));

        self::assertArrayHasKey('yandex', $this->settings()->usable());
    }

    public function testCustomOpenAiCompatibleProviderCanBeAddedAndRemoved(): void
    {
        $this->staff();
        $form = ['slug' => 'my-gw', 'label' => 'Мой шлюз', 'base_url' => 'https://gw.example.org/v1', 'model' => 'qwen2.5-72b', 'api_key' => 'gw-secret', 'auth' => 'api-key', 'enabled' => '1', 'default' => '1'];

        $this->post('/admin/ai', $this->confirm($form));

        self::assertArrayHasKey('custom-my-gw', $this->settings()->usable());
        $provider = $this->app->container()->get(LlmFactory::class)->default();
        self::assertInstanceOf(OpenAiCompatibleProvider::class, $provider);
        self::assertSame('custom-my-gw', $provider->name());
        self::assertStringContainsString('Мой шлюз', $this->get('/admin/ai')->body);

        $this->post('/admin/ai/custom-my-gw/delete', $this->confirm());
        self::assertArrayNotHasKey('custom-my-gw', $this->settings()->all());
    }

    public function testInvalidInputIsRefused(): void
    {
        $this->staff();
        foreach ([
            ['slug' => 'Bad Slug', 'label' => 'x', 'base_url' => 'https://a.example/v1', 'model' => 'm', 'api_key' => 'k'],
            ['slug' => 'ok', 'label' => 'Имя', 'base_url' => 'http://insecure.example/v1', 'model' => 'm', 'api_key' => 'k'],
            ['slug' => 'ok', 'label' => 'Имя', 'base_url' => 'https://user:pw@a.example/v1', 'model' => 'm', 'api_key' => 'k'],
            ['slug' => 'ok', 'label' => 'Имя', 'base_url' => '', 'model' => 'm', 'api_key' => 'k'],
            ['slug' => 'ok', 'label' => 'Имя', 'base_url' => 'https://a.example/v1', 'model' => "m\nx", 'api_key' => 'k'],
        ] as $form) {
            $this->post('/admin/ai', $this->confirm($form + ['enabled' => '1']));
        }
        self::assertSame([], $this->settings()->all());
        self::assertSame(404, $this->post('/admin/ai/custom-nothing/delete', $this->confirm())->status);
        self::assertSame(404, $this->post('/admin/ai/unknown-provider', $this->confirm())->status);
        self::assertFalse(LlmCatalog::isCustom('custom-'), 'an empty slug is not a code');
    }

    public function testTheChainIsManagedWithACodeAndAudited(): void
    {
        $this->staff(StaffRole::Finance);
        self::assertSame(403, $this->post('/admin/ai-chain', [])->status);
        $this->staff();
        $form = ['provider' => 'tokenator', 'model' => 'free-glm-5.3-flash', 'label' => 'GLM бесплатный', 'daily_requests' => '60', 'enabled' => '1'];

        $this->post('/admin/ai-chain', $form);
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM ai_chain_steps')[0]['n'], 'no code, nothing saved');

        $this->post('/admin/ai-chain', $this->confirm($form));
        $id = (int) $this->db->select('SELECT id FROM ai_chain_steps')[0]['id'];
        self::assertStringContainsString('GLM бесплатный', $this->get('/admin/ai')->body);

        $this->post('/admin/ai-chain/' . $id, $this->confirm(['provider' => 'tokenator', 'model' => 'free-minimax-m3.1-flash', 'daily_requests' => '50', 'enabled' => '1']));
        self::assertSame(['free-minimax-m3.1-flash', 50], [$this->db->select('SELECT model FROM ai_chain_steps')[0]['model'], (int) $this->db->select('SELECT daily_requests FROM ai_chain_steps')[0]['daily_requests']]);

        $this->post('/admin/ai-chain', $this->confirm(['provider' => 'tokenator', 'model' => '<bad>', 'daily_requests' => '1']));
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS n FROM ai_chain_steps')[0]['n'], 'an invalid step is refused');

        $this->post('/admin/ai-chain/' . $id . '/delete', $this->confirm());
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS n FROM ai_chain_steps')[0]['n']);
        self::assertSame(3, (int) $this->db->select("SELECT COUNT(*) AS n FROM audit_log WHERE action = 'admin.ai_chain_updated'")[0]['n']);
    }
}
