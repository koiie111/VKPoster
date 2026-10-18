<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $suffix = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        // Promo codes. `kind`: percent (value = 1..100), amount (value = minor units off, in `currency`), days (value = free days of the one plan in
        // `plan_codes_json`, no payment). `plan_codes_json` NULL = any plan. A code is used once per person (`user_id` of the redemption).
        $db->execute('CREATE TABLE promo_codes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(32) NOT NULL,
            kind VARCHAR(8) NOT NULL,
            value BIGINT UNSIGNED NOT NULL,
            currency CHAR(3) NULL,
            plan_codes_json JSON NULL,
            first_payment_only BOOLEAN NOT NULL DEFAULT TRUE,
            max_redemptions INT UNSIGNED NULL,
            valid_from DATETIME(6) NULL,
            valid_until DATETIME(6) NULL,
            active BOOLEAN NOT NULL DEFAULT TRUE,
            note VARCHAR(200) NOT NULL DEFAULT \'\',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_promo_code (code)
        )' . $suffix);
        // One row per use. A redemption of an invoice that was never paid stops counting once the invoice is void or expired.
        $db->execute('CREATE TABLE promo_redemptions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            promo_id BIGINT UNSIGNED NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            invoice_id BIGINT UNSIGNED NULL,
            discount BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_promo_redemption_invoice (invoice_id),
            KEY idx_promo_redemptions_promo (promo_id),
            KEY idx_promo_redemptions_user (promo_id, user_id),
            CONSTRAINT fk_promo_redemptions_promo FOREIGN KEY (promo_id) REFERENCES promo_codes (id) ON DELETE CASCADE,
            CONSTRAINT fk_promo_redemptions_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE SET NULL
        )' . $suffix);

        // Add-on packages (extra channels, people, storage, AI credits), sold at the owner's prices in any currency: `prices_json` = {"RUB": minor, "USD": minor}.
        // `quantity` is in the unit of the kind (storage: bytes). Limits packages last `days` days from payment; credits are added to the wallet for good.
        $db->execute('CREATE TABLE addon_packages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(32) NOT NULL,
            name VARCHAR(80) NOT NULL,
            kind VARCHAR(16) NOT NULL,
            quantity BIGINT UNSIGNED NOT NULL,
            days INT UNSIGNED NOT NULL DEFAULT 30,
            prices_json JSON NOT NULL,
            active BOOLEAN NOT NULL DEFAULT TRUE,
            sort INT NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_addon_code (code)
        )' . $suffix);
        $db->execute('CREATE TABLE workspace_addons (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            workspace_id BIGINT UNSIGNED NOT NULL,
            package_id BIGINT UNSIGNED NULL,
            kind VARCHAR(16) NOT NULL,
            quantity BIGINT UNSIGNED NOT NULL,
            invoice_id BIGINT UNSIGNED NULL,
            valid_until DATETIME(6) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_workspace_addon_invoice (invoice_id),
            KEY idx_workspace_addons (workspace_id, valid_until),
            CONSTRAINT fk_workspace_addons_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT fk_workspace_addons_package FOREIGN KEY (package_id) REFERENCES addon_packages (id) ON DELETE SET NULL,
            CONSTRAINT fk_workspace_addons_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE SET NULL
        )' . $suffix);

        $db->execute('ALTER TABLE invoices
            ADD COLUMN discount BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER list_price,
            ADD COLUMN promo_code_id BIGINT UNSIGNED NULL AFTER discount,
            ADD COLUMN addon_package_id BIGINT UNSIGNED NULL AFTER promo_code_id,
            ADD CONSTRAINT fk_invoices_promo FOREIGN KEY (promo_code_id) REFERENCES promo_codes (id) ON DELETE SET NULL,
            ADD CONSTRAINT fk_invoices_addon FOREIGN KEY (addon_package_id) REFERENCES addon_packages (id) ON DELETE SET NULL');

        // Referral programme. A person has one code; `referrals` links an invited person to who invited them (once, forever). `status`:
        // active | flagged (suspected self-invite: no bonus until staff decide) | rejected. Bonuses and withdrawals live in the ledger
        // (`referral:user:<id>`); `referral_bonuses` keeps one row per bonus for the screens and for reversal on refund.
        $db->execute('CREATE TABLE referral_codes (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            code VARCHAR(12) NOT NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_referral_code (code),
            CONSTRAINT fk_referral_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )' . $suffix);
        $db->execute('CREATE TABLE referrals (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            referrer_id BIGINT UNSIGNED NOT NULL,
            referred_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT \'active\',
            reason VARCHAR(120) NULL,
            ip_hash CHAR(64) NULL,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_referred (referred_id),
            KEY idx_referrals_referrer (referrer_id, status),
            CONSTRAINT fk_referrals_referrer FOREIGN KEY (referrer_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_referrals_referred FOREIGN KEY (referred_id) REFERENCES users (id) ON DELETE CASCADE
        )' . $suffix);
        $db->execute('CREATE TABLE referral_bonuses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            referral_id BIGINT UNSIGNED NOT NULL,
            payment_id BIGINT UNSIGNED NOT NULL,
            amount BIGINT UNSIGNED NOT NULL,
            reversed BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_referral_bonus_payment (payment_id),
            KEY idx_referral_bonuses_referral (referral_id),
            CONSTRAINT fk_referral_bonuses_referral FOREIGN KEY (referral_id) REFERENCES referrals (id) ON DELETE CASCADE,
            CONSTRAINT fk_referral_bonuses_payment FOREIGN KEY (payment_id) REFERENCES payments (id) ON DELETE CASCADE
        )' . $suffix);
        // A request to withdraw the balance, handled by hand: requested -> paid | rejected. The money is taken from the balance when it is asked for.
        $db->execute('CREATE TABLE referral_payouts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            amount BIGINT UNSIGNED NOT NULL,
            details VARCHAR(500) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT \'requested\',
            staff_note VARCHAR(300) NULL,
            handled_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            handled_at DATETIME(6) NULL,
            UNIQUE KEY uq_referral_payout_public (public_id),
            KEY idx_referral_payouts_status (status, id),
            CONSTRAINT fk_referral_payouts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        )' . $suffix);
    }

    public function down(Connection $db): void
    {
        $db->execute('ALTER TABLE invoices DROP FOREIGN KEY fk_invoices_promo, DROP FOREIGN KEY fk_invoices_addon, DROP COLUMN discount, DROP COLUMN promo_code_id, DROP COLUMN addon_package_id');
        foreach (['referral_payouts', 'referral_bonuses', 'referrals', 'referral_codes', 'workspace_addons', 'addon_packages', 'promo_redemptions', 'promo_codes'] as $table) {
            $db->execute('DROP TABLE ' . $table);
        }
    }
};
