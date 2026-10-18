<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

/**
 * What the admin area knows about each payment provider: its name, the fields the owner fills in and where the notifications go.
 * The list is code, not data: adding a provider means writing its gateway. Which providers are on, and their keys, are data
 * (`ProviderSettings`).
 *
 * A field is `[key, label, secret, hint]`; a secret field is never shown again after it is saved.
 */
final class ProviderCatalog
{
    /**
     * @return array<string, array{label: string, region: string, fields: list<array{key: string, label: string, secret: bool, hint: string}>, webhook: bool, note: string}>
     */
    public static function all(): array
    {
        return [
            'yookassa' => [
                'label' => 'ЮKassa', 'region' => 'Россия, карты, СБП', 'webhook' => true, 'note' => 'Автопродление по сохранённой карте.',
                'fields' => [
                    self::field('shop_id', 'Идентификатор магазина', false, 'Личный кабинет ЮKassa → Интеграция.'),
                    self::field('secret_key', 'Секретный ключ', true, 'Выпускается там же. Для проверки используйте тестовый магазин.'),
                ],
            ],
            'tbank' => [
                'label' => 'Т-Банк', 'region' => 'Россия, карты', 'webhook' => true, 'note' => 'Автопродление по сохранённой карте.',
                'fields' => [
                    self::field('terminal_key', 'Ключ терминала', false, 'Личный кабинет Т-Банка → Терминалы.'),
                    self::field('password', 'Пароль терминала', true, 'Им подписываются запросы.'),
                ],
            ],
            'cloudpayments' => [
                'label' => 'CloudPayments', 'region' => 'Россия, карты', 'webhook' => true, 'note' => 'Автопродление по токену карты.',
                'fields' => [
                    self::field('public_id', 'Public ID', false, 'Личный кабинет CloudPayments → Сайты.'),
                    self::field('api_secret', 'Пароль для API', true, 'Им подписываются уведомления (Content-HMAC) и запросы к API.'),
                    self::field('currencies', 'Валюты', false, 'Через запятую, например RUB. По умолчанию RUB.'),
                ],
            ],
            'stripe' => [
                'label' => 'Stripe', 'region' => 'Зарубежные карты', 'webhook' => true, 'note' => 'Нужно юрлицо в стране, где работает Stripe. Автопродление по сохранённой карте.',
                'fields' => [
                    self::field('secret_key', 'Секретный ключ', true, 'Developers → API keys (sk_live_… или sk_test_…).'),
                    self::field('webhook_secret', 'Секрет вебхука', true, 'Developers → Webhooks → Signing secret (whsec_…).'),
                    self::field('currencies', 'Валюты', false, 'Через запятую, например USD,EUR. По умолчанию USD,EUR.'),
                ],
            ],
            'paddle' => [
                'label' => 'Paddle', 'region' => 'Зарубежные карты, налоги на Paddle', 'webhook' => true, 'note' => 'Paddle — продавец (Merchant of Record). Продление вручную.',
                'fields' => [
                    self::field('api_key', 'API-ключ', true, 'Developer tools → Authentication.'),
                    self::field('webhook_secret', 'Секрет вебхука', true, 'Developer tools → Notifications.'),
                    self::field('sandbox', 'Песочница (1 — да)', false, '1 для тестовой среды sandbox-api.paddle.com.'),
                    self::field('currencies', 'Валюты', false, 'Через запятую, например USD,EUR. По умолчанию USD.'),
                ],
            ],
            'crypto' => [
                'label' => 'Криптовалюта (Heleket)', 'region' => 'Весь мир', 'webhook' => true, 'note' => 'Разовый счёт на период, без автопродления. Недоплата и переплата разбираются вручную.',
                'fields' => [
                    self::field('merchant_id', 'UUID мерчанта', false, 'Кабинет Heleket → Настройки.'),
                    self::field('api_key', 'Ключ платежей', true, 'Им подписываются запросы и уведомления.'),
                    self::field('currencies', 'Валюты счёта', false, 'Через запятую, например USD. По умолчанию USD.'),
                ],
            ],
            'stars' => [
                'label' => 'Telegram Stars', 'region' => 'Telegram', 'webhook' => false, 'note' => 'Оплата звёздами в общем боте (TELEGRAM_BOT_TOKEN): бот должен получать обновления (webhook или telegram:poll), в плане нужны цены в звёздах. Продление вручную.',
                'fields' => [],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    /**
     * @return array{key: string, label: string, secret: bool, hint: string}
     */
    private static function field(string $key, string $label, bool $secret, string $hint): array
    {
        return ['key' => $key, 'label' => $label, 'secret' => $secret, 'hint' => $hint];
    }
}
