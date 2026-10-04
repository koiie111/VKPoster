<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Domain\Billing\BillingService;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;

/**
 * Endpoint payment providers call for every payment event (`/webhooks/billing/{provider}`). No session and no CSRF token: the call comes from
 * the provider, and authenticity is checked by its gateway (sender address for YooKassa, the signed `Token` for T-Bank) before anything is read.
 * `BillingService` does the rest and answers the way the provider expects.
 */
final class PaymentWebhookController
{
    public function __construct(private readonly BillingService $billing)
    {
    }

    public function receive(Request $request): Response
    {
        $params = $request->attribute('route_params');

        return $this->billing->handleWebhook(is_array($params) && is_string($params['provider'] ?? null) ? $params['provider'] : '', $request);
    }
}
