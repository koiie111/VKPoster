<?php

declare(strict_types=1);

namespace App\Tests\Feature\Billing;

use App\Domain\Billing\BillingPeriod;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Billing\BillingController;
use App\Kernel\Http\Response;
use App\Tests\Support\BillingTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The billing pages as the owner sees them, the whole checkout through the test provider, and who may (not) open what.
 */
#[CoversClass(BillingController::class)]
final class BillingPagesTest extends BillingTestCase
{
    private function billingUrl(\App\Domain\Workspace\Workspace $workspace, string $suffix = ''): string
    {
        return $this->base($workspace) . '/billing' . $suffix;
    }

    /**
     * Run the checkout the way a browser does and return the final redirect target after the "provider" page was answered.
     */
    private function checkoutAndAnswer(\App\Domain\Workspace\Workspace $workspace, string $plan, string $answer, string $period = 'month', string $keepCard = '1'): string
    {
        $checkout = $this->post($this->billingUrl($workspace, '/checkout'), ['plan' => $plan, 'period' => $period, 'gateway' => 'fake', 'keep_card' => $keepCard]);
        self::assertSame(303, $checkout->status);
        $pay = (string) $checkout->header('Location');
        self::assertTrue(str_starts_with($pay, $this->billingUrl($workspace, '/pay/')), $pay);
        $toProvider = $this->get($pay);
        self::assertSame(302, $toProvider->status);
        $providerPage = (string) $toProvider->header('Location');
        self::assertStringStartsWith('/dev/billing/pay/', $providerPage);
        self::assertSame(200, $this->get($providerPage)->status);
        $path = explode('?', $providerPage)[0];
        parse_str(explode('?', $providerPage)[1] ?? '', $query);
        $back = is_string($query['return'] ?? null) ? $query['return'] : self::fail('the provider page keeps the return address');
        $answered = $this->post($path, ['answer' => $answer, 'return' => $back]);
        self::assertSame(302, $answered->status);

        return (string) $answered->header('Location');
    }

    public function testGuestsAreSentToSignIn(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();

        $response = $this->get($this->billingUrl($workspace));

        self::assertSame(302, $response->status);
        self::assertStringStartsWith('/login', (string) $response->header('Location'));
    }

    public function testOnlyTheOwnerOpensBillingAndOtherPeopleGetNoHintsOfIt(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        foreach ([Role::Admin, Role::Editor, Role::Author, Role::Viewer, Role::Client] as $role) {
            $member = $this->memberOf($workspace, $role->value . '@example.com', $role);
            $this->actAs($member);
            foreach (['', '/plans', '/return?payment=x'] as $suffix) {
                self::assertSame(403, $this->get($this->billingUrl($workspace, $suffix))->status, $role->value . ' ' . $suffix);
            }
            self::assertSame(403, $this->post($this->billingUrl($workspace, '/checkout'), ['plan' => 'pro', 'period' => 'month', 'gateway' => 'fake'])->status);
            self::assertSame(403, $this->post($this->billingUrl($workspace, '/renewal/cancel'))->status);
            if ($role !== Role::Client) {
                self::assertStringNotContainsString('Тариф и оплата', $this->plain($this->get($this->base($workspace))), 'no menu entry for ' . $role->value);
            }
        }
        $this->actAs($owner);
        self::assertSame(200, $this->get($this->billingUrl($workspace))->status);
    }

    public function testAnotherWorkspaceIsNotFoundNotForbidden(): void
    {
        [, $mine] = $this->ownerWithWorkspace('mine@example.com');
        [, $theirs] = $this->ownerWithWorkspace('theirs@example.com');
        $this->actAs($this->createUser('visitor@example.com'));

        self::assertSame(404, $this->get($this->billingUrl($theirs))->status);
        self::assertSame(404, $this->post($this->billingUrl($theirs, '/checkout'), ['plan' => 'pro', 'period' => 'month', 'gateway' => 'fake'])->status);
        self::assertSame(404, $this->get($this->billingUrl($theirs, '/plans'))->status);
        self::assertNotSame($mine->id, $theirs->id);
    }

    public function testTheOverviewShowsThePlanTheTrialTheMetersAndAnEmptyHistory(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);

