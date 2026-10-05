<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLog;
use App\Domain\Design\ThemeColors;
use App\Http\Admin\AdminInput;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Admin: the colours of the site. Every token of the design system can be set by hand for the light and the dark theme, with a live
 * preview and a contrast check, and everything can be put back to the standard colours with one button.
 */
final class DesignController
{
    public function __construct(
        private readonly View $view,
        private readonly ThemeColors $theme,
        private readonly AuditLog $audit,
        private readonly FormFlash $flash,
    ) {
    }

    public function show(): Response
    {
        $groups = [];
        foreach (ThemeColors::TOKENS as $token => [$label, $group]) {
            $groups[$group][] = [
                'token' => $token,
                'label' => $label,
                'light' => ThemeColors::tripletToHex($this->theme->effective('light')[$token]),
                'dark' => ThemeColors::tripletToHex($this->theme->effective('dark')[$token]),
                'light_default' => ThemeColors::tripletToHex(ThemeColors::LIGHT[$token]),
                'dark_default' => ThemeColors::tripletToHex(ThemeColors::DARK[$token]),
            ];
        }
        $overrides = $this->theme->overrides();

        return $this->view->response('admin/design.twig', [
            'color_groups' => $groups,
            'customised' => ['light' => count($overrides['light']), 'dark' => count($overrides['dark'])],
            'changed' => ['light' => array_keys($overrides['light']), 'dark' => array_keys($overrides['dark'])],
            'contrast' => ['light' => $this->theme->contrast('light'), 'dark' => $this->theme->contrast('dark')],
        ]);
    }

    public function save(Request $request): Response
    {
        $actor = WorkspaceRequest::user($request);
        $light = $request->input('light');
        $dark = $request->input('dark');
        $result = $this->theme->save(is_array($light) ? $light : [], is_array($dark) ? $dark : [], $actor->id);
        $this->audit->record('admin.design_saved', $actor->id, 'design', 'colors', ['changed' => implode(',', $result['changed']), 'rejected' => implode(',', $result['invalid'])]);
        if ($result['invalid'] !== []) {
            $this->flash->toast('Часть цветов не принята: нужен формат #rrggbb. Остальное сохранено.', 'warning');
        } else {
            $this->flash->toast($result['changed'] === [] ? 'Ничего не изменилось.' : 'Цвета сохранены и уже действуют на сайте.');
        }
        $poor = array_filter([...$this->theme->contrast('light'), ...$this->theme->contrast('dark')], static fn (array $row): bool => !$row['ok']);
        if ($poor !== []) {
            $this->flash->toast('Некоторые сочетания читаются плохо (' . count($poor) . '). Они отмечены в проверке контраста ниже.', 'warning');
        }

        return Response::redirect('/admin/design');
    }

    public function reset(Request $request): Response
    {
        $actor = WorkspaceRequest::user($request);
        $scope = match (AdminInput::choice($request, 'scope', ['all', 'light', 'dark'], 'all')) {
            'light' => 'light',
            'dark' => 'dark',
            default => 'all',
        };
        $count = $this->theme->reset($scope, $actor->id);
        $this->audit->record('admin.design_reset', $actor->id, 'design', 'colors', ['scope' => $scope, 'tokens' => $count]);
        $this->flash->toast($count === 0 ? 'Здесь и так стандартные цвета.' : 'Стандартные цвета возвращены.');

        return Response::redirect('/admin/design');
    }
}
