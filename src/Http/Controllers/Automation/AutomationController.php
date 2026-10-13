<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Domain\Automation\Crosspost\CrosspostRepository;
use App\Domain\Automation\Recurring\RecurringRepository;
use App\Domain\Automation\Rss\RssRepository;
use App\Domain\Billing\Entitlements;
use App\Http\WorkspaceRequest;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;

/**
 * The "Автоматизация" page: one place that explains the tools of stage 13 and shows how many of each the workspace uses.
 */
final class AutomationController
{
    public function __construct(
        private readonly View $view,
        private readonly RecurringRepository $recurring,
        private readonly CrosspostRepository $crosspost,
        private readonly RssRepository $rss,
        private readonly Entitlements $entitlements,
    ) {
    }

    public function index(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $base = AutomationHttp::base($context);
        $slots = $this->entitlements->hasFeature($context->workspaceId, 'slots');
        $crosspost = $this->entitlements->hasFeature($context->workspaceId, 'crosspost');
        $cards = [
            ['title' => 'Повторяющиеся посты', 'text' => 'Пост выходит сам: каждый день, по дням недели, раз в N дней или раз в месяц.', 'icon' => 'repeat', 'href' => $base . '/recurring', 'count' => count($this->recurring->all($context)), 'locked' => !$slots],
            ['title' => 'Кросспостинг из Telegram', 'text' => 'Пост в вашем Telegram-канале сам уходит в ВКонтакте, MAX и другие каналы.', 'icon' => 'share-2', 'href' => $base . '/crosspost', 'count' => count($this->crosspost->all($context)), 'locked' => !$crosspost],
            ['title' => 'Ленты RSS', 'text' => 'Новые записи сайта или блога превращаются в черновики или встают в очередь.', 'icon' => 'globe', 'href' => $base . '/rss', 'count' => count($this->rss->all($context)), 'locked' => !$crosspost],
            ['title' => 'Загрузка из файла', 'text' => 'Таблица CSV или Excel: сотни постов за один раз, с проверкой каждой строки.', 'icon' => 'upload', 'href' => $base . '/import', 'count' => null, 'locked' => false],
            ['title' => 'Очереди и слоты', 'text' => 'Задайте расписание один раз, и посты сами встают в ближайшее свободное время.', 'icon' => 'clock', 'href' => $base . '/queues', 'count' => null, 'locked' => !$slots],
        ];

        return $this->view->response('workspace/automation/index.twig', ['workspace' => $context, 'cards' => $cards, 'base' => $base]);
    }
}
