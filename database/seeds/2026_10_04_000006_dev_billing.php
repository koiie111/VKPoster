<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Seeder;

/**
 * Billing demo for the demo workspace (idempotent): a paid month of Pro through the test provider, a saved test card, one paid invoice
 * and two more payments (one waiting for the customer, one declined) with fixed ids, so the billing pages and the return page
 * can be looked at in every state: `/w/<workspace>/billing/return?payment=01JZZZZZZZZZZZZZZZZZZZZZP0` (paid), `...P1` (pending), `...P2` (declined).
 * Nothing here talks to a payment provider.
 */
return new class () implements Seeder {
    public function run(Connection $db): void
    {
        $workspace = $db->select('SELECT id, owner_id FROM workspaces WHERE name = ? LIMIT 1', ['Кофейня «Зерно»']);
        $pro = $db->select('SELECT id FROM plans WHERE code = ?', ['pro']);
        if ($workspace === [] || $pro === [] || $db->select('SELECT id FROM subscriptions WHERE workspace_id = ?', [$workspace[0]['id']]) !== []) {
            return;
        }
        $workspaceId = (int) $workspace[0]['id'];
        $planId = (int) $pro[0]['id'];
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $utc);
        $fmt = static fn (DateTimeImmutable $t): string => $t->format('Y-m-d H:i:s.u');
        $start = $now->modify('-10 days');
        $end = $start->modify('+1 month');
        $owner = $db->select('SELECT email FROM users WHERE id = ?', [$workspace[0]['owner_id']]);

        $methodId = (int) $db->table('payment_methods')->insert([
            'public_id' => (string) new Symfony\Component\Uid\Ulid(),
            'workspace_id' => $workspaceId,
            'provider' => 'fake',
            'provider_method_id' => 'fake-card-ok:seed',
            'title' => 'Тестовая карта •• 4242',
            'status' => 'active',
            'created_at' => $fmt($start),
        ]);
        $subscriptionId = (int) $db->table('subscriptions')->insert([
            'public_id' => (string) new Symfony\Component\Uid\Ulid(),
            'workspace_id' => $workspaceId,
            'plan_id' => $planId,
            'status' => 'active',
            'period' => 'month',
            'currency' => 'RUB',
            'price_amount' => 99000,
            'current_period_start' => $fmt($start),
            'current_period_end' => $fmt($end),
            'payment_method_id' => $methodId,
            'next_renewal_attempt_at' => $fmt($end->modify('-3 days')),
            'created_at' => $fmt($start),
            'updated_at' => $fmt($start),
        ]);
        $db->table('workspaces')->where('id', '=', $workspaceId)->update(['plan_id' => $planId]);
        $invoiceId = (int) $db->table('invoices')->insert([
            'public_id' => (string) new Symfony\Component\Uid\Ulid(),
            'number' => 'EZ-DEMO01',
            'workspace_id' => $workspaceId,
            'subscription_id' => $subscriptionId,
            'plan_id' => $planId,
            'period' => 'month',
            'kind' => 'new',
            'amount' => 99000,
            'list_price' => 99000,
            'currency' => 'RUB',
            'status' => 'paid',
            'description' => 'Тариф «Про» за месяц',
            'customer_email' => (string) ($owner[0]['email'] ?? 'demo@ezposter.local'),
            'period_start' => $fmt($start),
            'period_end' => $fmt($end),
            'created_at' => $fmt($start),
            'paid_at' => $fmt($start),
            'expires_at' => $fmt($start->modify('+1 day')),
        ]);
        $payment = static fn (string $id, int $invoice, string $status, string $providerStatus, ?string $url) => $db->table('payments')->insert([
            'public_id' => $id,
            'invoice_id' => $invoice,
            'workspace_id' => $workspaceId,
            'provider' => 'fake',
            'provider_payment_id' => 'fake_' . $id,
            'status' => $status,
            'provider_status' => $providerStatus,
            'amount' => 99000,
            'currency' => 'RUB',
            'payment_method_id' => $status === 'succeeded' ? $methodId : null,
            'save_method' => 1,
            'confirmation_url' => $url,
            'created_at' => $fmt($now),
            'updated_at' => $fmt($now),
        ]);
        $payment('01JZZZZZZZZZZZZZZZZZZZZZP0', $invoiceId, 'succeeded', 'succeeded', null);

        // A bill the customer has not paid yet, and one that was declined (its invoice was replaced).
        $open = static fn (string $number, string $status, string $kind) => (int) $db->table('invoices')->insert([
            'public_id' => (string) new Symfony\Component\Uid\Ulid(),
            'number' => $number,
            'workspace_id' => $workspaceId,
            'subscription_id' => $subscriptionId,
            'plan_id' => $planId,
            'period' => 'month',
            'kind' => $kind,
            'amount' => 99000,
            'list_price' => 99000,
            'currency' => 'RUB',
            'status' => $status,
            'description' => 'Продление тарифа «Про» за месяц',
            'customer_email' => (string) ($owner[0]['email'] ?? 'demo@ezposter.local'),
            'period_start' => $fmt($end),
            'period_end' => $fmt($end->modify('+1 month')),
            'created_at' => $fmt($now),
            'expires_at' => $fmt($now->modify('+7 days')),
        ]);
        $payment('01JZZZZZZZZZZZZZZZZZZZZZP1', $open('EZ-DEMO02', 'open', 'renewal'), 'pending', 'pending', '/dev/billing/pay/01JZZZZZZZZZZZZZZZZZZZZZP1?return=%2Fapp');
        $payment('01JZZZZZZZZZZZZZZZZZZZZZP2', $open('EZ-DEMO03', 'void', 'renewal'), 'failed', 'canceled', null);
    }
};
