<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLog;
use App\Http\Admin\StepUp;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Integrations\Payments\GatewayRegistry;
use App\Integrations\Payments\ProviderCatalog;
use App\Integrations\Payments\ProviderSettings;
use App\Kernel\Config;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Admin: payment providers. The owner switches each one on or off and enters its keys here, without a deploy. Keys are stored
 * encrypted and are never shown again: the form says only whether a secret is set, and an empty secret field keeps the saved one.
 * Saving needs the one-time code (it decides where customers' money goes).
 */
final class PaymentProvidersController
{
    public function __construct(
        private readonly View $view,
        private readonly ProviderSettings $settings,
        private readonly GatewayRegistry $registry,
        private readonly Config $config,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
        private readonly StepUp $stepUp,
    ) {
    }

    public function index(): Response
    {
        $base = rtrim($this->config->string('app.url'), '/');
        $offered = array_map(static fn ($g): string => $g->name(), $this->registry->available());
        $providers = [];
        foreach (ProviderCatalog::all() as $code => $info) {
            $fields = [];
            foreach ($info['fields'] as $field) {
                $fields[] = $field + ['set' => $this->settings->isSet($code, $field['key']), 'value' => $field['secret'] ? '' : $this->settings->value($code, $field['key'])];
            }
            $providers[] = array_merge($info, [
                'code' => $code,
                'fields' => $fields,
                'enabled' => $this->settings->isEnabled($code),
                'offered' => in_array($code, $offered, true),
                'webhook_url' => $info['webhook'] ? $base . '/webhooks/billing/' . $code . ($code === 'cloudpayments' ? '?type=pay' : '') : null,
            ]);
        }

        return $this->view->response('admin/billing/providers.twig', ['providers' => $providers]);
    }

    public function save(Request $request, string $code): Response
    {
        if (!isset(ProviderCatalog::all()[$code])) {
            throw new HttpException(404, 'Not found');
        }
        $staff = WorkspaceRequest::user($request);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/providers')) !== null) {
            return $denied;
        }
        $input = [];
        foreach ($request->all() as $key => $value) {
            if (is_string($value)) {
                $input[$key] = $value;
            }
        }
        $enabled = ($input['enabled'] ?? '') === '1';
        $before = ['enabled' => $this->settings->isEnabled($code)];
        $errors = $this->settings->save($code, $enabled, $input, $staff->id);
        if ($errors !== []) {
            $this->flash->toast(implode(' ', $errors), 'error');

            return Response::redirect('/admin/providers#' . $code);
        }
        // The trail names what changed, never a value.
        $touched = [];
        foreach (ProviderCatalog::all()[$code]['fields'] as $field) {
            if (trim($input[$field['key']] ?? '') !== '' || !$field['secret']) {
                $touched[] = $field['key'];
            }
        }
        $this->audit->record('admin.provider_updated', $staff->id, 'payment_provider', $code, ['enabled_before' => $before['enabled'] ? '1' : '0', 'enabled_after' => $enabled ? '1' : '0', 'fields' => implode(',', $touched)]);
        $this->flash->toast('Провайдер «' . ProviderCatalog::all()[$code]['label'] . '» сохранён.');

        return Response::redirect('/admin/providers#' . $code);
    }
}
