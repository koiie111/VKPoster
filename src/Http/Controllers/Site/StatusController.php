<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Domain\Status\PlatformStatus;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * The public "state of the social networks" page, fed by the same data as the banner inside the application.
 */
final class StatusController
{
    public function __construct(private readonly View $view, private readonly PlatformStatus $status)
    {
    }

    public function show(): Response
    {
        return $this->view->response('site/status.twig', [
            'platforms' => $this->status->all(),
            'indexable' => true,
            'seo_title' => 'Состояние соцсетей',
            'seo_description' => 'Работают ли Telegram, ВКонтакте и MAX для публикации постов прямо сейчас.',
            'canonical_path' => '/status',
        ])->withHeader('Cache-Control', 'no-cache');
    }
}
