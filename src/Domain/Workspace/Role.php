<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

/**
 * Role of a member inside one workspace. `rank()` orders roles by power; it is used only for the
 * "who may manage whom" rule, never to decide a plain permission (that is the matrix in `config/permissions.php`).
 */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Author = 'author';
    case Viewer = 'viewer';
    case Client = 'client';

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 60,
            self::Admin => 50,
            self::Editor => 40,
            self::Author => 30,
            self::Viewer => 20,
            self::Client => 10,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Владелец',
            self::Admin => 'Администратор',
            self::Editor => 'Редактор',
            self::Author => 'Автор',
            self::Viewer => 'Наблюдатель',
            self::Client => 'Клиент',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Всё, включая оплату и удаление пространства.',
            self::Admin => 'Команда, каналы и настройки. Публикует и планирует посты.',
            self::Editor => 'Публикует и планирует посты, согласует чужие.',
            self::Author => 'Пишет черновики и отправляет их на согласование.',
            self::Viewer => 'Смотрит календарь и аналитику.',
            self::Client => 'Видит только назначенные каналы и согласует их посты.',
        };
    }

    /**
     * Roles that can be handed out through an invitation or a role change (ownership moves only by transfer).
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return [self::Admin, self::Editor, self::Author, self::Viewer, self::Client];
    }

    public static function tryFromString(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