        $page = $this->get($this->billingUrl($workspace));
        $text = $this->plain($page);

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Тариф и оплата', $text);
        self::assertStringContainsString('Про', $text);
        self::assertStringContainsString('Пробный период', $text);
        self::assertStringContainsString('осталось дней: 14', $text);
        self::assertStringContainsString('Каналы', $text);
        self::assertStringContainsString('0 из 30', $text);
        self::assertStringContainsString('Платежей пока нет', $text);
        self::assertStringContainsString('Карта не сохранена', $text);
        self::assertStringContainsString($this->billingUrl($workspace, '/plans'), $page->body);
    }

    public function testTheOverviewOfAFreeWorkspace(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $this->actAs($owner);

        $text = $this->plain($this->get($this->billingUrl($workspace)));

        self::assertStringContainsString('Free', $text);
        self::assertStringContainsString('Бесплатный тариф без срока', $text);
        self::assertStringContainsString('0 из 2', $text);
        self::assertStringContainsString('Выбрать тариф', $text);
    }

    public function testThePlanPageListsEveryPlanWithPricesAndTheTrialNotice(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);

        $page = $this->get($this->billingUrl($workspace, '/plans'));
        $text = $this->plain($page);

        self::assertSame(200, $page->status);
        foreach (['Free', 'Старт', 'Про', 'Агентство', '390 ₽', '990 ₽', '2 990 ₽', 'Сейчас у вас пробный период', 'Что входит в каждый тариф', 'Согласование и гостевые ссылки', 'Тестовая оплата'] as $needle) {
            self::assertStringContainsString($needle, $text, $needle);
        }
        self::assertStringContainsString('name="plan" value="pro"', $page->body);
        self::assertStringContainsString('name="_token"', $page->body);
        self::assertSame(1, substr_count($page->body, 'name="gateway" value="fake"'));
    }

    public function testTheYearlyView(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);

        $text = $this->plain($this->get($this->billingUrl($workspace, '/plans?period=year')));

        self::assertStringContainsString('9 900 ₽', $text);
        self::assertStringContainsString('в год', $text);
        self::assertStringContainsString('825 ₽ в месяц', $text);
    }

    public function testThePageSaysWhatEachButtonWillDoForAPaidPlan(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'start');
        $this->actAs($owner);

        $text = $this->plain($this->get($this->billingUrl($workspace, '/plans')));

        self::assertStringContainsString('Ваш тариф', $text);
        self::assertStringContainsString('Доплатить', $text, 'Pro is an upgrade with a surcharge');
        self::assertStringContainsString('Доплата за остаток текущего срока', $text);
    }

    public function testAPaymentThroughTheTestProviderEndsOnAThankYouPageAndInTheHistoryWithAReceipt(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $this->actAs($owner);

        $return = $this->checkoutAndAnswer($workspace, 'pro', 'pay');
        $page = $this->get($return);

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Спасибо, оплата прошла', $this->plain($page));
        self::assertSame('pro', $this->plans()->find($this->subscription($workspace)->planId)?->code);

        $overview = $this->get($this->billingUrl($workspace));
        $text = $this->plain($overview);
        self::assertStringContainsString('Тариф «Про» за месяц', $text);
        self::assertStringContainsString('Оплачен', $text);
        self::assertStringContainsString('990 ₽', $text);
        self::assertStringContainsString('Тестовая карта •• 4242', $text);
        self::assertStringContainsString('спишем 990 ₽ за тариф «Про»', $text);
        $invoiceId = self::capture('#/invoices/([0-9A-Z]{26})/receipt#', $overview->body);

        $receipt = $this->get($this->billingUrl($workspace, '/invoices/' . $invoiceId . '/receipt'));

        self::assertSame(200, $receipt->status);
        self::assertSame('application/pdf', $receipt->header('Content-Type'));
        self::assertStringContainsString('attachment; filename="receipt-EZ-', (string) $receipt->header('Content-Disposition'));
        self::assertStringStartsWith('%PDF-1.4', $receipt->body);
        self::assertSame('private, no-store', $receipt->header('Cache-Control'));
    }

    public function testADeclinedPaymentEndsOnAnHonestPageAndChangesNothing(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $this->actAs($owner);

        $return = $this->checkoutAndAnswer($workspace, 'pro', 'decline');

        $text = $this->plain($this->get($return));
        self::assertStringContainsString('Оплата не прошла', $text);
        self::assertStringContainsString('Деньги не списаны', $text);
        self::assertSame('free', $this->plans()->find($this->subscription($workspace)->planId)?->code);
    }

    public function testAPaymentThatIsStillOpenShowsAWaitingPageThatRefreshesItself(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $this->actAs($owner);
        $checkout = $this->post($this->billingUrl($workspace, '/checkout'), ['plan' => 'pro', 'period' => 'month', 'gateway' => 'fake', 'keep_card' => '1']);
        $paymentId = substr((string) $checkout->header('Location'), -26);

        $page = $this->get($this->billingUrl($workspace, '/return?payment=' . $paymentId));

        self::assertStringContainsString('Ждём подтверждение', $this->plain($page));
        self::assertStringContainsString('hx-trigger="every 5s"', $page->body);
    }

    public function testNotKeepingTheCardIsRememberedFromTheCheckbox(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $this->actAs($owner);

        $this->get($this->checkoutAndAnswer($workspace, 'pro', 'pay', 'month', '0'));

        $text = $this->plain($this->get($this->billingUrl($workspace)));
        self::assertStringContainsString('Автопродление выключено', $text);
        self::assertStringContainsString('Включить автопродление', $text);
    }

    public function testRenewalCanBeSwitchedOffAndOnFromThePage(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $this->actAs($owner);
        self::assertStringContainsString('Отключить автопродление', $this->plain($this->get($this->billingUrl($workspace))));

        $off = $this->post($this->billingUrl($workspace, '/renewal/cancel'));
        self::assertSame(302, $off->status);
        $page = $this->plain($this->get($this->billingUrl($workspace)));
        self::assertStringContainsString('Автопродление выключено', $page);
        self::assertTrue($this->subscription($workspace)->cancelAtPeriodEnd);

        $this->post($this->billingUrl($workspace, '/renewal/resume'));
        self::assertFalse($this->subscription($workspace)->cancelAtPeriodEnd);
    }

    public function testADowngradeIsBookedAndCanBeTakenBackFromThePage(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $this->actAs($owner);

        $booked = $this->post($this->billingUrl($workspace, '/checkout'), ['plan' => 'start', 'period' => 'month', 'gateway' => 'fake', 'keep_card' => '1']);

        self::assertSame(302, $booked->status);
        self::assertSame($this->billingUrl($workspace), $booked->header('Location'));
        $page = $this->get($this->billingUrl($workspace));
        self::assertStringContainsString('тариф «Старт» начнётся', $page->body, 'the confirmation toast');
        self::assertStringContainsString('Отменить переход', $this->plain($page));

        $this->post($this->billingUrl($workspace, '/change/cancel'));
        self::assertNull($this->subscription($workspace)->pendingPlanId);
    }

    public function testTheSavedCardCanBeRemoved(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $this->actAs($owner);

        $this->post($this->billingUrl($workspace, '/card/remove'));

        self::assertNull($this->subscription($workspace)->paymentMethodId);
        self::assertStringContainsString('Карта не сохранена', $this->plain($this->get($this->billingUrl($workspace))));
    }

    public function testBadCheckoutInputIsAnsweredWithAMessageNotAnError(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);

        foreach ([['plan' => '', 'period' => 'month'], ['plan' => 'pro', 'period' => 'decade'], ['plan' => 'nonexistent', 'period' => 'month', 'gateway' => 'fake'], ['plan' => 'pro', 'period' => 'month', 'gateway' => 'stripe'], ['plan' => 'free', 'period' => 'month', 'gateway' => 'fake']] as $input) {
            $response = $this->post($this->billingUrl($workspace, '/checkout'), $input);
            self::assertSame(302, $response->status, (string) json_encode($input));
            self::assertTrue(str_starts_with((string) $response->header('Location'), $this->billingUrl($workspace, '/plans')));
        }
        self::assertSame(0, (int) $this->db->select('SELECT COUNT(*) AS c FROM invoices')[0]['c']);
    }

    public function testStateChangesNeedACsrfToken(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->payWithFake($workspace, $owner, 'pro');
        $this->actAs($owner);

        foreach (['/checkout', '/renewal/cancel', '/renewal/resume', '/change/cancel', '/card/remove'] as $path) {
            self::assertSame(419, $this->request('POST', $this->billingUrl($workspace, $path), ['plan' => 'pro'])->status, $path);
        }
        self::assertFalse($this->subscription($workspace)->cancelAtPeriodEnd);
    }

    public function testReceiptsAreOnlyForPaidInvoicesOfTheOwnWorkspace(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('mine@example.com');
        [$otherOwner, $other] = $this->ownerWithWorkspace('theirs@example.com');
        [$paid] = $this->payWithFake($other, $otherOwner, 'pro');
        $this->givePlan($workspace, 'free');
        $result = $this->billing()->checkout($this->contextFor($workspace, $owner), $owner, 'pro', BillingPeriod::Month, 'fake', true);
        $open = $this->paymentsRepo()->findByPublicId((string) $result->paymentId);
        $this->actAs($owner);

        self::assertSame(404, $this->get($this->billingUrl($workspace, '/invoices/' . $paid->publicId . '/receipt'))->status, 'an invoice of another workspace');
        self::assertSame(404, $this->get($this->billingUrl($workspace, '/invoices/' . $this->invoices()->findById($open->invoiceId ?? 0)?->publicId . '/receipt'))->status, 'an unpaid invoice');
        self::assertSame(404, $this->get($this->billingUrl($workspace, '/invoices/01JABCDEFGHJKMNPQRSTVWXYZ0/receipt'))->status, 'an unknown invoice');
    }

    public function testThePaymentPagesBelongToTheWorkspaceOfTheSession(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace('mine@example.com');
        [$otherOwner, $other] = $this->ownerWithWorkspace('theirs@example.com');
        $this->givePlan($other, 'free');
        $result = $this->billing()->checkout($this->contextFor($other, $otherOwner), $otherOwner, 'pro', BillingPeriod::Month, 'fake', true);
        $this->actAs($owner);

        self::assertSame(404, $this->get($this->billingUrl($workspace, '/pay/' . $result->paymentId))->status);
        self::assertSame(404, $this->get($this->billingUrl($workspace, '/return?payment=' . $result->paymentId))->status);
        self::assertSame(404, $this->get($this->billingUrl($workspace, '/return?payment=unknown'))->status);
    }

    public function testAPaymentThatIsAlreadyDoneCannotBeOpenedAgain(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $this->actAs($owner);
        $return = $this->checkoutAndAnswer($workspace, 'pro', 'pay');
        $this->get($return);
        $paymentId = self::capture('/payment=([0-9A-Z]{26})/', $return);

        $response = $this->get($this->billingUrl($workspace, '/pay/' . $paymentId));

        self::assertSame(302, $response->status);
        self::assertSame($this->billingUrl($workspace, '/plans'), $response->header('Location'));
        self::assertStringContainsString('уже не действует', $this->get($this->billingUrl($workspace, '/plans'))->body, 'the toast');
    }

    public function testTheTestProviderPageNeedsAnOpenPendingPayment(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->givePlan($workspace, 'free');
        $this->actAs($owner);

        self::assertSame(404, $this->get('/dev/billing/pay/01JABCDEFGHJKMNPQRSTVWXYZ0')->status);
        $return = $this->checkoutAndAnswer($workspace, 'pro', 'pay');
        $paymentId = self::capture('/payment=([0-9A-Z]{26})/', $return);
        $this->get($return);
        self::assertSame(404, $this->get('/dev/billing/pay/' . $paymentId)->status, 'a finished payment cannot be answered again');
    }

    public function testTheSidebarShowsThePlanAndTheTrialToEveryone(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $editor = $this->memberOf($workspace, 'editor@example.com', Role::Editor);
        $this->actAs($owner);
        $ownerText = $this->plain($this->get($this->base($workspace)));
        $this->actAs($editor);
        $editorPage = $this->get($this->base($workspace));

        self::assertStringContainsString('Тариф «Про»', $ownerText);
        self::assertStringContainsString('Пробный период: осталось дней 14', $ownerText);
        self::assertStringContainsString('Тариф и оплата', $ownerText);
        self::assertStringContainsString('Тариф «Про»', $this->plain($editorPage));
        self::assertStringNotContainsString($this->billingUrl($workspace), $editorPage->body, 'no way in for people who cannot pay');
    }

    public function testRegistrationStartsTheProTrial(): void
    {
        $this->post('/register', ['name' => 'Мария', 'email' => 'maria@example.com', 'password' => self::PASSWORD, 'consent' => '1']);

        $row = $this->db->select('SELECT s.status, p.code, s.trial_ends_at FROM subscriptions s JOIN plans p ON p.id = s.plan_id JOIN workspaces w ON w.id = s.workspace_id JOIN users u ON u.id = w.owner_id WHERE u.email = ?', ['maria@example.com']);
        self::assertCount(1, $row);
        self::assertSame('trialing', $row[0]['status']);
        self::assertSame('pro', $row[0]['code']);
        self::assertNotNull($row[0]['trial_ends_at']);
    }
}
