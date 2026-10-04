<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domain\Channel\TelegramUpdateHandler;
use App\Integrations\Social\Telegram\TelegramWebhook;
use App\Kernel\Config;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/**
 * Endpoint Telegram calls for every update of the shared bot. No session and no CSRF token (the call comes from Telegram);
 * authenticity is the secret in the URL plus the secret header, checked before anything is read. Always answers 200 for an
 * authentic call, even when the update was useless, because a non-200 makes Telegram retry it.
 */
final class TelegramWebhookController
{
    public function __construct(private readonly Config $config, private readonly TelegramUpdateHandler $handler)
    {
    }

    public function receive(Request $request): Response
    {
        $secret = $this->config->string('platforms.telegram.webhook_secret');
        if ($secret === '') {
            throw new HttpException(404, 'Not found');
        }
        $params = $request->attribute('route_params');
        $pathSecret = is_array($params) && is_string($params['secret'] ?? null) ? $params['secret'] : '';
        if (!TelegramWebhook::isAuthentic($secret, $pathSecret, $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            throw new HttpException(403, 'Forbidden');
        }
        $this->handler->handle($request->json());

        return Response::json(['ok' => true]);
    }
}
