<?php

declare(strict_types=1);

namespace App\Domain\Admin;

/**
 * What a member of staff may do in the back office. The superadmin is the owner (`users.is_superadmin`); the other roles are rows of
 * `staff_members`. What each role may touch is the matrix in `config/admin_permissions.php`.
 */
enum StaffRole: string
{
    case Superadmin = 'superadmin';
    case Finance = 'finance';
    case Support = 'support';
    case Content = 'content';
    case Analyst = 'analyst';

    public function label(): string
    {
        return match ($this) {
            self::Superadmin => 'Владелец',
            self::Finance => 'Финансы',
            self::Support => 'Поддержка',
            self::Content => 'Контент',
            self::Analyst => 'Аналитик',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Superadmin => 'Видит и меняет всё, управляет сотрудниками.',
            self::Finance => 'Платежи, возвраты, тарифы и выгрузки для бухгалтерии.',
            self::Support => 'Пользователи, обращения и вход под пользователем, без денег.',
            self::Content => 'Тексты сайта, объявления, рассылки и шаблоны писем.',
            self::Analyst => 'Только просмотр дашбордов и статистики.',
        };
    }
}
