<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Integrations\Payments\Balance\BalanceGateway;
use App\Integrations\Payments\CloudPayments\CloudPaymentsGateway;
use App\Integrations\Payments\Contracts\PaymentGateway;
use App\Integrations\Payments\Crypto\CryptoGateway;
use App\Integrations\Payments\Fake\FakeGateway;
use App\Integrations\Payments\Paddle\PaddleGateway;
use App\Integrations\Payments\Stars\StarsGateway;
use App\Integrations\Payments\Stripe\StripeGateway;
use App\Integrations\Payments\TBank\TBankGateway;
use App\Integrations\Payments\YooKassa\YooKassaGateway;
use App\Integrations\Social\Telegram\TelegramClientFactory;
use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Support\Clock;

/**
 * Builds every payment gateway from what the owner entered in the admin area (`ProviderSettings`, the environment as a fallback). It is
 * asked again on every use, so a changed key reaches a worker that has been running for days.
 */
final class GatewayFactory
{
    public function __construct(
        private readonly ProviderSettings $settings,
        private readonly Config $config,
        private readonly HttpClientInterface $http,
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly TelegramClientFactory $telegram,
    ) {
    }

    /**
     * @return list<PaymentGateway>
     */
    public function all(): array
    {
        $s = $this->settings;
        $tax = [$this->config->string('billing.tax.tax_system'), $this->config->string('billing.tax.vat'), $this->config->string('billing.tax.item_name')];
        $base = rtrim($this->config->string('app.url'), '/');

        return [
            new YooKassaGateway(
                $this->http,
                $s->value('yookassa', 'shop_id'),
                $s->value('yookassa', 'secret_key'),
                $this->config->string('billing.yookassa.api_base', 'https://api.yookassa.ru/v3'),
                // The sender check cannot be switched off in production.
                $this->config->bool('billing.yookassa.verify_ip', true) || $this->config->isProduction(),
                ...$tax,
            ),
            new TBankGateway(
                $this->http,
                $s->value('tbank', 'terminal_key'),
                $s->value('tbank', 'password'),
                $this->config->string('billing.tbank.api_base', 'https://securepay.tinkoff.ru/v2'),
                $base . '/webhooks/billing/tbank',
                ...$tax,
                caFile: $this->config->string('billing.tbank.ca_file'),
            ),
            new CloudPaymentsGateway($this->http, $s->value('cloudpayments', 'public_id'), $s->value('cloudpayments', 'api_secret'), self::currencies($s->value('cloudpayments', 'currencies'), ['RUB'])),
            new StripeGateway($this->http, $s->value('stripe', 'secret_key'), $s->value('stripe', 'webhook_secret'), $this->clock, self::currencies($s->value('stripe', 'currencies'), ['USD', 'EUR'])),
            new PaddleGateway($this->http, $s->value('paddle', 'api_key'), $s->value('paddle', 'webhook_secret'), $this->clock, $s->value('paddle', 'sandbox') === '1', self::currencies($s->value('paddle', 'currencies'), ['USD'])),
            new CryptoGateway($this->http, $s->value('crypto', 'merchant_id'), $s->value('crypto', 'api_key'), $base . '/webhooks/billing/crypto', self::currencies($s->value('crypto', 'currencies'), ['USD'])),
            new StarsGateway($this->db, $this->telegram, $this->config->string('platforms.telegram.bot_token')),
            new BalanceGateway($this->db),
            new FakeGateway($this->db),
        ];
    }

    /**
     * @return list<string>
     */
    public function enabled(): array
    {
        return $this->settings->enabled();
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private static function currencies(string $text, array $default): array
    {
        $list = [];
        foreach (explode(',', $text) as $code) {
            $code = strtoupper(trim($code));
            if (preg_match('/^[A-Z]{3}$/', $code) === 1) {
                $list[] = $code;
            }
        }

        return $list === [] ? $default : array_values(array_unique($list));
    }
}
