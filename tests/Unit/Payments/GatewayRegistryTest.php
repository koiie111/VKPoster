<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payments;

use App\Integrations\Payments\Fake\FakeGateway;
use App\Integrations\Payments\GatewayRegistry;
use App\Integrations\Payments\TBank\TBankGateway;
use App\Integrations\Payments\YooKassa\YooKassaGateway;
use App\Tests\Support\BillingFixtures;
use App\Tests\Support\MockHttpClient;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GatewayRegistry::class)]
final class GatewayRegistryTest extends TestCase
{
    /**
     * @return list<\App\Integrations\Payments\Contracts\PaymentGateway>
     */
    private function all(bool $yookassaConfigured = true): array
    {
        $http = new MockHttpClient();

        return [
            new YooKassaGateway($http, $yookassaConfigured ? '1' : '', $yookassaConfigured ? 'k' : '', BillingFixtures::YOOKASSA_API, true, 'osn', 'none', 'x'),
            new TBankGateway($http, 't', 'p', BillingFixtures::TBANK_API, 'https://x/hook', 'osn', 'none', 'x'),
            new FakeGateway(TestEnv::connection()),
        ];
    }

    /**
     * @param list<\App\Integrations\Payments\Contracts\PaymentGateway> $list
     * @return list<string>
     */
    private static function names(array $list): array
    {
        return array_map(static fn ($g): string => $g->name(), $list);
    }

    public function testOffersTheConfiguredGatewaysInTheConfiguredOrder(): void
    {
        $registry = new GatewayRegistry($this->all(), ['tbank', 'yookassa'], false);

        self::assertSame(['tbank', 'yookassa'], self::names($registry->available()));
    }

    public function testAGatewayWithoutCredentialsIsNeverOffered(): void
    {
        $registry = new GatewayRegistry($this->all(false), ['yookassa', 'tbank'], false);

        self::assertSame(['tbank'], self::names($registry->available()));
        self::assertNull($registry->get('yookassa'));
        self::assertNull($registry->forWebhook('yookassa'));
    }

    public function testTheTestProviderExistsOnlyOutsideProduction(): void
    {
        $production = new GatewayRegistry($this->all(), ['yookassa', 'fake'], false);
        $local = new GatewayRegistry($this->all(), ['yookassa'], true);

        self::assertSame(['yookassa'], self::names($production->available()), 'listing it in production changes nothing');
        self::assertNull($production->forWebhook('fake'));
        self::assertSame(['yookassa', 'fake'], self::names($local->available()), 'appended automatically outside production');
        self::assertNotNull($local->get('fake'));
    }

    public function testAGatewayThatIsNoLongerOfferedCanStillFinishOldPayments(): void
    {
        $registry = new GatewayRegistry($this->all(), ['yookassa'], false);

        self::assertNull($registry->get('tbank'));
        self::assertNotNull($registry->forWebhook('tbank'));
    }

    public function testUnknownNamesAreNull(): void
    {
        $registry = new GatewayRegistry($this->all(), ['yookassa'], true);

        self::assertNull($registry->get('stripe'));
        self::assertNull($registry->forWebhook('stripe'));
    }
}
