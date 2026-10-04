<?php

declare(strict_types=1);

use App\Domain\Billing\PlanCatalog;
use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;
use App\Kernel\Env;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $table = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // The price list. `limits_json` / `features_json` are what `Entitlements` reads; a NULL limit means "unlimited".
        $db->execute('CREATE TABLE plans (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(32) NOT NULL,
            name VARCHAR(60) NOT NULL,
            sort SMALLINT NOT NULL DEFAULT 0,
            limits_json TEXT NOT NULL,
            features_json TEXT NOT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_plans_code (code)
        )' . $table);

        // Money is an integer amount of the smallest unit (kopecks) plus a currency code; one price per plan, period and currency.
        $db->execute('CREATE TABLE plan_prices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            plan_id BIGINT UNSIGNED NOT NULL,
            period VARCHAR(8) NOT NULL,
            currency CHAR(3) NOT NULL,
            amount BIGINT UNSIGNED NOT NULL,
            UNIQUE KEY uq_plan_prices (plan_id, period, currency),
            CONSTRAINT fk_plan_prices_plan FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE CASCADE
        )' . $table);

        // The source of truth about what a workspace has paid for. One row per workspace; the history is in invoices and the audit log.
        // The Free plan is a subscription without period dates. `price_amount` is what the current period cost in full (the credit for an upgrade).
        $db->execute('CREATE TABLE subscriptions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            plan_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(16) NOT NULL,
            period VARCHAR(8) NULL,
            currency CHAR(3) NOT NULL DEFAULT \'RUB\',
            price_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
            current_period_start DATETIME(6) NULL,
            current_period_end DATETIME(6) NULL,
            trial_ends_at DATETIME(6) NULL,
            trial_reminded_at DATETIME(6) NULL,
            cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
            pending_plan_id BIGINT UNSIGNED NULL,
            pending_period VARCHAR(8) NULL,
            payment_method_id BIGINT UNSIGNED NULL,
            renewal_attempts SMALLINT NOT NULL DEFAULT 0,
            first_attempt_at DATETIME(6) NULL,
            next_renewal_attempt_at DATETIME(6) NULL,
            last_failure VARCHAR(255) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_subscriptions_public (public_id),
            UNIQUE KEY uq_subscriptions_workspace (workspace_id),
            KEY idx_subscriptions_renewal (next_renewal_attempt_at),
            KEY idx_subscriptions_period_end (status, current_period_end),
            KEY idx_subscriptions_trial (status, trial_ends_at),
            CONSTRAINT fk_subscriptions_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT fk_subscriptions_plan FOREIGN KEY (plan_id) REFERENCES plans (id)
        )' . $table);

        // A way to pay that the customer allowed us to reuse (a card saved at the provider). Only references are kept: the card number never reaches us.
        $db->execute('CREATE TABLE payment_methods (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(20) NOT NULL,
            provider_method_id VARCHAR(128) NOT NULL,
            customer_key VARCHAR(64) NULL,
            title VARCHAR(100) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT \'active\',
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_payment_methods_public (public_id),
            UNIQUE KEY uq_payment_methods_provider (provider, provider_method_id),
            KEY idx_payment_methods_workspace (workspace_id),
            CONSTRAINT fk_payment_methods_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        )' . $table);
        $db->execute('ALTER TABLE subscriptions ADD CONSTRAINT fk_subscriptions_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (id) ON DELETE SET NULL');

        // What the customer is asked to pay. `kind`: new | upgrade | renewal. `list_price` is the full price of the plan for the period
        // (the invoice `amount` is lower for a prorated upgrade). An open invoice is paid, voided or expires.
        $db->execute('CREATE TABLE invoices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            number VARCHAR(32) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            subscription_id BIGINT UNSIGNED NULL,
            plan_id BIGINT UNSIGNED NOT NULL,
            period VARCHAR(8) NOT NULL,
            kind VARCHAR(12) NOT NULL,
            amount BIGINT UNSIGNED NOT NULL,
            list_price BIGINT UNSIGNED NOT NULL,
            currency CHAR(3) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT \'open\',
            description VARCHAR(255) NOT NULL,
            customer_email VARCHAR(254) NOT NULL,
            period_start DATETIME(6) NOT NULL,
            period_end DATETIME(6) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            paid_at DATETIME(6) NULL,
            expires_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_invoices_public (public_id),
            UNIQUE KEY uq_invoices_number (number),
            KEY idx_invoices_workspace (workspace_id, created_at),
            KEY idx_invoices_open (status, expires_at),
            CONSTRAINT fk_invoices_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT fk_invoices_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE SET NULL,
            CONSTRAINT fk_invoices_plan FOREIGN KEY (plan_id) REFERENCES plans (id)
        )' . $table);

        // One attempt to collect an invoice through one provider. `provider_status` is the last raw status the provider reported.
        $db->execute('CREATE TABLE payments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            invoice_id BIGINT UNSIGNED NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(20) NOT NULL,
            provider_payment_id VARCHAR(128) NULL,
            status VARCHAR(12) NOT NULL DEFAULT \'pending\',
            provider_status VARCHAR(40) NULL,
            amount BIGINT UNSIGNED NOT NULL,
            currency CHAR(3) NOT NULL,
            refunded_amount BIGINT UNSIGNED NOT NULL DEFAULT 0,
            payment_method_id BIGINT UNSIGNED NULL,
            save_method TINYINT(1) NOT NULL DEFAULT 0,
            confirmation_url VARCHAR(1000) NULL,
            error_message VARCHAR(255) NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_payments_public (public_id),
            UNIQUE KEY uq_payments_provider (provider, provider_payment_id),
            KEY idx_payments_invoice (invoice_id),
            KEY idx_payments_workspace (workspace_id, created_at),
            CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
            CONSTRAINT fk_payments_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods (id) ON DELETE SET NULL
        )' . $table);

        // Every notification a provider sends is written here first. UNIQUE(provider, event_id) makes a repeated delivery a no-op.
        $db->execute('CREATE TABLE webhook_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(20) NOT NULL,
            event_id VARCHAR(190) NOT NULL,
            type VARCHAR(64) NOT NULL,
            payment_ref VARCHAR(128) NULL,
            outcome VARCHAR(16) NOT NULL DEFAULT \'received\',
            detail VARCHAR(255) NULL,
            received_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_webhook_events (provider, event_id),
            KEY idx_webhook_events_received (received_at)
        )' . $table);

        // Double-entry journal: the entries of one transaction (`txn_id`) always sum to zero, an account balance is the sum of its entries.
        // Debits are positive, credits negative.
        $db->execute('CREATE TABLE ledger_accounts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NOT NULL,
            name VARCHAR(120) NOT NULL,
            kind VARCHAR(12) NOT NULL,
            currency CHAR(3) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_ledger_accounts_code (code)
        )' . $table);
        $db->execute('CREATE TABLE ledger_entries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            txn_id CHAR(26) NOT NULL,
            account_id BIGINT UNSIGNED NOT NULL,
            amount BIGINT NOT NULL,
            currency CHAR(3) NOT NULL,
            ref_type VARCHAR(20) NOT NULL,
            ref_id VARCHAR(64) NOT NULL,
            memo VARCHAR(255) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            KEY idx_ledger_entries_txn (txn_id),
            KEY idx_ledger_entries_account (account_id, created_at),
            KEY idx_ledger_entries_ref (ref_type, ref_id),
            CONSTRAINT fk_ledger_entries_account FOREIGN KEY (account_id) REFERENCES ledger_accounts (id)
        )' . $table);

        // Metered usage per workspace and period (`2026-10` for monthly metrics): AI credits and the like. Posts are counted live.
        $db->execute('CREATE TABLE usage_counters (
            workspace_id BIGINT UNSIGNED NOT NULL,
            metric VARCHAR(40) NOT NULL,
            period_key VARCHAR(16) NOT NULL,
            value BIGINT NOT NULL DEFAULT 0,
            updated_at DATETIME(6) NOT NULL,
            PRIMARY KEY (workspace_id, metric, period_key),
            CONSTRAINT fk_usage_counters_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
        )' . $table);

        $db->execute('ALTER TABLE workspaces ADD CONSTRAINT fk_workspaces_plan FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE SET NULL');

        // The starting catalogue comes from config/billing.php; the table is the truth from here on.
        $config = (require dirname(__DIR__, 2) . '/config/billing.php')(new Env([]));
        PlanCatalog::insertMissing($db, $config['catalog'], (string) $config['currency']);
    }

    public function down(Connection $db): void
    {
        $db->execute('ALTER TABLE workspaces DROP FOREIGN KEY fk_workspaces_plan');
        $db->execute('UPDATE workspaces SET plan_id = NULL');
        foreach (['usage_counters', 'ledger_entries', 'ledger_accounts', 'webhook_events', 'payments', 'invoices'] as $name) {
            $db->execute('DROP TABLE IF EXISTS ' . $name);
        }
        $db->execute('ALTER TABLE subscriptions DROP FOREIGN KEY fk_subscriptions_method');
        foreach (['payment_methods', 'subscriptions', 'plan_prices', 'plans'] as $name) {
            $db->execute('DROP TABLE IF EXISTS ' . $name);
        }
    }
};
