<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

/** Separate immutable dispatch time from bookkeeping; existing in-flight attempts receive their last durable timestamp. */
return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute('ALTER TABLE oauth_refresh_attempts ADD dispatch_started_at DATETIME(6) NULL AFTER response_enc');
        $db->execute("UPDATE oauth_refresh_attempts SET dispatch_started_at=updated_at WHERE state IN ('dispatched','response_saved','recovery_required','completed')");
        // Adopt only the exact old recovery error on its still-active attempt; never relabel unrelated channel errors.
        $message = 'Обновление доступа прервалось. Переподключите канал; старый refresh token повторно не используется.';
        $db->execute("UPDATE channels c JOIN oauth_refresh_attempts a ON a.active_credential_id=c.credential_id
            SET c.last_error=CONCAT(?, ' [OAuth recovery ', a.id, ']')
            WHERE c.status='error' AND c.last_error=? AND a.state IN ('dispatched','response_saved','recovery_required')", [$message, $message]);
    }

    public function down(Connection $db): void
    {
        if ((int) ($db->select("SELECT COUNT(*) AS n FROM oauth_refresh_attempts WHERE state IN ('dispatched','response_saved','recovery_required')")[0]['n'] ?? 0) > 0) {
            throw new RuntimeException('Resolve pending OAuth recovery before rollback.');
        }
        $db->execute('ALTER TABLE oauth_refresh_attempts DROP COLUMN dispatch_started_at');
    }
};
