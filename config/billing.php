<?php

declare(strict_types=1);

use App\Kernel\Env;

/**
 * Billing, read as `billing` in `Config`: payment gateways, tax data for receipts, the trial, the renewal ladder and the plan catalogue.
 *
 * `catalog` is the starting price list and limit table (master plan §3.1). It is copied into the `plans` and `plan_prices` tables
 * by the migration (and by `billing:sync-plans` for plans that are not there yet); from then on the database is the truth, so prices
 * can be changed without a deploy. Money is in kopecks. A `null` limit means "unlimited".
 */
return static function (Env $env): array {
    $mb = 1024 * 1024;

    return [
        // Gateways offered on the checkout page. `fake` (a stand-in with a pay/decline page) is ignored in production.
        'gateways' => $env->list('BILLING_GATEWAYS') !== [] ? $env->list('BILLING_GATEWAYS') : ['yookassa', 'tbank'],
        'currency' => 'RUB',
        'yookassa' => [
            'shop_id' => $env->string('YOOKASSA_SHOP_ID'),
            'secret_key' => $env->string('YOOKASSA_SECRET_KEY'),
            'api_base' => rtrim($env->string('YOOKASSA_API_BASE', 'https://api.yookassa.ru/v3'), '/'),
            // Notifications are accepted only from YooKassa's published addresses. Switching the check off (a tunnel in dev) is refused in
            // production; the payment is re-read from the API in any case.
            'verify_ip' => $env->bool('YOOKASSA_VERIFY_IP', true),
        ],
        'tbank' => [
            'terminal_key' => $env->string('TBANK_TERMINAL_KEY'),
            'password' => $env->string('TBANK_PASSWORD'),
            'api_base' => rtrim($env->string('TBANK_API_BASE', 'https://securepay.tinkoff.ru/v2'), '/'),
        ],
        // Data for the 54-FZ receipt. `tax_system`: npd | osn | usn_income | usn_income_outcome | patent | envd | esn. `npd` (self-employed): no 54-FZ receipt is sent to the provider, the seller issues receipts in "Мой налог".
        // `vat`: none | vat0 | vat10 | vat20 (an individual entrepreneur on the simplified system is usually "none").
        'tax' => [
            'tax_system' => $env->string('BILLING_TAX_SYSTEM', 'npd'),
            'vat' => $env->string('BILLING_VAT', 'none'),
            'item_name' => 'Подписка ezposter',
        ],
        // Who sells (printed on the receipt): the individual entrepreneur's name and tax number. Empty = the line is left out.
        'seller' => ['name' => $env->string('BILLING_SELLER_NAME'), 'inn' => $env->string('BILLING_SELLER_INN')],
        // A TrueType font with Cyrillic for the PDF receipt (the Docker image ships DejaVu Sans).
        'receipt_font' => $env->string('BILLING_RECEIPT_FONT', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'),
        'trial' => ['plan' => 'pro', 'days' => 14],
        // Renewal: the first attempt is `lead_days` before the period ends; after a failure it is repeated after each of `retry_days`
        // (counted from the first attempt); when the period has ended the workspace has `grace_days` before it falls back to Free.
        'renewal' => ['lead_days' => 3, 'retry_days' => [1, 3, 4, 5, 6], 'grace_days' => 3],
        // An unpaid checkout is forgotten after this many hours (the payment page of the provider expires about then too).
        'checkout_ttl_hours' => 24,
        'catalog' => [
            'free' => [
                'name' => 'Free',
                'sort' => 0,
                'limits' => ['channels' => 2, 'posts_per_month' => 30, 'workspaces' => 1, 'members' => 1, 'storage_bytes' => 500 * $mb, 'crosspost_rules' => 0, 'analytics_days' => 7, 'ai_credits' => 10],
                'features' => [],
                'prices' => [],
            ],
            'start' => [
                'name' => 'Старт',
                'sort' => 1,
                'limits' => ['channels' => 10, 'posts_per_month' => null, 'workspaces' => 1, 'members' => 2, 'storage_bytes' => 5 * 1024 * $mb, 'crosspost_rules' => 1, 'analytics_days' => 30, 'ai_credits' => 100],
                'features' => ['slots', 'crosspost'],
                'prices' => ['month' => 39000, 'year' => 390000],
            ],
            'pro' => [
                'name' => 'Про',
                'sort' => 2,
                'limits' => ['channels' => 30, 'posts_per_month' => null, 'workspaces' => 3, 'members' => 5, 'storage_bytes' => 20 * 1024 * $mb, 'crosspost_rules' => null, 'analytics_days' => 365, 'ai_credits' => 500],
                'features' => ['slots', 'crosspost', 'approvals', 'guest_links', 'api', 'analytics_export'],
                'prices' => ['month' => 99000, 'year' => 990000],
            ],
            'agency' => [
                'name' => 'Агентство',
                'sort' => 3,
                'limits' => ['channels' => 100, 'posts_per_month' => null, 'workspaces' => null, 'members' => 20, 'storage_bytes' => 100 * 1024 * $mb, 'crosspost_rules' => null, 'analytics_days' => 365, 'ai_credits' => 2000],
                'features' => ['slots', 'crosspost', 'approvals', 'guest_links', 'api', 'analytics_export', 'client_reports', 'white_label'],
                'prices' => ['month' => 299000, 'year' => 2990000],
            ],
        ],
    ];
};
