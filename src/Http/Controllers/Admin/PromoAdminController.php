<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Promo;
use App\Domain\Billing\PromoRepository;
use App\Domain\Billing\PromoService;
use App\Domain\Billing\PlanRepository;
use App\Http\Admin\AdminInput;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Admin: promo codes. The owner creates codes (percent, amount, free days) with limits, switches them off and sees how often each was used.
 * A code is never edited once it exists (people may have seen its terms): switch it off and make a new one.
 */
final class PromoAdminController
{
    public function __construct(
        private readonly View $view,
        private readonly PromoRepository $promos,
        private readonly PlanRepository $plans,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->view->response('admin/billing/promo.twig', [
            'rows' => $this->promos->allWithUse(),
            'plans' => array_filter(array_map(static fn ($p): ?array => $p->isFree() ? null : ['code' => $p->code, 'name' => $p->name], $this->plans->all())),
            'kinds' => Promo::KINDS,
            'tz' => WorkspaceRequest::user($request)->timezone,
        ]);
    }

    public function create(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        $code = PromoService::normalize(AdminInput::text($request, 'code', 32));
        $kind = AdminInput::choice($request, 'kind', array_keys(Promo::KINDS));
        $valueText = str_replace(',', '.', AdminInput::text($request, 'value', 12));
        $plans = [];
        $submitted = $request->input('plans');
        foreach (is_array($submitted) ? $submitted : [] as $planCode) {
            if (is_string($planCode) && $this->plans->findByCode($planCode) !== null) {
                $plans[] = $planCode;
            }
        }
        $errors = [];
        if (!PromoService::isValidCode($code)) {
            $errors[] = 'Код — от 3 до 32 символов: латинские буквы, цифры, дефис и подчёркивание.';
        } elseif ($this->promos->findByCode($code) !== null) {
            $errors[] = 'Такой код уже есть.';
        }
        $value = 0;
        $currency = null;
        if ($kind === '') {
            $errors[] = 'Выберите вид промокода.';
        } elseif ($kind === 'percent') {
            if (preg_match('/^\d{1,3}$/', $valueText) !== 1 || (int) $valueText < 1 || (int) $valueText > 100) {
                $errors[] = 'Скидка в процентах — целое число от 1 до 100.';
            } else {
                $value = (int) $valueText;
            }
        } elseif ($kind === 'amount') {
            $currency = AdminInput::choice($request, 'currency', ['RUB', 'USD', 'EUR'], 'RUB');
            if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $valueText) !== 1 || (float) $valueText <= 0) {
                $errors[] = 'Сумма скидки — положительное число.';
            } else {
                $value = (int) round((float) $valueText * 100);
            }
        } else {
            if (preg_match('/^\d{1,4}$/', $valueText) !== 1 || (int) $valueText < 1) {
                $errors[] = 'Бесплатные дни — целое число от 1.';
            } else {
                $value = (int) $valueText;
            }
            if (count($plans) !== 1) {
                $errors[] = 'Для бесплатных дней выберите ровно один тариф.';
            }
        }
        $max = AdminInput::text($request, 'max', 7);
        if ($max !== '' && (preg_match('/^\d{1,7}$/', $max) !== 1 || (int) $max < 1)) {
            $errors[] = 'Лимит использований — целое число от 1 (пусто = без лимита).';
        }
        $zone = new DateTimeZone($staff->timezone);
        $from = $this->day(AdminInput::text($request, 'from', 10), $zone, false);
        $until = $this->day(AdminInput::text($request, 'until', 10), $zone, true);
        if ((AdminInput::text($request, 'from', 10) !== '' && $from === null) || (AdminInput::text($request, 'until', 10) !== '' && $until === null)) {
            $errors[] = 'Даты — в формате ГГГГ-ММ-ДД.';
        }
        if ($errors !== []) {
            $this->flash->toast(implode(' ', $errors), 'error');

            return Response::redirect('/admin/promo');
        }
        $promo = $this->promos->create($code, $kind, $value, $currency, $plans, $request->input('first_payment_only') === '1', $max === '' ? null : (int) $max, $from, $until, AdminInput::text($request, 'note', 200), $staff->id);
        $this->audit->record('admin.promo_created', $staff->id, 'promo', $promo->code, ['kind' => $kind, 'value' => $value]);
        $this->flash->toast('Промокод ' . $promo->code . ' создан.');

        return Response::redirect('/admin/promo');
    }

    public function toggle(Request $request): Response
    {
        $params = $request->attribute('route_params');
        $promo = $this->promos->find(is_array($params) && is_string($params['id'] ?? null) ? (int) $params['id'] : 0) ?? throw new HttpException(404, 'Not found');
        $this->promos->setActive($promo->id, !$promo->active);
        $this->audit->record('admin.promo_toggled', WorkspaceRequest::user($request)->id, 'promo', $promo->code, ['active' => $promo->active ? '0' : '1']);
        $this->flash->toast($promo->active ? 'Промокод выключен.' : 'Промокод включён.');

        return Response::redirect('/admin/promo');
    }

    private function day(string $text, DateTimeZone $zone, bool $endOfDay): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
            return null;
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $text, $zone);
        if ($day === false || $day->format('Y-m-d') !== $text) {
            return null;
        }

        return ($endOfDay ? $day->modify('+1 day') : $day)->setTimezone(new DateTimeZone('UTC'));
    }
}
