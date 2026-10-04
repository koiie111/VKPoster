<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Domain\Billing\Payment;
use App\Domain\Billing\PaymentRepository;
use App\Domain\Billing\PaymentStatus;
use App\Integrations\Payments\Fake\FakeGateway;
use App\Integrations\Payments\GatewayRegistry;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * The "payment page" of the test provider: pay, pay with a card that will be declined at renewal, or decline. Answers 404 unless the test
 * provider is available (never in production). The answer is recorded on the provider's side (`FakeGateway`); our own records change when
 * the customer lands on the return page, exactly as with a real provider.
 */
final class FakePaymentController
{
    public function __construct(
        private readonly View $view,
        private readonly GatewayRegistry $gateways,
        private readonly PaymentRepository $payments,
        private readonly FakeGateway $fake,
    ) {
    }

    public function show(Request $request): Response
    {
        $payment = $this->payment($request);

        return $this->view->response('dev/billing_pay.twig', ['payment' => $payment, 'return' => $this->returnUrl($request)]);
    }

    public function answer(Request $request): Response
    {
        $payment = $this->payment($request);
        $answer = $request->input('answer');
        $this->fake->answer($payment->publicId, is_string($answer) && in_array($answer, ['pay', 'pay_declining', 'decline'], true) ? $answer : 'decline');

        return Response::redirect($this->returnUrl($request));
    }

    private function payment(Request $request): Payment
    {
        if ($this->gateways->forWebhook('fake') === null) {
            throw new HttpException(404, 'Not found');
        }
        $params = $request->attribute('route_params');
        $payment = $this->payments->findByPublicId(is_array($params) && is_string($params['paymentId'] ?? null) ? $params['paymentId'] : '');
        if ($payment === null || $payment->provider !== 'fake' || $payment->status !== PaymentStatus::Pending) {
            throw new HttpException(404, 'Not found');
        }

        return $payment;
    }

    private function returnUrl(Request $request): string
    {
        $back = $request->input('return');

        return is_string($back) && Response::isRelativeUrl($back) ? $back : '/app';
    }
}
