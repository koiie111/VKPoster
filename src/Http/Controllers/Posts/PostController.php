<?php

declare(strict_types=1);

namespace App\Http\Controllers\Posts;

use App\Domain\Channel\ChannelRepository;
use App\Domain\Post\Post;
use App\Domain\Post\PostDraft;
use App\Domain\Post\PostException;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostService;
use App\Domain\Post\PostStatus;
use App\Domain\Post\PostTemplateRepository;
use App\Domain\Post\Publication;
use App\Domain\Post\PublicationRepository;
use App\Domain\Post\PublicationStatus;
use App\Domain\Post\ScheduleTime;
use App\Domain\Post\TextFormatter;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Http\FormFlash;
use App\Http\WorkspaceRequest;
use App\Integrations\Social\Contracts\EditableAdapter;
use App\Integrations\Social\PlatformRegistry;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Kernel\View\View;
use App\Support\Clock;
use App\Support\RuDates;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Posts: the editor (new and existing), saving as a draft, planning and publishing now, autosave and live validation for the editor,
 * the post page with its journal, moving in the calendar, cancelling, duplicating, deleting, retrying and settling publications,
 * editing a published post. Rules live in `PostService`; this class reads requests and answers pages or JSON.
 */
final class PostController
{
    public function __construct(
        private readonly View $view,
        private readonly FormFlash $flash,
        private readonly PostService $service,
        private readonly PostRepository $posts,
        private readonly PublicationRepository $publications,
        private readonly PostTemplateRepository $templates,
        private readonly ChannelRepository $channels,
        private readonly PostEditorView $editor,
        private readonly PlatformRegistry $registry,
        private readonly Permissions $permissions,
        private readonly Clock $clock,
    ) {
    }

    // ---- editor ---------------------------------------------------------------------------------------------------

    public function create(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        [$date, $time] = $this->editor->defaultSchedule($context, WorkspaceRequest::text($request->input('date')) === '' ? null : WorkspaceRequest::text($request->input('date')));
        $draft = $this->editor->blank();
        $templateId = WorkspaceRequest::text($request->input('template'));
        if ($templateId !== '') {
            $template = $this->templates->find($context, $templateId) ?? throw new HttpException(404, 'Not found');
            $draft = $template['draft'];
            $this->flash->toast('Шаблон «' . $template['name'] . '» подставлен. Проверьте текст и выберите время.', 'info');
        }

        return $this->editorResponse($context, $draft, null, $date, $time, []);
    }

    public function edit(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $post = $this->findVisible($context, $request);
        if (!$this->service->canEdit($context, $post) || !$post->status->isEditablePlan()) {
            $this->flash->toast($post->status->isEditablePlan() ? 'Менять этот пост может редактор или администратор.' : 'Этот пост уже опубликован, поэтому здесь его план не изменить.', 'info');

            return Response::redirect($this->postUrl($context, $post));
        }
        $zone = new DateTimeZone($context->timezone);
        if ($post->scheduledAt !== null) {
            $local = $post->scheduledAt->setTimezone($zone);
            [$date, $time] = [$local->format('Y-m-d'), $local->format('H:i')];
        } else {
            [$date, $time] = $this->editor->defaultSchedule($context, null);
        }

        return $this->editorResponse($context, $this->editor->draftOf($context, $post), $post, $date, $time, []);
    }

    public function store(Request $request): Response
    {
        return $this->save($request, null);
    }

    public function update(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);

