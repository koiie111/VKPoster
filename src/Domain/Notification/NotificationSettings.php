<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Kernel\Database\Connection;

/**
 * Who wants which message by which way (email, Telegram). Rows exist only where the person changed something; the rest
 * follows `NotificationType::defaults()`.
 */
final class NotificationSettings
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array<string, array{email: bool, telegram: bool}> keyed by type value, complete
     */
    public function forUser(int $userId): array
    {
        $result = [];
        foreach (NotificationType::cases() as $type) {
            $result[$type->value] = $type->defaults();
        }
        foreach ($this->db->table('notification_settings')->where('user_id', '=', $userId)->get() as $row) {
            if (NotificationType::tryFrom((string) $row['type']) !== null) {
                $result[(string) $row['type']] = ['email' => (int) $row['email'] === 1, 'telegram' => (int) $row['telegram'] === 1];
            }
        }

        return $result;
    }

    /**
     * @param array<string, array{email: bool, telegram: bool}> $choices keyed by type value; unknown types are ignored
     */
    public function save(int $userId, array $choices): void
    {
        $this->db->transaction(function (Connection $db) use ($userId, $choices): void {
            foreach (NotificationType::cases() as $type) {
                if (!isset($choices[$type->value])) {
                    continue;
                }
                $db->execute(
                    'INSERT INTO notification_settings (user_id, type, email, telegram) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE email = VALUES(email), telegram = VALUES(telegram)',
                    [$userId, $type->value, $choices[$type->value]['email'] ? 1 : 0, $choices[$type->value]['telegram'] ? 1 : 0],
                );
            }
        });
    }
}
