<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Entitlements;
use App\Domain\Post\Post;
use App\Domain\Post\PostRepository;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\ChannelAccessRepository;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Kernel\Security\PasswordHasher;
use App\Kernel\Security\RateLimiter;
use App\Kernel\Security\Signer;
use App\Kernel\Session\Session;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/** Signed opaque capabilities with hashed storage, live revocation/plan checks and PIN sessions, never workspace membership. */
final class GuestLinks
{
    public function __construct(private readonly Connection $db, private readonly PostRepository $posts, private readonly WorkspaceRepository $workspaces, private readonly ChannelAccessRepository $access, private readonly Permissions $permissions, private readonly Entitlements $entitlements, private readonly ApprovalWorkflow $workflow, private readonly PostComments $comments, private readonly Signer $signer, private readonly PasswordHasher $passwords, private readonly RateLimiter $limiter, private readonly Session $session, private readonly AuditLog $audit, private readonly Clock $clock)
    {
    }
    /** @param list<string> $postIds Explicit set, or frozen calendar-period set. Returns a secret URL only at creation. */
    public function create(WorkspaceContext $ctx, array $postIds, int $ttlHours, string $pin = '', string $from = '', string $to = ''): string
    {
        if (!$this->permissions->allows($ctx->role, 'guest_links.manage')) {
            throw new HttpException(403, 'Forbidden');
        }
        $this->entitlements->assertFeature($ctx->workspaceId, 'guest_links', 'Гостевые ссылки');
        if ($ttlHours < 1 || $ttlHours > 720 || ($pin !== '' && preg_match('/^[0-9]{4,12}$/D', $pin) !== 1)) {
            throw new HttpException(422, 'TTL: 1–720 часов. PIN: 4–12 цифр.');
        }
        if ($from !== '' || $to !== '') {
            $zone = new \DateTimeZone($ctx->timezone);
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone);
            $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $zone);
            if ($start === false || $end === false || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to || $end < $start || $end > $start->modify('+1 year')) {
                throw new HttpException(422, 'Проверьте период календаря, не больше года.');
            }
            $postIds = array_column($this->db->select('SELECT public_id FROM posts WHERE workspace_id=? AND scheduled_at>=? AND scheduled_at<? ORDER BY scheduled_at,id LIMIT 101', [$ctx->workspaceId, DbTime::format($start), DbTime::format($end->modify('+1 day'))]), 'public_id');
        }
        $postIds = array_values(array_unique($postIds));
        if ($postIds === [] || count($postIds) > 100) {
            throw new HttpException(422, 'Выберите от 1 до 100 постов.');
        }
        $raw = bin2hex(random_bytes(32));
        $id = (string) new Ulid();
        $this->db->transaction(function () use ($ctx, $postIds, $ttlHours, $pin, $from, $to, $raw, $id): void {
            $link = $this->db->table('guest_links')->insert(['public_id' => $id, 'workspace_id' => $ctx->workspaceId, 'token_hash' => hash('sha256', $raw), 'pin_hash' => $pin === '' ? null : $this->passwords->hash($pin), 'expires_at' => DbTime::format($this->clock->now()->modify('+' . $ttlHours . ' hours')), 'created_by' => $ctx->userId, 'created_at' => DbTime::format($this->clock->now()), 'scope_json' => json_encode(['from' => $from, 'to' => $to, 'channels' => $this->access->allowed($ctx, $ctx->userId)], JSON_THROW_ON_ERROR)]);
            foreach ($postIds as $publicId) {
                $post = $this->posts->find($ctx, $publicId) ?? throw new HttpException(404, 'Not found');
                if (!$this->workflow->visible($ctx, $post) || !$this->allChannelsVisible($ctx, $post, $this->access->allowed($ctx, $ctx->userId))) {
                    throw new HttpException(404, 'Not found');
                }
                $this->db->table('guest_link_posts')->insert(['workspace_id' => $ctx->workspaceId, 'link_id' => $link, 'post_id' => $post->id]);
            }
            $this->audit->record('guest_link.created', $ctx->userId, 'guest_link', $id, ['post_count' => count($postIds)], $ctx->workspaceId);
        });
        return '/g/' . $raw . '.' . $this->signer->sign('guest:' . $raw);
    }
    /** @return array{link:array<string,mixed>,context:WorkspaceContext,locked:bool} */
    public function resolve(string $token, bool $lock = false): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || preg_match('/^[a-f0-9]{64}$/D', $parts[0]) !== 1 || !$this->signer->verify('guest:' . $parts[0], $parts[1])) {
            throw new HttpException(404, 'Not found');
        }
        $link = $this->db->select('SELECT * FROM guest_links WHERE token_hash=?' . ($lock ? ' FOR UPDATE' : ''), [hash('sha256', $parts[0])])[0] ?? throw new HttpException(404, 'Not found');
        if ($link['revoked_at'] !== null || $link['expires_at'] <= DbTime::format($this->clock->now()) || !$this->entitlements->hasFeature((int) $link['workspace_id'], 'guest_links')) {
            throw new HttpException(404, 'Not found');
        }
        $workspace = $this->workspaces->findById((int) $link['workspace_id']) ?? throw new HttpException(404, 'Not found');
        $member = $this->workspaces->membership($workspace->id, (int) $link['created_by']) ?? throw new HttpException(404, 'Not found');
        $ctx = WorkspaceContext::from($workspace, $member);
        if (!$this->permissions->allows($ctx->role, 'guest_links.manage')) {
            throw new HttpException(404, 'Not found');
        }
        $locked = $link['pin_hash'] !== null && $this->session->get('guest.pin.' . $link['public_id']) !== hash('sha256', (string) $link['pin_hash']);
        return ['link' => $link, 'context' => $ctx, 'locked' => $locked];
    }
    public function unlock(string $token, string $pin, string $ip): void
    {
        $r = $this->resolve($token);
        $link = $r['link'];
        // Both per-link and per-IP limits resist rotating browser cookies and distributed guesses.
        foreach (['link:' . $link['public_id'], 'ip:' . hash('sha256', $ip)] as $key) {
            try {
                $limit = $this->limiter->attempt('guest-pin:' . $key, 5, 900, failClosed: true);
                if (!$limit->allowed) {
                    throw new HttpException(429, 'Слишком много попыток. Подождите 15 минут.');
                }
            } catch (HttpException $e) {
                throw $e;
            } catch (\Throwable) {
                throw new HttpException(503, 'Проверка PIN временно недоступна.');
            }
        }
        if ($link['pin_hash'] === null || !$this->passwords->verify($pin, (string) $link['pin_hash'])) {
            throw new HttpException(403, 'Неверный PIN.');
        }
        $this->session->regenerate();
        $this->session->set('guest.pin.' . $link['public_id'], hash('sha256', (string) $link['pin_hash']));
    }
    /** @return list<Post> */
    public function posts(string $token): array
    {
        $r = $this->authorized($token);
        $rows = $this->db->select('SELECT p.* FROM guest_link_posts l JOIN posts p ON p.id=l.post_id AND p.workspace_id=l.workspace_id WHERE l.workspace_id=? AND l.link_id=? ORDER BY p.scheduled_at,p.id', [$r['context']->workspaceId, $r['link']['id']]);
        $out = [];
        foreach ($rows as $row) {
            $post = PostRepository::hydrate($row);
            if ($this->allowedPost($r, $post)) {
                $out[] = $post;
            }
        }
        return $out;
    }
    /** @return array{link:array<string,mixed>,context:WorkspaceContext,locked:bool} */
    public function authorized(string $token, bool $lock = false): array
    {
        $r = $this->resolve($token, $lock);
        if ($r['locked']) {
            throw new HttpException(403, 'Введите PIN.');
        }
        return $r;
    }
    /** @param array{link:array<string,mixed>,context:WorkspaceContext,locked:bool} $r */
    private function allowedPost(array $r, Post $post): bool
    {
        $scope = json_decode($r['link']['scope_json'], true, 32, JSON_THROW_ON_ERROR);
        return $this->workflow->visible($r['context'], $post) && $this->allChannelsVisible($r['context'], $post, $scope['channels']);
    }
    /** @param list<int>|null $channels */
    private function allChannelsVisible(WorkspaceContext $ctx, Post $post, ?array $channels): bool
    {
        if ($channels === null) {
            return true;
        }
        foreach ($this->posts->variants($ctx, $post) as $variant) {
            if ($variant->channelId === null || !in_array($variant->channelId, $channels, true)) {
                return false;
            }
        }
        return true;
    }
    public function post(string $token, string $id): Post
    {
        foreach ($this->posts($token) as $post) {
            if ($post->publicId === $id) {
                return $post;
            }
        }
        throw new HttpException(404, 'Not found');
    }
    public function act(string $token, string $postId, string $revision, ?string $comment, string $parent = ''): void
    {
        $this->db->transaction(function () use ($token, $postId, $revision, $comment, $parent): void {
            $r = $this->authorized($token, lock: true); // Current row read serializes actions with revocation.
            $post = $this->post($token, $postId);
            if ($comment === null) {
                $this->workflow->guestDecision($r['context'], $post, $revision, (int) $r['link']['id']);
            } else {
                $this->comments->add($r['context'], $post, $comment, $parent, (int) $r['link']['id']);
            }
        });
    }
    public function revoke(WorkspaceContext $ctx, string $id): void
    {
        if (!$this->permissions->allows($ctx->role, 'guest_links.manage')) {
            throw new HttpException(403, 'Forbidden');
        }
        $this->db->execute('UPDATE guest_links SET revoked_at=? WHERE workspace_id=? AND public_id=?', [DbTime::format($this->clock->now()), $ctx->workspaceId, $id]);
        $this->audit->record('guest_link.revoked', $ctx->userId, 'guest_link', $id, [], $ctx->workspaceId);
    }
    /** @return list<array<string,mixed>> Secret URL and PIN hash are not listed. */
    public function history(WorkspaceContext $ctx): array
    {
        return $this->db->select('SELECT public_id,expires_at,revoked_at,created_at FROM guest_links WHERE workspace_id=? ORDER BY id DESC LIMIT 100', [$ctx->workspaceId]);
    }
}
