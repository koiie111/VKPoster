<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Notification\MarketingConsent;
use App\Domain\Notification\NotificationSettings;
use App\Domain\Notification\NotificationType;
use App\Domain\Notification\TelegramLinks;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Config;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * "Notifications" in the account: which messages about posts and channels reach the person by email and in Telegram, and linking the
 * Telegram chat with the shared bot (`t.me/<bot>?start=<token>`). Account-level: the choice follows the person into every workspace.
 */
final class NotificationController
{
    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly NotificationSettings $settings,
        private readonly TelegramLinks $links,
        private readonly Config $config,
        private readonly MarketingConsent $marketing,
    ) {
    }

    public function show(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        $choices = $this->settings->forUser($user->id);
        $types = [];
        foreach (NotificationType::cases() as $type) {
            $types[] = ['id' => $type->value, 'label' => $type->label(), 'hint' => $type->hint(), 'email' => $choices[$type->value]['email'], 'telegram' => $choices[$type->value]['telegram']];
        }
        $link = $this->links->linkOf($user->id);
        $botName = $this->config->string('platforms.telegram.bot_username');
        $session = $this->flash->session();
        $token = $session->get('notifications.telegram_token');

        return $this->view->response('account/notifications.twig', [
            'user' => $user,
            'types' => $types,
            'marketing' => $this->marketing->isSubscribed($user->id),
            'has_email' => $user->email !== null && $user->isVerified(),
            'telegram' => $link,
            'bot_configured' => $this->config->string('platforms.telegram.bot_token') !== '' && $botName !== '',
            'connect_url' => is_string($token) && $botName !== '' ? 'https://t.me/' . $botName . '?start=' . $token : null,
        ]);
    }

    public function save(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        $input = $request->input('n');
        $choices = [];
        foreach (NotificationType::cases() as $type) {
            $row = is_array($input) && is_array($input[$type->value] ?? null) ? $input[$type->value] : [];
            $choices[$type->value] = ['email' => ($row['email'] ?? null) === '1', 'telegram' => ($row['telegram'] ?? null) === '1'];
        }
        $this->settings->save($user->id, $choices);
        $this->flash->toast('Настройки уведомлений сохранены.');

        return Response::redirect('/account/notifications');
    }

    /**
     * The separate choice to receive news and offers (off unless the person ticked it at sign-up or here).
     */
    public function saveMarketing(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        if ($request->input('marketing') === '1') {
            $this->marketing->subscribe($user->id);
            $this->flash->toast('Будем присылать новости и предложения. Отказаться можно в любой момент.');
        } else {
            $this->marketing->unsubscribe($user->id);
            $this->flash->toast('Новости и предложения отключены.');
        }

        return Response::redirect('/account/notifications');
    }

    public function linkTelegram(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        $this->flash->session()->set('notifications.telegram_token', $this->links->issueToken($user->id));

        return Response::redirect('/account/notifications');
    }

    public function unlinkTelegram(Request $request): Response
    {
        $user = WorkspaceRequest::user($request);
        $this->links->unlink($user->id);
        $this->flash->session()->forget('notifications.telegram_token');
        $this->flash->toast('Telegram отключён от уведомлений.');

        return Response::redirect('/account/notifications');
    }
}
