<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Notification\MailComposer;
use App\Domain\User\User;
use App\Kernel\Config;
use App\Kernel\Http\Router;

/**
 * Account emails (confirmation, reset, security notices). Builds absolute links from `APP_URL` and
 * hands the message to the queue; texts live in `templates/emails/`.
 */
final class AuthMailer
{
    public function __construct(
        private readonly MailComposer $mail,
        private readonly Router $router,
        private readonly Config $config,
    ) {
    }

    public function verifyEmail(string $email, string $name, string $token): void
    {
        $this->mail->send($email, 'Подтвердите адрес почты', 'verify_email', [
            'name' => $name,
            'link' => $this->absolute('auth.verify.show', ['token' => $token]),
        ]);
    }

    /**
     * Sent instead of a confirmation when someone signs up with an address that already has an account.
     */
    public function accountExists(string $email): void
    {
        $this->mail->send($email, 'У вас уже есть аккаунт', 'account_exists', [
            'login_link' => $this->absolute('login'),
            'reset_link' => $this->absolute('auth.forgot'),
        ]);
    }

    public function passwordReset(User $user, string $token): void
    {
        $this->toUser($user, 'Сброс пароля', 'password_reset', [
            'link' => $this->absolute('auth.reset.show', ['token' => $token]),
        ]);
    }

    public function passwordChanged(User $user): void
    {
        $this->toUser($user, 'Пароль изменён', 'password_changed', ['reset_link' => $this->absolute('auth.forgot')]);
    }

    public function emailChangeConfirm(string $newEmail, User $user, string $token): void
    {
        $this->mail->send($newEmail, 'Подтвердите новый адрес почты', 'email_change_confirm', [
            'name' => $user->name,
            'link' => $this->absolute('account.email.confirm.show', ['token' => $token]),
        ]);
    }

    public function emailChangeRequested(User $user, string $newEmail): void
    {
        $this->toUser($user, 'Запрошена смена адреса почты', 'email_change_requested', [
            'new_email' => $newEmail,
            'reset_link' => $this->absolute('auth.forgot'),
        ]);
    }

    public function emailChanged(string $oldEmail, string $newEmail, User $user): void
    {
        $this->mail->send($oldEmail, 'Адрес почты изменён', 'email_changed', [
            'name' => $user->name,
            'new_email' => $newEmail,
        ]);
    }

    public function lockoutWarning(User $user): void
    {
        $this->toUser($user, 'Много неудачных попыток входа', 'login_lockout', [
            'reset_link' => $this->absolute('auth.forgot'),
        ]);
    }

    public function twoFactorChanged(User $user, bool $enabled): void
    {
        $this->toUser(
            $user,
            $enabled ? 'Двухфакторная защита включена' : 'Двухфакторная защита отключена',
            $enabled ? 'two_factor_enabled' : 'two_factor_disabled',
            ['reset_link' => $this->absolute('auth.forgot')],
        );
    }

    /**
     * @param array<string, scalar|null> $data
     */
    private function toUser(User $user, string $subject, string $template, array $data): void
    {
        if ($user->email === null) {
            return;
        }
        $this->mail->send($user->email, $subject, $template, ['name' => $user->name] + $data);
    }

    /**
     * @param array<string, scalar> $params
     */
    private function absolute(string $route, array $params = []): string
    {
        return rtrim($this->config->string('app.url'), '/') . $this->router->url($route, $params);
    }
}
