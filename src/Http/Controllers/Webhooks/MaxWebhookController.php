<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domain\Channel\MaxUpdateHandler;
use App\Integrations\Social\Max\MaxWebhook;
use App\Kernel\Config;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/**
 * Endpoint MAX calls for every update of the shared bot. No session and no CSRF token (the call comes from MAX); authenticity is
 * the secret in the URL plus the secret header, checked before anything is read. Always answers 200 for an authentic call, even
 * when the update was useless, because any other answer makes MAX retry it (and drop the subscription after eight hours of failures).
 */
final class MaxWebhookController
{
    public function __construct(private readonly Config $config, private readonly MaxUpdateHandler $handler)
    {
    }

    public function receive(Request $request): Response
    {
        $secret = $this->config->string('platforms.max.webhook_secret');
        if ($secret === '') {
            throw new HttpException(404, 'Not found');
        }
        $params = $request->attribute('route_params');
        $pathSecret = is_array($params) && is_string($params['secret'] ?? null) ? $params['secret'] : '';
        if (!MaxWebhook::isAuthentic($secret, $pathSecret, $request->header('X-Max-Bot-Api-Secret'))) {
            throw new HttpException(403, 'Forbidden');
        }
        $this->handler->handle($request->json());

        return Response::json(['ok' => true]);
    }
}
