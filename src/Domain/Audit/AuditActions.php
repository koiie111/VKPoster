<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Human-readable names of audit actions for the workspace journal. Actions missing from the map are
 * shown by their technical name, so a new event is never hidden.
 */
final class AuditActions
{
    /** @var array<string, string> */
    private const LABELS = [
        'workspace.created' => 'Создано пространство',
        'workspace.updated' => 'Изменены настройки пространства',
        'workspace.deleted' => 'Пространство удалено',
        'workspace.ownership_transferred' => 'Владение передано',
        'member.invited' => 'Приглашён участник',
        'member.invitation_revoked' => 'Приглашение отозвано',
        'member.joined' => 'Участник присоединился',
        'member.role_changed' => 'Изменена роль',
        'member.removed' => 'Участник исключён',
        'member.left' => 'Участник вышел',
        'channel.connected' => 'Подключён канал',
        'channel.disconnected' => 'Отключён канал',
        'post.published' => 'Опубликован пост',
        'post.scheduled' => 'Запланирован пост',
        'billing.payment' => 'Платёж',
    ];

    /** @var array<string, string> filter value => label of the group of actions */
    private const GROUPS = [
        'workspace' => 'Пространство',
        'member' => 'Команда',
        'channel' => 'Каналы',
        'post' => 'Публикации',
        'billing' => 'Оплата',
    ];

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? $action;
    }

    /**
     * @return array<string, string>
     */
    public static function groups(): array
    {
        return self::GROUPS;
    }
}
