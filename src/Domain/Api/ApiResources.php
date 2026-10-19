<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Domain\Analytics\PostAnalytics;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Media\{MediaFetcher, MediaService};
use App\Domain\Post\{Post, PostDraft, PostOptions, PostVariant, Publication, VariantInput, PostRepository, PostService, PublicationRepository};
use App\Domain\Workspace\Permissions;
use App\Kernel\Database\Connection;
use App\Kernel\Http\{Request, UploadedFile};

/** Public resource mapping over the existing workspace-scoped domain services; no publishing implementation here. */
final class ApiResources
{
    public function __construct(private readonly Connection $db, private readonly PostRepository $posts, private readonly PostService $service, private readonly PublicationRepository $publications, private readonly ChannelRepository $channels, private readonly MediaService $media, private readonly MediaFetcher $fetcher, private readonly PostAnalytics $analytics, private readonly Cursor $cursor, private readonly Permissions $permissions)
    {
    }

    /** Replaying a stored result must still honor today's role and channel access. */
    public function authorizeReplay(ApiIdentity $who, Request $request, \App\Kernel\Http\Response $response): void
    {
        $route = $request->attribute('route');
        if (!$route instanceof \App\Kernel\Http\Route) {
            throw new ApiException(404, 'not_found', 'Ресурс не найден.');
        }
        $action = $route->handler[1];
        $this->ability($who, $action === 'media' ? 'media:write' : 'posts:write', match ($action) {
            'media' => 'media.upload',
            'schedule', 'unschedule' => 'posts.publish',
            default => 'posts.draft',
        });
        if ($action !== 'media' && $action !== 'delete') {
            $body = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
            $post = $this->find($who, (string) $body['data']['id']);
            if (!$this->service->canEdit($who->context, $post)) {
                throw new ApiException(403, 'forbidden', 'Недостаточно прав участника.');
            }
        }
    }

