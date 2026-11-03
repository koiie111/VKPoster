<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Ai\AiAction;
use App\Domain\Ai\AiUsageLog;
use App\Domain\Audit\AuditLog;
use App\Http\Admin\StepUp;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Integrations\Ai\Llm\LlmCatalog;
use App\Integrations\Ai\Llm\LlmChain;
use App\Integrations\Ai\Llm\LlmSettings;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Admin: language-model providers for the AI assistant. Ready presets (OpenAI, Claude, Gemini, OpenRouter, YandexGPT, DeepSeek, …)
 * need only a key; any OpenAI-compatible endpoint is added as a custom provider. One provider is the default. Keys are stored
 * encrypted and never shown again (an empty key field keeps the saved one); every change needs the one-time code and is audited
 * without values.
 */
final class AiProvidersController
{
    public function __construct(
        private readonly View $view,
        private readonly LlmSettings $settings,
        private readonly LlmChain $chain,
        private readonly AiUsageLog $usage,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
        private readonly StepUp $stepUp,
    ) {
    }

    public function index(): Response
    {
        $saved = $this->settings->all();
        $usable = $this->settings->usable();
        $cards = [];
        foreach (LlmCatalog::presets() as $code => $preset) {
            $cards[] = $this->card($code, $preset['label'], $preset, $saved[$code] ?? null, isset($usable[$code]), false);
        }
        foreach ($saved as $code => $row) {
            if (LlmCatalog::isCustom($code)) {
                $cards[] = $this->card($code, $row['label'], ['kind' => 'openai', 'base_url' => '', 'model' => '', 'auth' => 'bearer', 'region' => 'custom', 'note' => 'Любой сервис с OpenAI-совместимым API: шлюз, свой сервер с vLLM или Ollama за https.'], $row, isset($usable[$code]), true);
            }
        }

        $steps = array_map(fn (array $s): array => $s + ['used' => $this->chain->usedToday($s['id']), 'cooling' => $this->chain->cooling($s['id']), 'ready' => isset($usable[$s['provider']])], $this->chain->all());
        $providers = [];
        foreach ($cards as $card) {
            $providers[$card['code']] = $card['label'];
        }

        return $this->view->response('admin/ai.twig', [
            'steps' => $steps,
            'actions' => array_column(array_map(static fn (AiAction $a): array => [$a->value, $a->label()], AiAction::cases()), 1, 0),
            'providers' => $providers,
            'cards' => $cards,
            'auth_schemes' => LlmCatalog::AUTH_SCHEMES,
            'default' => $this->settings->defaultCode(),
            'totals' => $this->usage->totals(30),
        ]);
    }

    public function save(Request $request, string $code): Response
    {
        if (!LlmCatalog::isKnown($code)) {
            throw new HttpException(404, 'Not found');
        }
        $staff = WorkspaceRequest::user($request);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/ai')) !== null) {
            return $denied;
        }
        $input = $this->input($request);
        $errors = $this->settings->save($code, $input, $staff->id);
        if ($errors !== []) {
            $this->flash->toast(implode(' ', $errors), 'error');

            return Response::redirect('/admin/ai#' . $code);
        }
        $this->audit->record('admin.ai_provider_updated', $staff->id, 'ai_provider', $code, ['enabled' => ($input['enabled'] ?? '') === '1' ? '1' : '0', 'default' => ($input['default'] ?? '') === '1' ? '1' : '0']);
        $this->flash->toast('Провайдер сохранён.');

