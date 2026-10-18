<?php

declare(strict_types=1);

namespace App\Tests\Feature\Admin;

use App\Domain\Admin\StaffRole;
use App\Http\Controllers\Admin\PaymentProvidersController;
use App\Integrations\Payments\GatewayRegistry;
use App\Integrations\Payments\ProviderSettings;
use App\Tests\Support\AdminTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The owner connects payment providers in the admin area: keys are encrypted and never shown again, an empty secret keeps the saved
 * one, and the checkout follows the switches at once.
 */
#[CoversClass(PaymentProvidersController::class)]
#[CoversClass(ProviderSettings::class)]
final class PaymentProvidersTest extends AdminTestCase
{
    private function settings(): ProviderSettings
    {
        return $this->app->container()->get(ProviderSettings::class);
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return array_map(static fn ($g): string => $g->name(), $this->app->container()->get(GatewayRegistry::class)->available());
    }

    public function testOnlyTheOwnerOpensTheProviders(): void
    {
        $this->staff(StaffRole::Finance);
        self::assertSame(403, $this->get('/admin/providers')->status);
        self::assertSame(403, $this->post('/admin/providers/stripe', [])->status);
        $this->staff();
        $page = $this->get('/admin/providers');
        self::assertSame(200, $page->status);
        foreach (['ЮKassa', 'CloudPayments', 'Stripe', 'Paddle', 'Heleket', 'Telegram Stars'] as $label) {
            self::assertStringContainsString($label, $page->body);
        }
        self::assertStringContainsString('/webhooks/billing/cloudpayments?type=pay', $page->body);
    }

    public function testSavingNeedsACodeAndKeepsSecretsEncrypted(): void
    {
        $this->staff();
        $form = ['enabled' => '1', 'secret_key' => 'sk_live_TOPSECRET', 'webhook_secret' => 'whsec_TOPSECRET', 'currencies' => 'usd, eur'];

        $this->post('/admin/providers/stripe', $form);
        self::assertFalse($this->settings()->isEnabled('stripe'), 'no code, nothing saved');

        $this->post('/admin/providers/stripe', $this->confirm($form));

        self::assertTrue($this->settings()->isEnabled('stripe'));
        self::assertSame('sk_live_TOPSECRET', $this->settings()->value('stripe', 'secret_key'));
        $stored = (string) $this->db->select("SELECT config_enc FROM payment_providers WHERE code = 'stripe'")[0]['config_enc'];
        self::assertStringNotContainsString('TOPSECRET', $stored);
        self::assertStringNotContainsString('sk_live', $stored);
        self::assertContains('stripe', $this->names());
        $audit = (string) $this->db->select("SELECT meta_json FROM audit_log WHERE action = 'admin.provider_updated' ORDER BY id DESC LIMIT 1")[0]['meta_json'];
        self::assertStringNotContainsString('TOPSECRET', $audit);
    }

    public function testSecretsAreNeverShownAgain(): void
    {
        $this->staff();
        $this->post('/admin/providers/stripe', $this->confirm(['enabled' => '1', 'secret_key' => 'sk_live_TOPSECRET', 'webhook_secret' => 'whsec_TOPSECRET']));

        $page = $this->get('/admin/providers');

        self::assertStringNotContainsString('TOPSECRET', $page->body);
        self::assertStringContainsString('Ключ сохранён', $page->body);
    }

    public function testAnEmptySecretKeepsTheSavedOneAndSwitchingOffHidesTheProvider(): void
    {
        $this->staff();
        $this->post('/admin/providers/stripe', $this->confirm(['enabled' => '1', 'secret_key' => 'sk_live_A', 'webhook_secret' => 'whsec_A']));
        $this->post('/admin/providers/stripe', $this->confirm(['enabled' => '1', 'secret_key' => '', 'webhook_secret' => '']));

        self::assertSame('sk_live_A', $this->settings()->value('stripe', 'secret_key'));
        self::assertContains('stripe', $this->names());

        $this->post('/admin/providers/stripe', $this->confirm(['secret_key' => '', 'webhook_secret' => '']));

        self::assertNotContains('stripe', $this->names());
        self::assertSame('sk_live_A', $this->settings()->value('stripe', 'secret_key'), 'the keys stay for the next switch-on');
    }

    public function testAProviderWithoutKeysIsNotOffered(): void
    {
        $this->staff();
        $this->post('/admin/providers/paddle', $this->confirm(['enabled' => '1']));

        self::assertTrue($this->settings()->isEnabled('paddle'));
        self::assertNotContains('paddle', $this->names());
    }

    public function testAnUnknownProviderIs404AndBadInputIsRefused(): void
    {
        $this->staff();
        self::assertSame(404, $this->post('/admin/providers/nopeprovider', $this->confirm())->status);

        $this->post('/admin/providers/stripe', $this->confirm(['enabled' => '1', 'secret_key' => "bad\x00key", 'webhook_secret' => 'x']));
        self::assertSame('', $this->settings()->value('stripe', 'secret_key'));
    }
}