        return $this->save($request, $this->findVisible($context, $request));
    }

    private function save(Request $request, ?Post $existing): Response
    {
        $context = WorkspaceRequest::context($request);
        $form = PostForm::fromRequest($request);
        $errors = [];
        try {
            $post = $this->apply($context, $existing, $form);
        } catch (PostException $e) {
            if ($e->forbidden && $existing !== null && !$this->service->canEdit($context, $existing)) {
                throw new HttpException(403, 'Forbidden');
            }
            $errors = ['form' => $e->getMessage(), 'channels' => $e->channelProblems, 'date' => $this->dateError($e)];

            return $this->editorResponse($context, $form->draft, $existing, $form->date, $form->time, $errors, 422);
        }
        $base = '/w/' . $context->workspacePublicId;
        $zone = new DateTimeZone($context->timezone);
        switch ($form->intent) {
            case 'schedule':
                $when = $post->scheduledAt === null ? '' : RuDates::full($post->scheduledAt->setTimezone($zone));
                $this->flash->toast('Пост запланирован на ' . $when . ' (' . ScheduleTime::label($context->timezone, $this->clock->now()) . ').');

                return Response::redirect($base . '/calendar?date=' . ($post->scheduledAt?->setTimezone($zone)->format('Y-m-d') ?? ''));
            case 'now':
                $this->flash->toast('Пост отправлен на публикацию. Через минуту он появится в каналах.');

                return Response::redirect($this->postUrl($context, $post));
            default:
                $this->flash->toast('Черновик сохранён.');

                return Response::redirect($base . '/posts/' . $post->publicId . '/edit');
        }
    }

    /**
     * @throws PostException
     */
    private function apply(WorkspaceContext $context, ?Post $existing, PostForm $form): Post
    {
        if ($form->intent === 'draft') {
            return $this->service->saveDraft($context, $existing, $form->draft);
        }
        if ($form->intent === 'now') {
            return $this->service->schedule($context, $existing, $form->draft, $this->clock->now(), true);
        }

        return $this->service->schedule($context, $existing, $form->draft, ScheduleTime::parse($form->date, $form->time, $context->timezone, $this->clock->now()));
    }

    private function dateError(PostException $e): ?string
    {
        $message = $e->getMessage();

        return str_contains($message, 'время') && $e->channelProblems === [] && (str_contains($message, 'прошло') || str_contains($message, 'не существует') || str_contains($message, 'Укажите дату')) ? $message : null;
    }

    /**
     * Autosave of a draft while the person types: JSON in, JSON out. Nothing is created until there is something worth keeping.
     */
    public function autosave(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $form = PostForm::fromRequest($request);
        $existing = null;
        $id = WorkspaceRequest::text($request->input('post'));
        if ($id !== '') {
            $existing = $this->posts->find($context, $id);
            if ($existing === null || !$this->service->canEdit($context, $existing)) {
                return Response::json(['ok' => false, 'message' => 'Пост не найден.'], 404);
            }
            if ($existing->status !== PostStatus::Draft) {
                return Response::json(['ok' => false, 'skipped' => true, 'message' => 'Автосохранение работает только для черновиков.'], 409);
            }
        }
        $empty = trim($form->draft->text) === '' && $form->draft->mediaIds === [] && $form->draft->variants === [];
        if ($existing === null && $empty) {
            return Response::json(['ok' => true, 'skipped' => true]);
        }
        try {
            $post = $this->service->saveDraft($context, $existing, $form->draft, false);
        } catch (PostException $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage()], $e->forbidden ? 403 : 422);
        }

        return Response::json([
            'ok' => true,
            'id' => $post->publicId,
            'edit_url' => '/w/' . $context->workspacePublicId . '/posts/' . $post->publicId . '/edit',
            'update_url' => '/w/' . $context->workspacePublicId . '/posts/' . $post->publicId,
            'saved_at' => $this->clock->now()->setTimezone(new DateTimeZone($context->timezone))->format('H:i'),
        ]);
    }

    /**
     * Live validation for the editor: what is wrong with the post for each channel. Writes nothing.
     */
    public function validate(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $form = PostForm::fromRequest($request);
        try {
            $problems = $this->service->problemsFor($context, $form->draft);
        } catch (PostException $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage(), 'problems' => (object) []]);
        }

        return Response::json(['ok' => true, 'problems' => (object) $problems]);
    }

    /**
     * Visible length of a text per channel limits is computed in the browser; this answers the one thing it cannot: how the markup
     * will go out (used by tests and by the preview of the exact text).
     */
    public function preview(Request $request): Response
    {
        $text = is_string($request->input('text')) ? $request->input('text') : '';

        return Response::json(['html' => TextFormatter::toHtml(mb_substr($text, 0, 20000)), 'plain' => TextFormatter::toPlain(mb_substr($text, 0, 20000)), 'length' => TextFormatter::visibleLength(mb_substr($text, 0, 20000))]);
    }

    // ---- the post page ----------------------------------------------------------------------------------------------

    public function show(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $post = $this->findVisible($context, $request);
        $zone = new DateTimeZone($context->timezone);
        $variants = [];
        foreach ($this->posts->variants($context, $post) as $variant) {
            $variants[$variant->id] = $variant;
        }
        $rows = [];
        $canPlan = $this->permissions->allows($context->role, 'posts.publish');
        $editableText = false;
        foreach ($this->publications->forPost($context, $post) as $publication) {
            $variant = $variants[$publication->variantId] ?? null;
            $channel = $publication->channelId === null ? null : $this->channels->findById($context, $publication->channelId);
            $row = $this->publicationRow($context, $publication, $variant === null ? 'Канал' : $variant->channelName, $variant === null ? 'vk' : $variant->platform->badgeKey(), $zone);
            $row['can_edit_text'] = $channel !== null && $publication->status === PublicationStatus::Sent && $publication->deletedAt === null && $this->registry->isEnabled($channel->platform) && $this->registry->adapter($channel->platform) instanceof EditableAdapter;
            $editableText = $editableText || $row['can_edit_text'];
            $rows[] = $row;
        }
        $planned = [];
        foreach ($variants as $variant) {
            if ($variant->channelId !== null && array_filter($rows, static fn (array $r): bool => $r['variant_id'] === $variant->id) === []) {
                $planned[] = ['name' => $variant->channelName, 'platform' => $variant->platform->badgeKey()];
            }
        }
        $editable = $this->service->canEdit($context, $post) && $post->status->isEditablePlan();
        $sent = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'sent'));

        return $this->view->response('workspace/posts/show.twig', [
            'workspace' => $context,
            'post' => $post,
            'title' => $post->title(100),
            'text' => $post->baseText,
            'html' => TextFormatter::toHtml($post->baseText),
            'status' => $post->status->badge(),
            'status_label' => $post->status->label(),
            'when' => $post->scheduledAt === null ? null : RuDates::full($post->scheduledAt->setTimezone($zone)),
            'timezone_label' => ScheduleTime::label($context->timezone, $this->clock->now()),
            'created' => RuDates::full($post->createdAt->setTimezone($zone)),
            'rows' => $rows,
            'planned' => $planned,
            'editable' => $editable,
            'can_plan' => $canPlan,
            'can_duplicate' => $this->permissions->allows($context->role, 'posts.draft'),
            'can_cancel' => $editable && $post->status === PostStatus::Scheduled,
            'can_delete' => $this->service->canEdit($context, $post) && $sent === 0 && $post->status !== PostStatus::Publishing,
            'can_edit_published' => $canPlan && $editableText,
            'media_count' => count($post->mediaIds),
            'base' => '/w/' . $context->workspacePublicId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicationRow(WorkspaceContext $context, Publication $publication, string $name, string $platform, DateTimeZone $zone): array
    {
        $attempts = [];
        foreach ($this->publications->attempts($context, $publication) as $attempt) {
            $attempts[] = [
                'number' => $attempt['attempt'],
                'outcome' => self::outcomeLabel($attempt['outcome']),
                'ok' => in_array($attempt['outcome'], ['sent', 'deleted'], true),
                'message' => $attempt['message'],
                'at' => $attempt['finished_at'] === null ? '' : RuDates::full($attempt['finished_at']->setTimezone($zone)),
                // Technical details are for administrators of the service only; owners see the plain message.
                'detail' => $context->role->value === 'owner' || $context->role->value === 'admin' ? $attempt['detail'] : null,
            ];
        }

        return [
            'id' => $publication->publicId,
            'variant_id' => $publication->variantId,
            'name' => $name,
            'platform' => $platform,
            'status' => $publication->status->value,
            'badge' => $publication->status->badge(),
            'label' => $publication->status->label(),
            'at' => RuDates::full(($publication->sentAt ?? $publication->dueAt)->setTimezone($zone)),
            'url' => $publication->externalUrl,
            'error' => in_array($publication->status, [PublicationStatus::Failed, PublicationStatus::Unknown, PublicationStatus::Cancelled], true) ? $publication->errorMessage : null,
            'retry_at' => $publication->status === PublicationStatus::Queued && $publication->attempt > 0 ? RuDates::full($publication->runAt->setTimezone($zone)) : null,
            'delete_at' => $publication->deleteAt === null || $publication->deletedAt !== null ? null : RuDates::full($publication->deleteAt->setTimezone($zone)),
            'deleted' => $publication->deletedAt !== null,
            'delete_error' => $publication->deleteError,
            'pinned' => $publication->pinned,
            'attempts' => $attempts,
        ];
    }

    private static function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            'sent' => 'Опубликовано',
            'temporary' => 'Временная ошибка, повторим',
            'rate_limited' => 'Соцсеть просит подождать',
            'permanent', 'rejected' => 'Соцсеть отказала',
            'auth' => 'Нет доступа к каналу',
            'unknown', 'unknown_outcome' => 'Неизвестно, вышел ли пост',
            'failed' => 'Не удалось',
            'cancelled' => 'Отменено',
            'deleted' => 'Удалено из соцсети',
            'delete_failed' => 'Удалить не удалось',
            'pin_failed' => 'Закрепить не удалось',
            'comment_failed' => 'Комментарий не оставлен',
            default => $outcome,
        };
    }

    // ---- actions --------------------------------------------------------------------------------------------------

    /**
     * The calendar's drag and drop: keep the time of day, change the date (or take a full date and time).
     */
    public function move(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $post = $this->posts->find($context, $this->param($request, 'postId'));
        if ($post === null || !$this->service->canSee($context, $post)) {
            return Response::json(['ok' => false, 'message' => 'Пост не найден.'], 404);
        }
        $zone = new DateTimeZone($context->timezone);
        $date = WorkspaceRequest::text($request->input('date'));
        $time = WorkspaceRequest::text($request->input('time'));
        if ($time === '') {
            $time = ($post->scheduledAt ?? $this->clock->now())->setTimezone($zone)->format('H:i');
        }
        try {
            $at = ScheduleTime::parse($date, $time, $context->timezone, $this->clock->now());
            $moved = $this->service->reschedule($context, $post, $at);
        } catch (PostException $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage()], $e->forbidden ? 403 : 422);
        }

        return Response::json(['ok' => true, 'label' => $moved->scheduledAt === null ? '' : RuDates::full($moved->scheduledAt->setTimezone($zone))]);
    }

    public function cancel(Request $request): Response
    {
        return $this->act($request, function (WorkspaceContext $context, Post $post): string {
            $this->service->cancel($context, $post);

            return 'Публикация отменена. Пост остался в истории, его можно запланировать заново.';
        });
    }

    public function duplicate(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $post = $this->findVisible($context, $request);
        try {
            $copy = $this->service->duplicate($context, $post);
        } catch (PostException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect($this->postUrl($context, $post));
        }
        $this->flash->toast('Копия сохранена как черновик. Выберите время и опубликуйте.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/posts/' . $copy->publicId . '/edit');
    }

    public function delete(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $post = $this->findVisible($context, $request);
        try {
            $this->service->delete($context, $post);
        } catch (PostException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect($this->postUrl($context, $post));
        }
        $this->flash->toast('Пост удалён.');

        return Response::redirect('/w/' . $context->workspacePublicId . '/calendar');
    }

    public function retry(Request $request): Response
    {
        return $this->actOnPublication($request, function (WorkspaceContext $context, Publication $publication): string {
            $this->service->retry($context, $publication);

            return 'Пробуем опубликовать ещё раз. Результат появится на этой странице.';
        });
    }

    public function settle(Request $request): Response
    {
        $wentOut = WorkspaceRequest::text($request->input('outcome')) === 'sent';

        return $this->actOnPublication($request, function (WorkspaceContext $context, Publication $publication) use ($wentOut): string {
            $this->service->settleUnknown($context, $publication, $wentOut);

            return $wentOut ? 'Отметили, что пост вышел.' : 'Отметили, что пост не вышел. Его можно запланировать заново.';
        });
    }

    public function removeFromNetwork(Request $request): Response
    {
        return $this->actOnPublication($request, function (WorkspaceContext $context, Publication $publication): string {
            $this->service->removeFromNetwork($context, $publication);

            return 'Пост удалён из соцсети.';
        });
    }

    public function editPublished(Request $request): Response
    {
        $context = WorkspaceRequest::context($request);
        $post = $this->findVisible($context, $request);
        try {
            $report = $this->service->editPublished($context, $post, WorkspaceRequest::text($request->input('text')));
        } catch (PostException $e) {
            $this->flash->toast($e->getMessage(), 'error');

            return Response::redirect($this->postUrl($context, $post));
        }
        $failed = array_filter($report, static fn (array $r): bool => !$r['ok']);
        if ($failed === []) {
            $this->flash->toast('Текст изменён во всех опубликованных копиях.');
        } else {
            foreach ($failed as $item) {
                $this->flash->toast($item['channel'] . ': ' . $item['message'], 'error');
            }
        }

        return Response::redirect($this->postUrl($context, $post));
    }

    // ---- helpers --------------------------------------------------------------------------------------------------

    /**
     * @param callable(WorkspaceContext, Post): string $action returns the toast
     */
    private function act(Request $request, callable $action): Response
    {
        $context = WorkspaceRequest::context($request);
        $post = $this->findVisible($context, $request);
        try {
            $this->flash->toast($action($context, $post));
        } catch (PostException $e) {
            if ($e->forbidden) {
                throw new HttpException(403, 'Forbidden');
            }
            $this->flash->toast($e->getMessage(), 'error');
        }

        return Response::redirect($this->postUrl($context, $post));
    }

    /**
     * @param callable(WorkspaceContext, Publication): string $action returns the toast
     */
    private function actOnPublication(Request $request, callable $action): Response
    {
        $context = WorkspaceRequest::context($request);
        $post = $this->findVisible($context, $request);
        $publication = $this->publications->find($context, $this->param($request, 'publicationId'));
        if ($publication === null || $publication->postId !== $post->id) {
            throw new HttpException(404, 'Not found');
        }
        try {
            $this->flash->toast($action($context, $publication));
        } catch (PostException $e) {
            if ($e->forbidden) {
                throw new HttpException(403, 'Forbidden');
            }
            $this->flash->toast($e->getMessage(), 'error');
        }

        return Response::redirect($this->postUrl($context, $post));
    }

    private function findVisible(WorkspaceContext $context, Request $request): Post
    {
        $post = $this->posts->find($context, $this->param($request, 'postId'));
        if ($post === null || !$this->service->canSee($context, $post)) {
            throw new HttpException(404, 'Not found');
        }

        return $post;
    }

    private function postUrl(WorkspaceContext $context, Post $post): string
    {
        return '/w/' . $context->workspacePublicId . '/posts/' . $post->publicId;
    }

    private function param(Request $request, string $name): string
    {
        $params = $request->attribute('route_params');

        return is_array($params) && is_string($params[$name] ?? null) ? $params[$name] : '';
    }

    /**
     * @param array<string, mixed> $errors
     */
    private function editorResponse(WorkspaceContext $context, PostDraft $draft, ?Post $post, string $date, string $time, array $errors, int $status = 200): Response
    {
        $data = $this->editor->build($context, $draft, $post, $date, $time, [
            'errors' => $errors,
            'can_publish' => $this->permissions->allows($context->role, 'posts.publish'),
            'can_template' => $this->permissions->allows($context->role, 'posts.draft'),
            'templates' => array_map(static fn (array $t): array => ['id' => $t['id'], 'name' => $t['name']], array_slice($this->templates->all($context), 0, 30)),
            'action' => '/w/' . $context->workspacePublicId . '/posts' . ($post === null ? '' : '/' . $post->publicId),
            'autosave' => $post === null || $post->status === PostStatus::Draft,
        ]);

        return $this->view->response('workspace/posts/editor.twig', $data)->withStatus($status);
    }
}
