<?php

declare(strict_types=1);

namespace App\Http\Controllers\Automation;

use App\Domain\Automation\AutomationException;
use App\Domain\Automation\Recurring\RecurrenceRule;
use App\Domain\Automation\Recurring\RecurringPost;
use App\Domain\Automation\Recurring\RecurringRepository;
use App\Domain\Automation\Recurring\RecurringService;
use App\Domain\Billing\Entitlements;
use App\Domain\Post\Post;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostService;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Clock;
use App\Support\RuDates;
use DateTimeZone;

/**
 * Recurring posts: the list of rules with the next time of each, and the form that makes a rule from a draft.
 */
final class RecurringController
{
    private const DAYS = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];

    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly AutomationHttp $http,
        private readonly RecurringService $service,
        private readonly RecurringRepository $repository,
        private readonly PostRepository $posts,
        private readonly PostService $postService,
        private readonly Entitlements $entitlements,
        private readonly Permissions $permissions,
        private readonly Clock $clock,
    ) {
    }

    public function index(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $zone = new DateTimeZone($context->timezone);
        $rows = [];
        foreach ($this->repository->all($context) as $recurring) {
            $post = $this->posts->findById($context, $recurring->postId);
            $rows[] = [
                'id' => $recurring->publicId,
                'post_id' => $post?->publicId,
                'title' => $post === null ? 'Пост удалён' : $post->title(80),
                'summary' => self::summary($recurring->rule),
                'next' => $recurring->enabled && $recurring->nextRunAt !== null ? RuDates::full($recurring->nextRunAt->setTimezone($zone)) : null,
                'done' => $recurring->doneCount,
                'left' => $recurring->rule->maxCount === null ? null : max(0, $recurring->rule->maxCount - $recurring->doneCount),
                'enabled' => $recurring->enabled,
                'finished' => !$recurring->enabled && $recurring->nextRunAt === null,
                'error' => $recurring->lastError,
            ];
        }
        $preselected = $this->posts->find($context, AutomationHttp::text($request, 'post', 26));

        return $this->view->response('workspace/automation/recurring.twig', [
            'workspace' => $context,
            'rules' => $rows,
            'posts' => array_map(static fn (Post $p): array => ['id' => $p->publicId, 'title' => $p->title(70)], $this->posts->drafts($context, $this->postService->allowedChannels($context), 100)),
            'preselected' => $preselected !== null && $this->postService->canSee($context, $preselected) ? $preselected->publicId : '',
            'days' => self::DAYS,
            'today' => $this->clock->now()->setTimezone(new DateTimeZone($context->timezone))->format('Y-m-d'),
            'can_manage' => $this->permissions->allows($context->role, 'posts.publish'),
            'has_feature' => $this->entitlements->hasFeature($context->workspaceId, 'slots'),
            'timezone' => $context->timezone,
            'base' => AutomationHttp::base($context),
        ]);
    }

    public function store(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $back = AutomationHttp::base($context) . '/recurring';
        $weekdays = [];
        $raw = $request->input('weekdays');
        foreach (is_array($raw) ? $raw : [] as $day) {
            if (is_string($day) && ctype_digit($day) && (int) $day >= 1 && (int) $day <= 7) {
                $weekdays[(int) $day] = (int) $day;
            }
        }
        $end = AutomationHttp::text($request, 'ends_on', 10);
        $max = AutomationHttp::text($request, 'max_count', 5);
        $monthDay = AutomationHttp::text($request, 'month_day', 2);
        $interval = AutomationHttp::text($request, 'interval', 3);
        $rule = new RecurrenceRule(
            AutomationHttp::text($request, 'frequency', 16),
            ctype_digit($interval) ? (int) $interval : 1,
            array_values($weekdays),
            ctype_digit($monthDay) ? (int) $monthDay : null,
            AutomationHttp::text($request, 'time', 5),
            $context->timezone,
            AutomationHttp::text($request, 'starts_on', 10),
            $end === '' ? null : $end,
            ctype_digit($max) ? (int) $max : null,
        );
        $input = $request->all();
        try {
            $post = $this->posts->find($context, AutomationHttp::text($request, 'post', 26)) ?? throw new AutomationException('Выберите пост, который нужно повторять.');
            $this->service->create($context, $post, $rule);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, $back, $input);
        }
        $this->flash->toast('Повтор создан. Копии поста будут появляться в календаре за сутки до выхода.');

        return Response::redirect($back);
    }

    public function pause(Request $request): Response
    {
        return $this->toggle($request, false);
    }

    public function resume(Request $request): Response
    {
        return $this->toggle($request, true);
    }

    public function delete(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $recurring = $this->rule($context, $request);
        try {
            $this->service->delete($context, $recurring);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, AutomationHttp::base($context) . '/recurring');
        }
        $this->flash->toast('Повтор удалён. Уже созданные копии остались в календаре.');

        return Response::redirect(AutomationHttp::base($context) . '/recurring');
    }

    private function toggle(Request $request, bool $enable): Response
    {
        $context = WorkspaceRequest::context($request);
        $recurring = $this->rule($context, $request);
        try {
            $this->service->setEnabled($context, $recurring, $enable);
        } catch (AutomationException $e) {
            return $this->http->refuse($context, $e, AutomationHttp::base($context) . '/recurring');
        }
        $this->flash->toast($enable ? 'Повтор снова работает.' : 'Повтор остановлен.');

        return Response::redirect(AutomationHttp::base($context) . '/recurring');
    }

    private function rule(WorkspaceContext $context, Request $request): RecurringPost
    {
        return $this->repository->find($context, AutomationHttp::id($request, 'ruleId')) ?? throw new HttpException(404, 'Not found');
    }

    /**
     * "Каждый день в 10:00", "По пн, ср в 10:00 до 31.12.2026"…
     */
    public static function summary(RecurrenceRule $rule): string
    {
        $text = match ($rule->frequency) {
            RecurrenceRule::DAILY => 'Каждый день',
            RecurrenceRule::WEEKDAYS => 'По ' . implode(', ', array_map(static fn (int $d): string => mb_strtolower(self::DAYS[$d] ?? ''), $rule->weekdays)),
            RecurrenceRule::EVERY_N => $rule->intervalDays === 1 ? 'Каждый день' : 'Каждые ' . $rule->intervalDays . ' ' . self::plural($rule->intervalDays),
            RecurrenceRule::MONTHLY => 'Каждого ' . (int) $rule->monthDay . ' числа',
            default => 'Повтор',
        };
        $text .= ' в ' . $rule->time;
        if ($rule->frequency === RecurrenceRule::MONTHLY && (int) $rule->monthDay > 28) {
            $text .= ' (в коротких месяцах — в последний день)';
        }
        if ($rule->endsOn !== null) {
            $text .= ', до ' . substr($rule->endsOn, 8, 2) . '.' . substr($rule->endsOn, 5, 2) . '.' . substr($rule->endsOn, 0, 4);
        }
        if ($rule->maxCount !== null) {
            $text .= ', не больше ' . $rule->maxCount . ' ' . self::times($rule->maxCount);
        }

        return $text;
    }

    private static function plural(int $n): string
    {
        return $n % 10 === 1 && $n % 100 !== 11 ? 'день' : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20) ? 'дня' : 'дней');
    }

    private static function times(int $n): string
    {
        return $n % 10 === 1 && $n % 100 !== 11 ? 'раза' : 'раз';
    }
}
