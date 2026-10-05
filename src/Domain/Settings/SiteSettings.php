<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Kernel\Config;

/**
 * The site-wide switches and texts the owner edits in the admin area, with their types and defaults: maintenance mode, who may register,
 * contact details and requisites, global limits. Everything is a plain value in `app_settings`; secrets (keys, tokens, passwords) never are,
 * they stay in the environment.
 */
final class SiteSettings
{
    public const REGISTRATION_MODES = ['open' => 'Открыта для всех', 'invite' => 'Только по приглашениям', 'closed' => 'Закрыта'];

    public function __construct(private readonly Settings $settings, private readonly Config $config)
    {
    }

    public function maintenanceOn(): bool
    {
        return $this->settings->get('site.maintenance') === true;
    }

    public function maintenanceMessage(): string
    {
        $text = $this->settings->get('site.maintenance_message');

        return is_string($text) && trim($text) !== '' ? trim($text) : 'Мы обновляем сервис. Это займёт немного времени, посты по расписанию продолжают выходить.';
    }

    /**
     * @return 'open'|'invite'|'closed'
     */
    public function registrationMode(): string
    {
        $mode = $this->settings->get('site.registration');

        return $mode === 'invite' ? 'invite' : ($mode === 'closed' ? 'closed' : 'open');
    }

    /**
     * Where people write for help: the setting, or the address from the environment.
     */
    public function supportEmail(): string
    {
        $value = $this->settings->get('site.support_email');

        return is_string($value) && $value !== '' ? $value : $this->config->string('mail.support');
    }

    public function supportTelegram(): string
    {
        $value = $this->settings->get('site.support_telegram');

        return is_string($value) ? $value : '';
    }

    public function requisites(): string
    {
        $value = $this->settings->get('site.requisites');

        return is_string($value) ? $value : '';
    }

    /**
     * How many workspaces one person may own (0 = no limit).
     */
    public function maxWorkspacesPerUser(): int
    {
        $value = $this->settings->get('limits.max_workspaces_per_user');

        return is_int($value) && $value > 0 ? $value : 0;
    }

    /**
     * Save the form of the settings page. Returns problems in the owner's words; empty when saved.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public function save(array $input, ?int $actorId): array
    {
        $errors = [];
        $mode = (string) ($input['registration'] ?? 'open');
        if (!isset(self::REGISTRATION_MODES[$mode])) {
            $errors[] = 'Выберите, кто может регистрироваться.';
        }
        $email = trim((string) ($input['support_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Почта поддержки написана с ошибкой.';
        }
        $telegram = trim((string) ($input['support_telegram'] ?? ''));
        if ($telegram !== '' && preg_match('/^@?[A-Za-z][A-Za-z0-9_]{3,31}$/', $telegram) !== 1) {
            $errors[] = 'Telegram поддержки — имя вида @support_bot.';
        }
        $max = trim((string) ($input['max_workspaces'] ?? '0'));
        if (preg_match('/^\d{1,4}$/', $max) !== 1) {
            $errors[] = 'Предел пространств на человека — целое число, 0 значит «без ограничений».';
        }
        $message = trim((string) ($input['maintenance_message'] ?? ''));
        if (mb_strlen($message) > 500 || mb_strlen((string) ($input['requisites'] ?? '')) > 3000) {
            $errors[] = 'Слишком длинный текст: сообщение о работах — до 500 символов, реквизиты — до 3000.';
        }
        if ($errors !== []) {
            return $errors;
        }
        $this->settings->set('site.maintenance', ($input['maintenance'] ?? '') === '1', $actorId);
        $this->settings->set('site.maintenance_message', $message, $actorId);
        $this->settings->set('site.registration', $mode, $actorId);
        $this->settings->set('site.support_email', $email, $actorId);
        $this->settings->set('site.support_telegram', ltrim($telegram, '@') === '' ? '' : '@' . ltrim($telegram, '@'), $actorId);
        $this->settings->set('site.requisites', trim((string) ($input['requisites'] ?? '')), $actorId);
        $this->settings->set('limits.max_workspaces_per_user', (int) $max, $actorId);

        return [];
    }
}
