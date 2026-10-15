<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Entitlements;
use App\Domain\Notification\Notifier;
use App\Domain\Post\Post;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostStatus;
use App\Domain\Workspace\ChannelAccessRepository;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Support\Clock;
use App\Support\DbTime;

/** Revision-bound approvals on existing posts; scheduling remains exclusively in PostService. */
final class ApprovalWorkflow
{
    public function __construct(private readonly Connection $db, private readonly PostRepository $posts, private readonly Permissions $permissions, private readonly ChannelAccessRepository $access, private readonly Entitlements $entitlements, private readonly AuditLog $audit, private readonly Notifier $notifier, private readonly WorkspaceRepository $workspaces, private readonly Clock $clock)
    {
    }
    /** @return array<string,mixed> */
    public function settings(int $workspace): array
    {
        $r = $this->db->table('workspace_approval_settings')->where('workspace_id', '=', $workspace)->first();
        return $r === null ? ['required' => false, 'approvers' => [], 'brand_name' => '', 'brand_color' => '#4338ca', 'brand_logo' => ''] : ['required' => (bool) $r['required'], 'approvers' => json_decode($r['approvers_json'], true, 32, JSON_THROW_ON_ERROR), 'brand_name' => $r['brand_name'] ?? '', 'brand_color' => $r['brand_color'] ?? '#4338ca', 'brand_logo' => $r['brand_logo'] ?? ''];
    }
    /** @param list<string> $members Member public IDs, empty means owner/admin/editor. */
    public function configure(WorkspaceContext $ctx, bool $required, array $members, string $name = '', string $color = '#4338ca', string $logo = ''): void
    {
        if (!$this->permissions->allows($ctx->role, 'workspace.settings')) {
            throw new HttpException(403, 'Forbidden');
        }
        if ($required) {
            $this->entitlements->assertFeature($ctx->workspaceId, 'approvals', 'Согласование');
        }
        $ids = [];
        foreach (array_unique($members) as $publicId) {
            $r = $this->db->table('workspace_members')->where('workspace_id', '=', $ctx->workspaceId)->where('public_id', '=', $publicId)->first() ?? throw new HttpException(422, 'Участник не найден.');
            $ids[] = (int) $r['user_id'];
        }
        if (mb_strlen($name) > 100 || preg_match('/^#[0-9a-fA-F]{6}$/D', $color) !== 1) {
            throw new HttpException(422, 'Проверьте название и цвет бренда.');
        }
        if ($name !== '' || $logo !== '' || $color !== '#4338ca') {
            $this->entitlements->assertFeature($ctx->workspaceId, 'white_label', 'Свой бренд');
        }
        if ($logo !== '' && $this->db->table('media')->where('workspace_id', '=', $ctx->workspaceId)->where('public_id', '=', $logo)->where('kind', '=', 'image')->first() === null) {
            throw new HttpException(422, 'Логотип не найден в медиатеке.');
        }
        $this->db->execute('INSERT INTO workspace_approval_settings (workspace_id, required, approvers_json, brand_name, brand_color, brand_logo, updated_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE required=VALUES(required),approvers_json=VALUES(approvers_json),brand_name=VALUES(brand_name),brand_color=VALUES(brand_color),brand_logo=VALUES(brand_logo),updated_at=VALUES(updated_at)', [$ctx->workspaceId, $required, json_encode($ids, JSON_THROW_ON_ERROR), $name, strtolower($color), $logo, DbTime::format($this->clock->now())]);
        $this->audit->record('approval.settings_updated', $ctx->userId, 'workspace', $ctx->workspacePublicId, [], $ctx->workspaceId);
    }
    public function visible(WorkspaceContext $ctx, Post $post): bool
    {
        return $post->workspaceId === $ctx->workspaceId && $this->posts->visibleTo($ctx, $post, $this->access->allowed($ctx, $ctx->userId));
    }
    public function requires(Post $post): bool
    {
        $s = $this->settings($post->workspaceId);
        if ($s['required'] !== true) {
            return false;
        }
        $author = $this->db->table('workspace_members')->where('workspace_id', '=', $post->workspaceId)->where('user_id', '=', $post->authorId ?? 0)->first();
        return $author === null || $author['role'] === 'author';
    }
    /** @param array<string,mixed>|null $settings Preloaded settings, to avoid a query per member. */
    public function canApprove(WorkspaceContext $ctx, Post $post, ?array $settings = null): bool
    {
        if (!$this->visible($ctx, $post) || $post->authorId === $ctx->userId) {
            return false;
        }
        $allowed = $this->access->allowed($ctx, $ctx->userId);
        if ($allowed !== null) {
            foreach ($this->posts->variants($ctx, $post) as $variant) {
                if (!in_array($variant->channelId, $allowed, true)) {
                    return false;
                }
            }
        }
        $s = $settings ?? $this->settings($ctx->workspaceId);
        return $s['approvers'] === [] ? in_array($ctx->role->value, ['owner', 'admin', 'editor'], true) : in_array($ctx->userId, $s['approvers'], true);
    }
    /** @return array<string,mixed> Full immutable content snapshot, no schedule/technical timestamps. */
    public function snapshot(WorkspaceContext $ctx, Post $post): array
    {
        $variants = [];
        foreach ($this->posts->variants($ctx, $post) as $v) {
            $variants[] = ['channel_id' => $v->channelId, 'text' => $v->text, 'media' => $v->mediaIds, 'options' => $v->options?->toArray()];
        }
        usort($variants, static fn (array $a, array $b): int => $a['channel_id'] <=> $b['channel_id']);
        return ['text' => $post->baseText, 'media' => $post->mediaIds, 'options' => $post->options->toArray(), 'per_network' => $post->perNetwork, 'variants' => $variants];
    }
    public function revision(WorkspaceContext $ctx, Post $post): string
    {
        return hash('sha256', json_encode($this->snapshot($ctx, $post), JSON_THROW_ON_ERROR));
    }
    /** Called in PostService's post lock transaction; identical autosaves preserve approval. */
    public function saved(WorkspaceContext $ctx, Post $post, ?string $before, PostStatus $beforeStatus): Post
    {
        $hash = $this->revision($ctx, $post);
        $this->capture($ctx, $post);
        $wasApproved = $this->db->select("SELECT id FROM approvals WHERE workspace_id=? AND post_id=? AND decision='approved' LIMIT 1", [$ctx->workspaceId,$post->id]) !== [];
        if ($before !== null && (in_array($beforeStatus, [PostStatus::Approved, PostStatus::PendingApproval, PostStatus::ChangesRequested], true) || $before !== $hash && $wasApproved)) {
            $status = $before === $hash ? $beforeStatus : PostStatus::PendingApproval;
            $this->posts->setSchedule($ctx, $post, $status, null, $ctx->timezone);
            $post = $this->posts->find($ctx, $post->publicId) ?? $post;
            if ($status !== $beforeStatus || $before !== $hash) {
                $this->notify($ctx, $post, 'approval.revision_changed');
            }
        }
        return $post;
    }
    /** Record content history without changing publishing status; caller holds the post lock. */
    public function capture(WorkspaceContext $ctx, Post $post): void
    {
        $hash = $this->revision($ctx, $post);
        $prior = $this->db->select('SELECT revision_hash FROM post_versions WHERE workspace_id=? AND post_id=? ORDER BY id DESC LIMIT 1', [$ctx->workspaceId, $post->id])[0] ?? null;
        if ($prior === null || $prior['revision_hash'] !== $hash) {
            $this->db->table('post_versions')->insert(['workspace_id' => $ctx->workspaceId, 'post_id' => $post->id, 'revision_hash' => $hash, 'snapshot_json' => json_encode($this->snapshot($ctx, $post), JSON_THROW_ON_ERROR), 'actor_id' => $ctx->userId, 'created_at' => DbTime::format($this->clock->now())]);
        }
    }
    /** Prevent ABA edits from reusing an old decision even when text is restored; caller has locked the post. */
    public function assertReady(WorkspaceContext $ctx, Post $post): void
    {
        if ($this->requires($post) && !in_array($post->status, [PostStatus::Approved,PostStatus::Scheduled], true)) {
            throw new HttpException(403, 'Согласуйте актуальную версию поста.');
        }
    }
    /** Existing publisher preflight, including plans created before the workspace enabled approval. */
    public function publicationProblem(Post $post): ?string
    {
        if (!$this->requires($post)) {
            return null;
        }
        $ws = $this->workspaces->findById($post->workspaceId);
        $member = $ws === null ? null : $this->workspaces->membership($ws->id, $ws->ownerId);
        if ($ws === null || $member === null) {
            return 'Пространство недоступно.';
        }
        try {
            $this->assertSchedule(WorkspaceContext::from($ws, $member), $post);
            return null;
        } catch (HttpException) {
            return 'Актуальная версия поста не согласована.';
        }
    }
    /** Preference-aware scheduled notification for a reviewed post, on the existing Queue. */
    public function scheduled(WorkspaceContext $ctx, Post $post): void
    {
        if ($this->db->select('SELECT id FROM approvals WHERE workspace_id=? AND post_id=? LIMIT 1', [$ctx->workspaceId,$post->id]) !== []) {
            $this->notify($ctx, $post, 'approval.scheduled');
        }
    }
    /** Called after candidate content is persisted but before publications are created, in the same transaction. */
    public function assertSchedule(WorkspaceContext $ctx, Post $post): void
    {
        $decision = $this->db->select('SELECT revision_hash,decision FROM approvals WHERE workspace_id=? AND post_id=? ORDER BY id DESC LIMIT 1', [$ctx->workspaceId, $post->id])[0] ?? null;
        if ($decision === null && !$this->requires($post)) {
            return;
        }
        if ($decision === null || $decision['decision'] !== 'approved' || !hash_equals((string) $decision['revision_hash'], $this->revision($ctx, $post))) {
            throw new HttpException(403, 'Пост автора ещё не согласован.');
        }
    }
    public function submit(WorkspaceContext $ctx, Post $post): void
    {
        $this->db->transaction(function () use ($ctx, $post): void {
            $p = $this->posts->lock($ctx, $post) ?? throw new HttpException(404, 'Not found');
            if (!$this->visible($ctx, $p) || (!$this->permissions->allows($ctx->role, 'posts.publish') && $p->authorId !== $ctx->userId)) {
                throw new HttpException(403, 'Forbidden');
            }
            if (!in_array($p->status, [PostStatus::Draft, PostStatus::ChangesRequested], true)) {
                throw new HttpException(409, 'Пост уже отправлен на согласование или публикацию.');
            }
            $this->entitlements->assertFeature($ctx->workspaceId, 'approvals', 'Согласование');
            $this->capture($ctx, $p);
            $this->posts->setSchedule($ctx, $p, PostStatus::PendingApproval, null, $ctx->timezone);
            $this->notify($ctx, $p, 'approval.submitted');
        });
    }
    public function decide(WorkspaceContext $ctx, Post $post, string $revision, bool $approve): void
    {
        if (!$this->canApprove($ctx, $post)) {
            throw new HttpException(403, 'Forbidden');
        }
        $this->decision($ctx, $post, $revision, $approve, $ctx->userId, null);
    }
    /** Authorized guest boundary; link identity is separately revalidated under lock by GuestLinks. */
    public function guestDecision(WorkspaceContext $ctx, Post $post, string $revision, int $link): void
    {
        // The link carries its creator's authority: a creator outside the approvers list (or the post's own author)
        // cannot approve through a guest, and a guest never overrides a requested change.
        if (!$this->canApprove($ctx, $post)) {
            throw new HttpException(403, 'Создатель ссылки не может согласовывать этот пост.');
        }
        $this->decision($ctx, $post, $revision, true, null, $link, [PostStatus::PendingApproval, PostStatus::Approved]);
    }
    /** @param list<PostStatus> $statuses */
    private function decision(WorkspaceContext $ctx, Post $post, string $revision, bool $approve, ?int $actor, ?int $link, array $statuses = [PostStatus::PendingApproval, PostStatus::Approved, PostStatus::ChangesRequested]): void
    {
        $this->db->transaction(function () use ($ctx, $post, $revision, $approve, $actor, $link, $statuses): void {
            $p = $this->posts->lock($ctx, $post) ?? throw new HttpException(404, 'Not found');
            if (!hash_equals($this->revision($ctx, $p), $revision) || !in_array($p->status, $statuses, true)) {
                throw new HttpException(409, 'Пост изменился или не ожидает согласования. Обновите страницу.');
            }
            $next = $approve ? PostStatus::Approved : PostStatus::ChangesRequested;
            if ($p->status === $next) {
                return;
            }
            $this->db->table('approvals')->insert(['workspace_id' => $ctx->workspaceId, 'post_id' => $p->id, 'revision_hash' => $revision, 'decision' => $next->value, 'actor_id' => $actor, 'guest_link_id' => $link, 'created_at' => DbTime::format($this->clock->now())]);
            $this->posts->setSchedule($ctx, $p, $next, null, $ctx->timezone);
            $this->notify($ctx, $p, $approve ? 'approval.approved' : 'approval.changes_requested', $actor, $link !== null);
        });
    }
    /** @return list<array<string,mixed>> */
    public function versions(WorkspaceContext $ctx, Post $post): array
    {
        if (!$this->visible($ctx, $post)) {
            throw new HttpException(404, 'Not found');
        }
        $rows = $this->db->select('SELECT id,snapshot_json,created_at FROM post_versions WHERE workspace_id=? AND post_id=? ORDER BY id DESC LIMIT 50', [$ctx->workspaceId, $post->id]);
        foreach ($rows as $i => &$row) {
            $text = $this->describe(json_decode($row['snapshot_json'], true, 32, JSON_THROW_ON_ERROR));
            $previous = isset($rows[$i + 1]) ? $this->describe(json_decode($rows[$i + 1]['snapshot_json'], true, 32, JSON_THROW_ON_ERROR)) : '';
            $row['diff'] = TextDiff::lines($previous, $text);
            unset($row['snapshot_json']);
        }
        unset($row);
        return $rows;
    }
    /**
     * Plain-text rendering of everything the revision hash covers, so the diff shows media, options and variants too.
     * @param array<string,mixed> $snapshot
     */
    private function describe(array $snapshot): string
    {
        $json = static fn (mixed $v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $lines = explode(chr(10), (string) ($snapshot['text'] ?? ''));
        $lines[] = 'Медиа: ' . implode(', ', (array) ($snapshot['media'] ?? []));
        $lines[] = 'Параметры: ' . $json($snapshot['options'] ?? null) . ' · по сетям: ' . $json($snapshot['per_network'] ?? null);
        foreach ((array) ($snapshot['variants'] ?? []) as $v) {
            $channel = 'Канал ' . ($v['channel_id'] ?? '?');
            $lines[] = $channel . ': ' . str_replace(chr(10), ' ', (string) ($v['text'] ?? '(общий текст)'));
            $lines[] = $channel . ', медиа: ' . (($v['media'] ?? null) === null ? '(общие)' : implode(', ', (array) $v['media']));
            $lines[] = $channel . ', параметры: ' . $json($v['options'] ?? null);
        }
        return implode(chr(10), $lines);
    }
    /** Scheduled author posts with no approval yet: they stop publishing once the policy is on. */
    public function unapprovedScheduled(int $workspace): int
    {
        return (int) $this->db->select("SELECT COUNT(*) AS c FROM posts p JOIN workspace_members m ON m.workspace_id=p.workspace_id AND m.user_id=p.author_id WHERE p.workspace_id=? AND p.status='scheduled' AND m.role='author' AND NOT EXISTS (SELECT 1 FROM approvals a WHERE a.workspace_id=p.workspace_id AND a.post_id=p.id AND a.decision='approved')", [$workspace])[0]['c'];
    }
    /** @return list<array<string,mixed>> */
    public function members(WorkspaceContext $ctx): array
    {
        return $this->db->select('SELECT m.public_id,m.user_id,u.name FROM workspace_members m JOIN users u ON u.id=m.user_id WHERE m.workspace_id=? ORDER BY u.name,m.user_id', [$ctx->workspaceId]);
    }
    private function notify(WorkspaceContext $ctx, Post $post, string $event, ?int $actor = null, bool $guest = false): void
    {
        $this->audit->record($event, $guest ? null : ($actor ?? $ctx->userId), 'post', $post->publicId, [], $ctx->workspaceId);
        $workspace = $this->workspaces->findById($ctx->workspaceId) ?? throw new HttpException(404, 'Not found');
        $settings = $this->settings($ctx->workspaceId);
        foreach ($this->members($ctx) as $row) {
            $member = $this->workspaces->membership($workspace->id, (int) $row['user_id']);
            if ($member === null) {
                continue;
            }
            $target = WorkspaceContext::from($workspace, $member);
            if ($this->visible($target, $post) && ($post->authorId === $target->userId || $this->canApprove($target, $post, $settings))) {
                $this->notifier->approvalNotice($target->userId, $post, $ctx->workspacePublicId, $event);
            }
        }
    }
}