        return Response::redirect('/admin/ai#' . $code);
    }

    /** Add a custom OpenAI-compatible provider: `slug` becomes part of its code. */
    public function create(Request $request): Response
    {
        $staff = WorkspaceRequest::user($request);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/ai')) !== null) {
            return $denied;
        }
        $slug = strtolower(WorkspaceRequest::text($request->input('slug')));
        $code = LlmCatalog::CUSTOM_PREFIX . $slug;
        if (!LlmCatalog::isCustom($code)) {
            $this->flash->toast('Короткое имя: латиница, цифры и дефис, до 25 знаков.', 'error');

            return Response::redirect('/admin/ai#add');
        }
        $errors = $this->settings->save($code, $this->input($request), $staff->id);
        if ($errors !== []) {
            $this->flash->toast(implode(' ', $errors), 'error');

            return Response::redirect('/admin/ai#add');
        }
        $this->audit->record('admin.ai_provider_updated', $staff->id, 'ai_provider', $code, ['created' => '1']);
        $this->flash->toast('Провайдер добавлен.');

        return Response::redirect('/admin/ai#' . $code);
    }

    public function delete(Request $request, string $code): Response
    {
        if (!LlmCatalog::isCustom($code) || !isset($this->settings->all()[$code])) {
            throw new HttpException(404, 'Not found');
        }
        $staff = WorkspaceRequest::user($request);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/ai')) !== null) {
            return $denied;
        }
        $this->settings->delete($code);
        $this->audit->record('admin.ai_provider_deleted', $staff->id, 'ai_provider', $code);
        $this->flash->toast('Провайдер удалён.');

        return Response::redirect('/admin/ai');
    }

    /** Add a step to the chain of models (tried in order; the first with free daily requests answers). */
    public function chainAdd(Request $request): Response
    {
        return $this->chainSave($request, null);
    }

    public function chainUpdate(Request $request, string $id): Response
    {
        return $this->chainSave($request, (int) $id);
    }

    public function chainDelete(Request $request, string $id): Response
    {
        $staff = WorkspaceRequest::user($request);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/ai')) !== null) {
            return $denied;
        }
        $this->chain->delete((int) $id);
        $this->audit->record('admin.ai_chain_updated', $staff->id, 'ai_chain_step', $id, ['deleted' => '1']);
        $this->flash->toast('Шаг удалён.');

        return Response::redirect('/admin/ai');
    }

    private function chainSave(Request $request, ?int $id): Response
    {
        $staff = WorkspaceRequest::user($request);
        if (($denied = $this->stepUp->guard($request, $staff, '/admin/ai')) !== null) {
            return $denied;
        }
        $input = $this->input($request);
        $actions = $request->all()['actions'] ?? [];
        $errors = $this->chain->save($id, $input + ['actions' => is_array($actions) ? $actions : []], $staff->id);
        if ($errors !== []) {
            $this->flash->toast(implode(' ', $errors), 'error');

            return Response::redirect('/admin/ai');
        }
        $this->audit->record('admin.ai_chain_updated', $staff->id, 'ai_chain_step', $id === null ? 'new' : (string) $id, ['provider' => mb_substr($input['provider'] ?? '', 0, 40), 'enabled' => ($input['enabled'] ?? '') === '1' ? '1' : '0']);
        $this->flash->toast('Цепочка сохранена.');

        return Response::redirect('/admin/ai');
    }

    /** @return array<string, string> */
    private function input(Request $request): array
    {
        $input = [];
        foreach ($request->all() as $key => $value) {
            if (is_string($value)) {
                $input[$key] = $value;
            }
        }

        return $input;
    }

    /**
     * @param array{kind: string, base_url: string, model: string, auth: string, region: string, note: string} $preset
     * @param array{kind: string, label: string, enabled: bool, default: bool, values: array<string, string>}|null $row
     * @return array<string, mixed>
     */
    private function card(string $code, string $label, array $preset, ?array $row, bool $usable, bool $custom): array
    {
        $values = $row['values'] ?? [];

        return [
            'code' => $code,
            'label' => $label,
            'kind' => $preset['kind'],
            'region' => $preset['region'],
            'note' => $preset['note'],
            'custom' => $custom,
            'enabled' => $row['enabled'] ?? false,
            'default' => $row['default'] ?? false,
            'usable' => $usable,
            'key_set' => ($values['api_key'] ?? '') !== '',
            'base_url' => $values['base_url'] ?? '',
            'base_url_default' => $preset['base_url'],
            'model' => $values['model'] ?? '',
            'model_default' => $preset['model'],
            'auth' => $values['auth'] ?? $preset['auth'],
        ];
    }
}
