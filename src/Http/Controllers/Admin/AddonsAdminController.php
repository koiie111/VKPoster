<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\AddonPackage;
use App\Domain\Billing\AddonRepository;
use App\Domain\Billing\PlanEditor;
use App\Http\Admin\AdminInput;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Admin: add-on packages (extra channels, people, library space, AI credits) with prices in any currency. A package is created or, when
 * the code already exists, changed; what customers already bought keeps the quantity and the term they paid for.
 */
final class AddonsAdminController
{
    public function __construct(
        private readonly View $view,
        private readonly AddonRepository $addons,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
    ) {
    }

    public function index(Request $request): Response
    {
        $edit = AdminInput::text($request, 'edit', 32);

        return $this->view->response('admin/billing/addons.twig', [
            'packages' => $this->addons->all(),
            'kinds' => array_map(static fn (array $k): string => $k[0], AddonPackage::KINDS),
            'currencies' => PlanEditor::CURRENCIES,
            'editing' => $edit === '' ? null : $this->addons->findByCode($edit),
        ]);
    }

    public function save(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        $code = strtolower(AdminInput::text($request, 'code', 32));
        $name = AdminInput::text($request, 'name', 80);
        $kind = AdminInput::choice($request, 'kind', array_keys(AddonPackage::KINDS));
        $quantityText = AdminInput::text($request, 'quantity', 9);
        $daysText = AdminInput::text($request, 'days', 4);
        $errors = [];
        if (preg_match('/^[a-z0-9_]{2,32}$/', $code) !== 1) {
            $errors[] = 'Код пакета — латиница, цифры и подчёркивание.';
        }
        if (mb_strlen($name) < 2) {
            $errors[] = 'Напишите название.';
        }
        if ($kind === '') {
            $errors[] = 'Выберите, что даёт пакет.';
        }
        if (preg_match('/^\d{1,9}$/', $quantityText) !== 1 || (int) $quantityText < 1) {
            $errors[] = 'Количество — целое число от 1.';
        }
        $isCredits = $kind === 'ai_credits';
        if (!$isCredits && (preg_match('/^\d{1,4}$/', $daysText) !== 1 || (int) $daysText < 1)) {
            $errors[] = 'Срок действия — целое число дней.';
        }
        $prices = [];
        foreach (array_keys(PlanEditor::CURRENCIES) as $currency) {
            $text = str_replace(',', '.', AdminInput::text($request, 'price_' . strtolower($currency), 12));
            if ($text === '') {
                continue;
            }
            $ok = $currency === 'XTR' ? preg_match('/^\d{1,5}$/', $text) === 1 : preg_match('/^\d{1,7}(\.\d{1,2})?$/', $text) === 1;
            if (!$ok || (float) $text <= 0) {
                $errors[] = 'Цена в ' . $currency . ' — положительное число.';
                continue;
            }
            $prices[$currency] = (int) round((float) $text * 100);
        }
        if ($prices === []) {
            $errors[] = 'Укажите хотя бы одну цену.';
        }
        if ($errors !== []) {
            $this->flash->toast(implode(' ', $errors), 'error');

            return Response::redirect('/admin/addons' . ($code !== '' ? '?edit=' . rawurlencode($code) : ''));
        }
        $quantity = (int) $quantityText * ($kind === 'storage_bytes' ? 1024 * 1024 : 1);
        $existing = $this->addons->findByCode($code);
        $package = $this->addons->save($existing?->id, $code, $name, $kind, $quantity, $isCredits ? 30 : (int) $daysText, $prices, $request->input('active') === '1', (int) AdminInput::text($request, 'sort', 4));
        $this->audit->record($existing === null ? 'admin.addon_created' : 'admin.addon_updated', $staff->id, 'addon', $package->code, ['kind' => $kind, 'quantity' => $quantity]);
        $this->flash->toast('Пакет «' . $package->name . '» сохранён.');

        return Response::redirect('/admin/addons');
    }
}
