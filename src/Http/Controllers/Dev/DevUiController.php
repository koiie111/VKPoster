<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Kernel\Config;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * Design-system showcase (`/dev/ui`), layout samples and clickable prototypes of the key screens.
 * Available only when APP_ENV is `local` (or `testing`); every other environment gets a plain 404,
 * and the application refuses to boot dev features in production anyway.
 */
final class DevUiController
{
    public function __construct(private readonly View $view, private readonly Config $config)
    {
    }

    public function showcase(): Response
    {
        return $this->page('dev/ui.twig', ['posts' => DemoData::posts()]);
    }

    public function onboarding(Request $request): Response
    {
        $step = (int) $request->input('step', 1);

        return $this->page('dev/onboarding.twig', ['step' => min(max($step, 1), 4)]);
    }

    public function channels(Request $request): Response
    {
        $state = $request->input('state');

        return $this->page('dev/channels.twig', [
            'channels' => DemoData::channels(),
            'state' => $state === 'empty' || $state === 'loading' ? $state : 'default',
            'connected' => $request->input('connected') === '1',
        ]);
    }

    public function editor(Request $request): Response
    {
        $error = $request->input('error') === '1';

        return $this->page('dev/editor.twig', [
            'error' => $error,
            // The error state shows a post that is too long for Instagram (2200 characters).
            'text' => $error
                ? 'Очень длинный текст, который не помещается в подпись к фото в Instagram. ' . str_repeat('Ещё немного слов для проверки. ', 70)
                : 'Новое меню уже в кофейне! Заходите попробовать тыквенный латте.',
        ]);
    }

    public function calendar(Request $request): Response
    {
        return $this->page('dev/calendar.twig', [
            'state' => $request->input('state') === 'empty' ? 'empty' : 'default',
            'view' => in_array($request->input('view'), ['week', 'list'], true) ? $request->input('view') : 'month',
            'week' => DemoData::week(),
            'month' => DemoData::month(),
            'groups' => DemoData::listGroups(),
        ]);
    }

    public function dashboard(): Response
    {
        return $this->page('dev/dashboard.twig', ['posts' => DemoData::posts(), 'channels' => DemoData::channels()]);
    }

    public function layoutAuth(): Response
    {
        return $this->page('dev/layout-auth.twig');
    }

    public function layoutLanding(): Response
    {
        return $this->page('dev/layout-landing.twig');
    }

    public function layoutAdmin(): Response
    {
        return $this->page('dev/layout-admin.twig');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(string $template, array $data = []): Response
    {
        $env = $this->config->string('app.env');
        if ($env !== 'local' && $env !== 'testing') {
            throw new HttpException(404, 'Not found');
        }

        return $this->view->response($template, $data);
    }
}
