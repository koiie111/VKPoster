<?php

declare(strict_types=1);

namespace App\Domain\Approval;

use App\Domain\Audit\AuditLog;
use App\Domain\Notification\Notifier;
use App\Domain\Post\Post;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/** Tenant-bound threaded plain-text comments and visibility-checked member mentions. */
final class PostComments
{
    public function __construct(private readonly Connection $db, private readonly ApprovalWorkflow $workflow, private readonly WorkspaceRepository $workspaces, private readonly Notifier $notifier, private readonly AuditLog $audit, private readonly Clock $clock)
    {
    }
    /** @return list<array<string,mixed>> */
    public function thread(WorkspaceContext $ctx, Post $post, ?int $guestLink = null): array
    {
        if (!$this->workflow->visible($ctx, $post)) {
            throw new HttpException(404, 'Not found');
        }
        // A shared link sees only its own guest comments: neither internal discussions nor other links' threads.
        return $this->db->select('SELECT c.public_id,c.body,c.created_at,p.public_id AS parent,u.name FROM post_comments c LEFT JOIN post_comments p ON p.id=c.parent_id AND p.workspace_id=c.workspace_id LEFT JOIN users u ON u.id=c.actor_id WHERE c.workspace_id=? AND c.post_id=?' . ($guestLink !== null ? ' AND c.guest_link_id=?' : '') . ' ORDER BY c.id LIMIT 500', $guestLink !== null ? [$ctx->workspaceId, $post->id, $guestLink] : [$ctx->workspaceId, $post->id]);
    }
    public function add(WorkspaceContext $ctx, Post $post, string $body, string $parent = '', ?int $guestLink = null): void
    {
        if (!$this->workflow->visible($ctx, $post)) {
            throw new HttpException(404, 'Not found');
        }
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 5000) {
            throw new HttpException(422, 'Комментарий должен содержать от 1 до 5000 символов.');
        }
        $parentId = null;
        if ($parent !== '') {
            $p = $this->db->table('post_comments')->where('workspace_id', '=', $ctx->workspaceId)->where('post_id', '=', $post->id)->where('public_id', '=', $parent)->first() ?? throw new HttpException(404, 'Not found');
            if ($guestLink !== null && (int) $p['guest_link_id'] !== $guestLink) {
                throw new HttpException(404, 'Not found');
            }
            $parentId = (int) $p['id'];
        }
        $id = (string) new Ulid();
        $this->db->table('post_comments')->insert(['public_id' => $id, 'workspace_id' => $ctx->workspaceId, 'post_id' => $post->id, 'parent_id' => $parentId, 'actor_id' => $guestLink === null ? $ctx->userId : null, 'guest_link_id' => $guestLink, 'body' => $body, 'created_at' => DbTime::format($this->clock->now())]);
        $this->audit->record('post.comment_added', $guestLink === null ? $ctx->userId : null, 'post', $post->publicId, [], $ctx->workspaceId);
        preg_match_all('/@([\p{L}\p{N}_-]{1,60})/u', $body, $found);
        $workspace = $this->workspaces->findById($ctx->workspaceId) ?? throw new HttpException(404, 'Not found');
        foreach ($this->workflow->members($ctx) as $member) {
            $handle = str_replace(' ', '_', (string) $member['name']);
            if (!in_array($handle, $found[1], true) && !in_array($member['public_id'], $found[1], true) && !($guestLink !== null && (int) $member['user_id'] === $post->authorId)) {
                continue;
            }
            $membership = $this->workspaces->membership($workspace->id, (int) $member['user_id']);
            if ($membership !== null && $this->workflow->visible(WorkspaceContext::from($workspace, $membership), $post)) {
                $this->notifier->approvalNotice((int) $member['user_id'], $post, $ctx->workspacePublicId, 'post.comment_added');
            }
        }
    }
}