    /** @return array<string,mixed> */
    public function channels(ApiIdentity $who, Request $request): array
    {
        $this->ability($who, 'channels:read', 'channels.view');
        $rows = [];
        foreach ($this->channels->all($who->context, $this->service->allowedChannels($who->context)) as $channel) {
            $rows[] = ['id' => $channel->publicId, 'name' => $channel->displayName(), 'platform' => $channel->platform->value, 'status' => $channel->status->value];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        return $this->page($who, 'channels', $request, $rows);
    }

    /** @return array<string,mixed> */
    public function posts(ApiIdentity $who, Request $request): array
    {
        $this->ability($who, 'posts:read', 'calendar.view');
        $limit = $this->limit($request);
        $after = $this->cursor->decode($who, 'posts', self::text($request->query['cursor'] ?? ''));
        $workspace = $who->context->workspaceId;
        $allowed = $this->service->allowedChannels($who->context);
        $rows = $this->db->select('SELECT * FROM posts WHERE workspace_id=? AND public_id>? ORDER BY public_id LIMIT 200', [$workspace, $after]);
        $data = [];
        $last = $after;
        // Related rows are loaded once per chunk of $limit posts instead of once per post.
        foreach (array_chunk($rows, max(1, $limit)) as $chunk) {
            $posts = array_map(PostRepository::hydrate(...), $chunk);
            $ids = array_map(static fn (Post $p): int => $p->id, $posts);
            $variants = $this->variantsOf($workspace, $ids);
            $publications = $this->publicationsOf($workspace, $ids);
            $channels = $this->channelIds($workspace);
            foreach ($posts as $post) {
                $last = $post->publicId;
                if ($this->visibleWith($allowed, $variants[$post->id] ?? [])) {
                    $data[] = $this->render($post, $variants[$post->id] ?? [], $channels, $publications[$post->id] ?? []);
                    if (count($data) === $limit) {
                        break 2;
                    }
                }
            }
        }
        $more = $last !== '' && $this->db->select('SELECT id FROM posts WHERE workspace_id=? AND public_id>? LIMIT 1', [$workspace, $last]) !== [];
        return ['data' => $data, 'next_cursor' => $more ? $this->cursor->encode($who, 'posts', $last) : null];
    }

    /** @param list<int> $ids
     * @return array<int,list<PostVariant>>
     */
    private function variantsOf(int $workspace, array $ids): array
    {
        $grouped = [];
        foreach ($this->db->select('SELECT * FROM post_variants WHERE workspace_id=? AND post_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id', [$workspace, ...$ids]) as $row) {
            $grouped[(int) $row['post_id']][] = PostRepository::hydrateVariant($row);
        }
        return $grouped;
    }

    /** @param list<int> $ids
     * @return array<int,list<Publication>>
     */
    private function publicationsOf(int $workspace, array $ids): array
    {
        $grouped = [];
        foreach ($this->db->select('SELECT * FROM publications WHERE workspace_id=? AND post_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id', [$workspace, ...$ids]) as $row) {
            $grouped[(int) $row['post_id']][] = PublicationRepository::hydrate($row);
        }
        return $grouped;
    }

    /** @return array<int,string> channel row id => public id */
    private function channelIds(int $workspace): array
    {
        $map = [];
        foreach ($this->db->select('SELECT id,public_id FROM channels WHERE workspace_id=?', [$workspace]) as $row) {
            $map[(int) $row['id']] = (string) $row['public_id'];
        }
        return $map;
    }

    public function find(ApiIdentity $who, string $id): Post
    {
        $post = $this->posts->find($who->context, $id);
        if ($post === null || !$this->visible($who, $post)) {
            throw new ApiException(404, 'not_found', 'Пост не найден.');
        }
        return $post;
    }

    private function visible(ApiIdentity $who, Post $post): bool
    {
        return $this->service->canSee($who->context, $post)
            && $this->visibleWith($this->service->allowedChannels($who->context), $this->posts->variants($who->context, $post));
    }

    /**
     * A public resource must never expose a mixed allowed/disallowed set of variants; a restricted member needs at least one
     * variant, all of them in allowed channels.
     *
     * @param list<int>|null $allowed
     * @param list<PostVariant> $variants
     */
    private function visibleWith(?array $allowed, array $variants): bool
    {
        if ($allowed === null) {
            return true;
        }
        if ($variants === []) {
            return false;
        }
        foreach ($variants as $variant) {
            if (!in_array($variant->channelId, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    public function show(ApiIdentity $who, string $id): array
    {
        $this->ability($who, 'posts:read', 'calendar.view');
        return $this->present($who, $this->find($who, $id));
    }

    /** @return array<string,mixed> */
    public function save(ApiIdentity $who, Request $request, ?string $id = null): array
    {
        $this->ability($who, 'posts:write', 'posts.draft');
        $existing = $id === null ? null : $this->find($who, $id);
        $base = $existing === null ? new PostDraft('', [], new PostOptions(), false, []) : $this->service->draftOf($who->context, $existing);
        $body = $request->body;
        $text = array_key_exists('text', $body) ? self::text($body['text']) : $base->text;
        $media = array_key_exists('media_ids', $body) ? self::strings($body['media_ids']) : $base->mediaIds;
        $options = array_key_exists('options', $body) ? PostOptions::fromArray(self::object($body['options'])) : $base->options;
        $variants = $base->variants;
        $custom = $base->perNetwork;
        if (array_key_exists('channels', $body)) {
            $variants = array_map(static fn (string $v): VariantInput => new VariantInput($v), self::strings($body['channels']));
            $custom = false;
        }
        if (array_key_exists('variants', $body)) {
            if (!is_array($body['variants']) || !array_is_list($body['variants']) || count($body['variants']) > 100) {
                throw new ApiException(422, 'validation_error', 'Проверьте варианты публикации.');
            }
            $variants = [];
            foreach ($body['variants'] as $item) {
                $v = self::object($item);
                $variants[] = new VariantInput(self::text($v['channel_id'] ?? ''), isset($v['text']) ? self::text($v['text']) : null, isset($v['media_ids']) ? self::strings($v['media_ids']) : null, isset($v['options']) ? PostOptions::fromArray(self::object($v['options'])) : null);
            }
            $custom = true;
        }
        $post = $this->service->saveDraft($who->context, $existing, new PostDraft($text, $media, $options, $custom, $variants));
        return $this->present($who, $post);
    }

    public function delete(ApiIdentity $who, string $id): void
    {
        $this->ability($who, 'posts:write', 'posts.draft');
        $this->service->delete($who->context, $this->find($who, $id));
    }

    /** @return array<string,mixed> */
    public function schedule(ApiIdentity $who, string $id, Request $request): array
    {
        $this->ability($who, 'posts:write', 'posts.publish');
        $value = self::text($request->body['scheduled_at'] ?? '');
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
                throw new \RuntimeException();
            }
            $at = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new \RuntimeException();
            }
        } catch (\Throwable) {
            throw new ApiException(422, 'validation_error', 'Передайте scheduled_at в ISO 8601 с часовым поясом.');
        }
        return $this->present($who, $this->service->planExisting($who->context, $this->find($who, $id), $at));
    }

    /** @return array<string,mixed> */
    public function unschedule(ApiIdentity $who, string $id): array
    {
        $this->ability($who, 'posts:write', 'posts.publish');
        return $this->present($who, $this->service->unschedule($who->context, $this->find($who, $id)));
    }

    /** @return array<string,mixed> */
    public function publication(ApiIdentity $who, string $id): array
    {
        $this->ability($who, 'posts:read', 'calendar.view');
        $p = $this->publications->find($who->context, $id) ?? throw new ApiException(404, 'not_found', 'Публикация не найдена.');
        $post = $this->posts->findById($who->context, $p->postId) ?? throw new ApiException(404, 'not_found', 'Публикация не найдена.');
        $this->find($who, $post->publicId);
        return ['id' => $p->publicId, 'post_id' => $post->publicId, 'status' => $p->status->value, 'scheduled_at' => $p->dueAt->format(DATE_ATOM), 'sent_at' => $p->sentAt?->format(DATE_ATOM), 'url' => $p->externalUrl];
    }

    /** @return array<string,mixed> */
    public function upload(ApiIdentity $who, Request $request): array
    {
        $this->ability($who, 'media:write', 'media.upload');
        $temporary = null;
        try {
            $file = $request->files['file'] ?? null;
            if ($file instanceof UploadedFile && $file->isValid()) {
                $result = $this->media->upload($who->context, $file->path, $file->clientName);
            } else {
                $download = $this->fetcher->fetch(self::text($request->body['url'] ?? ''));
                $temporary = $download['path'];
                $result = $this->media->upload($who->context, $temporary, $download['name']);
            }
            $m = $result->media;
            return ['id' => $m->publicId, 'name' => $m->originalName, 'mime' => $m->mime, 'size_bytes' => $m->size, 'width' => $m->width, 'height' => $m->height, 'duplicate' => $result->duplicate];
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }
    }

    /** @return array<string,mixed> */
    public function stats(ApiIdentity $who, Request $request): array
    {
        $this->ability($who, 'stats:read', 'analytics.view');
        $r = $this->analytics->report($who->context, self::text($request->query['from'] ?? ''), self::text($request->query['to'] ?? ''), self::text($request->query['channel'] ?? ''));
        $channels = [];
        $names = [];
        foreach ($r['channels'] as $channel) {
            $names[$channel->id] = $channel->publicId;
        }
        foreach ($r['by_channel'] as $id => $totals) {
            $channels[] = ['channel_id' => $names[$id], 'totals' => $totals];
        }
        $followers = [];
        foreach ($r['followers'] as $row) {
            $followers[] = ['channel_id' => $names[$row['channel_id']], 'observed_at' => $row['observed_at'], 'subscribers' => $row['followers']];
        }
        return ['from' => $r['from'], 'to' => $r['to'], 'timezone' => $r['timezone'], 'totals' => $r['totals'], 'daily' => $r['daily'], 'channels' => $channels, 'platforms' => $r['platforms'], 'best_time' => $r['best'], 'subscribers' => $followers];
    }

    /** @return array<string,mixed> */
    private function present(ApiIdentity $who, Post $post): array
    {
        $variants = $this->posts->variants($who->context, $post);
        $channels = [];
        foreach ($variants as $v) {
            if ($v->channelId !== null && !isset($channels[$v->channelId])) {
                $channel = $this->channels->findById($who->context, $v->channelId);
                if ($channel !== null) {
                    $channels[$v->channelId] = $channel->publicId;
                }
            }
        }
        return $this->render($post, $variants, $channels, $this->publications->forPost($who->context, $post));
    }

    /**
     * @param list<PostVariant> $variants
     * @param array<int,string> $channels channel row id => public id
     * @param list<Publication> $publications
     * @return array<string,mixed>
     */
    private function render(Post $post, array $variants, array $channels, array $publications): array
    {
        $rows = [];
        foreach ($variants as $v) {
            $rows[] = ['channel_id' => $v->channelId === null ? null : ($channels[$v->channelId] ?? null), 'platform' => $v->platform->value, 'text' => $v->text, 'media_ids' => $v->mediaIds, 'options' => $v->options?->toArray()];
        }
        return ['id' => $post->publicId, 'status' => $post->status->value, 'text' => $post->baseText, 'media_ids' => $post->mediaIds, 'options' => $post->options->toArray(), 'variants' => $rows, 'scheduled_at' => $post->scheduledAt?->format(DATE_ATOM), 'created_at' => $post->createdAt->format(DATE_ATOM), 'updated_at' => $post->updatedAt->format(DATE_ATOM), 'publications' => array_map(static fn (Publication $p): array => ['id' => $p->publicId, 'status' => $p->status->value], $publications)];
    }

    private function ability(ApiIdentity $who, string $scope, string $permission): void
    {
        $who->requireScope($scope);
        if (!$this->permissions->allows($who->context->role, $permission)) {
            throw new ApiException(403, 'forbidden', 'Недостаточно прав участника.');
        }
    }

    private function limit(Request $request): int
    {
        $raw = $request->query['limit'] ?? '25';
        if ((!is_int($raw) && (!is_string($raw) || !ctype_digit($raw))) || (int) $raw < 1 || (int) $raw > 100) {
            throw new ApiException(422, 'validation_error', 'limit должен быть от 1 до 100.');
        }
        return (int) $raw;
    }

    /** @param list<array{id:string,name:string,platform:string,status:string}> $rows
     * @return array<string,mixed>
     */
    private function page(ApiIdentity $who, string $collection, Request $request, array $rows): array
    {
        $after = $this->cursor->decode($who, $collection, self::text($request->query['cursor'] ?? ''));
        $rows = array_values(array_filter($rows, static fn (array $r): bool => strcmp($r['id'], $after) > 0));
        $limit = $this->limit($request);
        $data = array_slice($rows, 0, $limit);
        return ['data' => $data, 'next_cursor' => count($rows) > $limit ? $this->cursor->encode($who, $collection, $data[count($data) - 1]['id']) : null];
    }

    public static function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new ApiException(422, 'validation_error', 'Ожидается строка.');
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ApiException(422, 'validation_error', 'Ожидается объект.');
        }
        return $value;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 100 || count(array_filter($value, 'is_string')) !== count($value)) {
            throw new ApiException(422, 'validation_error', 'Ожидается список строк (не более 100).');
        }
        return $value;
    }
}
