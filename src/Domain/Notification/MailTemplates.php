<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Kernel\View\View;
use App\Support\Clock;
use App\Support\DbTime;
use App\Support\Markdown;

/**
 * Editable texts of the system emails. Every template of `templates/emails/` has its text written in code; the owner can replace the
 * subject and the body of a template in the admin area (Markdown with `{placeholders}` such as `{name}` and `{link}`) and see a preview,
 * and can go back to the written text at any time. An override that mentions a placeholder the template does not know is refused, so a
 * typo cannot send a letter with "{lnk}" in it. Values are substituted as plain text: nothing from them is read as markup.
 */
final class MailTemplates
{
    /** @var array<string, array{title: string, placeholders: array<string, string>}> template => description; the order is the order of the list */
    public const EDITABLE = [
        'verify_email' => ['title' => 'Подтверждение почты', 'placeholders' => ['name' => 'Имя', 'link' => 'Ссылка подтверждения']],
        'password_reset' => ['title' => 'Сброс пароля', 'placeholders' => ['link' => 'Ссылка для нового пароля']],
        'password_changed' => ['title' => 'Пароль изменён', 'placeholders' => ['name' => 'Имя', 'reset_link' => 'Ссылка, если это были не вы']],
        'account_exists' => ['title' => 'Аккаунт уже есть', 'placeholders' => ['login_link' => 'Ссылка на вход', 'reset_link' => 'Ссылка на сброс пароля']],
        'workspace_invitation' => ['title' => 'Приглашение в пространство', 'placeholders' => ['workspace' => 'Название пространства', 'inviter' => 'Кто пригласил', 'role' => 'Роль', 'days' => 'Сколько дней действует', 'link' => 'Ссылка-приглашение']],
        'billing_trial_ending' => ['title' => 'Пробный период заканчивается', 'placeholders' => ['until' => 'До какой даты', 'link' => 'Ссылка на тарифы']],
        'billing_failed' => ['title' => 'Не удалось списать оплату', 'placeholders' => ['plan' => 'Тариф', 'until' => 'Оплачено до', 'grace_until' => 'Отсрочка до', 'next' => 'Следующая попытка', 'link' => 'Ссылка на оплату']],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly View $view,
        private readonly Config $config,
    ) {
    }

    /**
     * What is saved for a template (both parts may be null).
     *
     * @return array{subject: ?string, body_md: ?string}
     */
    public function override(string $template): array
    {
        $row = $this->db->select('SELECT subject, body_md FROM mail_templates WHERE template = ?', [$template])[0] ?? null;

        return ['subject' => is_string($row['subject'] ?? null) && $row['subject'] !== '' ? $row['subject'] : null, 'body_md' => is_string($row['body_md'] ?? null) && $row['body_md'] !== '' ? $row['body_md'] : null];
    }

    /**
     * @return list<string> problems in the editor's words; empty when saved
     */
    public function save(string $template, string $subject, string $body, ?int $actorId): array
    {
        if (!isset(self::EDITABLE[$template])) {
            return ['Такого письма нет.'];
        }
        $errors = [];
        if (mb_strlen($subject) > 200 || mb_strlen($body) > 5000) {
            $errors[] = 'Слишком длинный текст: тема — до 200 символов, письмо — до 5000.';
        }
        $known = array_keys(self::EDITABLE[$template]['placeholders']);
        preg_match_all('/\{([a-z_]+)\}/', $subject . "\n" . $body, $found);
        foreach (array_unique($found[1]) as $name) {
            if (!in_array($name, $known, true)) {
                $errors[] = 'В тексте есть {' . $name . '}, а такой подстановки у этого письма нет. Доступны: ' . implode(', ', array_map(static fn (string $k): string => '{' . $k . '}', $known)) . '.';
            }
        }
        if ($body !== '' && isset(self::EDITABLE[$template]['placeholders']['link']) && !str_contains($body, '{link}')) {
            $errors[] = 'В письме нужна ссылка: добавьте {link}, иначе человек не сможет сделать то, ради чего оно отправлено.';
        }
        if ($errors !== []) {
            return $errors;
        }
        if ($subject === '' && $body === '') {
            $this->reset($template);

            return [];
        }
        $this->db->execute(
            'INSERT INTO mail_templates (template, subject, body_md, updated_by, updated_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE subject = VALUES(subject), body_md = VALUES(body_md), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$template, $subject === '' ? null : $subject, $body === '' ? null : $body, $actorId, DbTime::format($this->clock->now())],
        );

        return [];
    }

    public function reset(string $template): void
    {
        $this->db->execute('DELETE FROM mail_templates WHERE template = ?', [$template]);
    }

    /**
     * The subject that is really sent: the saved one with the values put in, or the one the code gave.
     *
     * @param array<string, mixed> $data
     */
    public function subject(string $template, string $default, array $data): string
    {
        $subject = $this->override($template)['subject'];

        return $subject === null ? $default : self::fill($subject, $data);
    }

    /**
     * The edited body as `[html, text]`, or null when the template has none (the written template is used).
     *
     * @param array<string, mixed> $data
     * @return array{html: string, text: string}|null
     */
    public function render(string $template, array $data, string $subject): ?array
    {
        $body = $this->override($template)['body_md'];
        if ($body === null) {
            return null;
        }
        $filled = self::fill($body, $data);

        return [
            'html' => $this->view->render('emails/custom.html.twig', $data + ['subject' => $subject, 'body_html' => Markdown::toHtml($filled)]),
            'text' => $filled . "\n\n" . $this->config->string('app.name') . "\n",
        ];
    }

    /**
     * Put the values into `{placeholders}`; a value is plain text, a placeholder without a value stays visible.
     *
     * @param array<string, mixed> $data
     */
    public static function fill(string $text, array $data): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($data): string {
            $value = $data[$m[1]] ?? null;

            return is_scalar($value) ? (string) $value : $m[0];
        }, $text);
    }

    /**
     * Sample values for the preview: the placeholders of a template filled with obvious examples.
     *
     * @return array<string, string>
     */
    public static function sample(string $template): array
    {
        $values = ['name' => 'Анна', 'link' => 'https://example.com/primer', 'reset_link' => 'https://example.com/reset', 'login_link' => 'https://example.com/login', 'workspace' => 'Кофейня «Зерно»', 'inviter' => 'Ольга', 'role' => 'редактор', 'days' => '7', 'until' => '15 октября', 'grace_until' => '18 октября', 'next' => '12 октября', 'plan' => 'Про'];

        return array_intersect_key($values, self::EDITABLE[$template]['placeholders'] ?? []);
    }
}
