<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Domain\Audit\AuditLog;
use App\Domain\User\User;
use App\Domain\User\UserRepository;
use App\Kernel\Security\RateLimiter;

/**
 * Team management: invitations, role changes, removal, leaving and ownership transfer. Each action
 * checks `MemberPolicy` itself (the route-level permission is only the first gate), keeps the
 * "exactly one owner" invariant and writes an audit record.
 */
final class TeamService
{
    public const INVITE_TTL_DAYS = 7;

    public function __construct(
        private readonly WorkspaceRepository $workspaces,
        private readonly MemberRepository $members,
        private readonly InvitationRepository $invitations,
        private readonly InvitationLookup $lookup,
        private readonly MemberPolicy $policy,
        private readonly WorkspaceMailer $mailer,
        private readonly AuditLog $audit,
        private readonly RateLimiter $limiter,
    ) {
    }

    /**
     * Invite a person by email. The answer does not reveal whether the address has an account.
     * At most 20 invitations per workspace per hour.
     */
    public function invite(WorkspaceContext $context, User $actor, string $email, Role $role): TeamResult
    {
        $email = UserRepository::normalizeEmail($email);
        if (!$this->policy->canInvite($context->role, $role)) {
            return TeamResult::Forbidden;
        }
        if ($this->members->findByEmail($context, $email) !== null) {
            return TeamResult::AlreadyMember;
        }
        if (!$this->limiter->attempt('invite:' . $context->workspaceId, 20, 3600)->allowed) {
            return TeamResult::Throttled;
        }
        $token = InvitationLookup::newToken();
        $invitation = $this->invitations->create($context, $email, $role, InvitationLookup::hash($token), $context->userId, self::INVITE_TTL_DAYS * 86400);
        $this->mailer->invitation($email, $actor->name, $context->workspaceName, $role, $token, self::INVITE_TTL_DAYS);
        $this->audit->record('member.invited', $context->userId, 'invitation', $invitation->publicId, ['email' => $email, 'role' => $role->value], $context->workspaceId);

        return TeamResult::Done;
    }

    public function revokeInvitation(WorkspaceContext $context, string $invitationId): TeamResult
    {
        $invitation = $this->invitations->find($context, $invitationId);
        if ($invitation === null) {
            return TeamResult::NotFound;
        }
        if (!$this->policy->canRevokeInvitation($context->role, $invitation->role)) {
            return TeamResult::Forbidden;
        }
        if (!$this->invitations->revoke($context, $invitationId)) {
            return TeamResult::NotFound;
        }
        $this->audit->record('member.invitation_revoked', $context->userId, 'invitation', $invitation->publicId, ['email' => $invitation->email, 'role' => $invitation->role->value], $context->workspaceId);

        return TeamResult::Done;
    }

    /**
     * What an open invitation offers, for the accept page: the invitation and its workspace. Null for a bad, used, revoked or expired link.
     *
     * @return array{invitation: Invitation, workspace: Workspace}|null
     */
    public function preview(string $token): ?array
    {
        $invitation = $this->lookup->findOpen($token);
        if ($invitation === null) {
            return null;
        }
        $workspace = $this->workspaces->findById($invitation->workspaceId);

        return $workspace === null ? null : ['invitation' => $invitation, 'workspace' => $workspace];
    }

    /**
     * Join the workspace the link belongs to. The link proves control of the mailbox; on top of that a
     * person whose own account has a different email address is turned away (they should sign in with the invited one).
     *
     * @return array{AcceptStatus, ?Workspace}
     */
    public function accept(User $user, string $token): array
    {
        $preview = $this->preview($token);
        if ($preview === null) {
            return [AcceptStatus::Invalid, null];
        }
        ['invitation' => $invitation, 'workspace' => $workspace] = $preview;
        if ($user->email !== null && $user->email !== $invitation->email) {
            return [AcceptStatus::EmailMismatch, $workspace];
        }
        if ($this->workspaces->membership($workspace->id, $user->id) !== null) {
            return [AcceptStatus::AlreadyMember, $workspace];
        }
        if (!$this->lookup->markAccepted($invitation)) {
            return [AcceptStatus::Invalid, null];
        }
        if (!$this->workspaces->addMember($workspace->id, $user->id, $invitation->role, $invitation->invitedBy)) {
            return [AcceptStatus::AlreadyMember, $workspace];
        }
        $this->audit->record('member.joined', $user->id, 'workspace', $workspace->publicId, ['role' => $invitation->role->value, 'invitation' => $invitation->publicId], $workspace->id);

        return [AcceptStatus::Joined, $workspace];
    }

    public function changeRole(WorkspaceContext $context, string $memberId, Role $new): TeamResult
    {
        $member = $this->members->find($context, $memberId);
        if ($member === null) {
            return TeamResult::NotFound;
        }
        if ($member->userId === $context->userId || !$this->policy->canChangeRole($context->role, $member->role, $new)) {
            return TeamResult::Forbidden;
        }
        if ($member->role === $new) {
            return TeamResult::Done;
        }
        $this->members->setRole($context, $member, $new);
        $this->audit->record('member.role_changed', $context->userId, 'member', $member->publicId, ['user_id' => $member->userId, 'from' => $member->role->value, 'to' => $new->value], $context->workspaceId);

        return TeamResult::Done;
    }

    public function remove(WorkspaceContext $context, string $memberId): TeamResult
    {
        $member = $this->members->find($context, $memberId);
        if ($member === null) {
            return TeamResult::NotFound;
        }
        if ($member->userId === $context->userId || !$this->policy->canRemove($context->role, $member->role)) {
            return TeamResult::Forbidden;
        }
        $this->members->remove($context, $member->userId);
        $this->audit->record('member.removed', $context->userId, 'member', $member->publicId, ['user_id' => $member->userId, 'role' => $member->role->value], $context->workspaceId);

        return TeamResult::Done;
    }

    /**
     * Leave the workspace. The owner must transfer it first, so a workspace is never left without one.
     */
    public function leave(WorkspaceContext $context): TeamResult
    {
        if (!$this->policy->canLeave($context->role)) {
            return TeamResult::OwnerMustTransfer;
        }
        if (!$this->members->remove($context, $context->userId)) {
            return TeamResult::NotFound;
        }
        $this->audit->record('member.left', $context->userId, 'member', $context->memberPublicId, ['role' => $context->role->value], $context->workspaceId);

        return TeamResult::Done;
    }

    /**
     * Make another member the owner; the previous owner becomes an admin.
     */
    public function transferOwnership(WorkspaceContext $context, string $memberId): TeamResult
    {
        if (!$this->policy->canTransfer($context->role)) {
            return TeamResult::Forbidden;
        }
        $member = $this->members->find($context, $memberId);
        if ($member === null) {
            return TeamResult::NotFound;
        }
        if ($member->userId === $context->userId || $member->role === Role::Client) {
            return TeamResult::Invalid;
        }
        if (!$this->workspaces->transferOwnership($context, $member->userId)) {
            return TeamResult::Forbidden;
        }
        $this->audit->record('workspace.ownership_transferred', $context->userId, 'workspace', $context->workspacePublicId, ['to_user_id' => $member->userId, 'to_member' => $member->publicId], $context->workspaceId);

        return TeamResult::Done;
    }
}
