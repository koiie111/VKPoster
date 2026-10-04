<?php

declare(strict_types=1);

namespace App\Domain\Workspace;

use App\Support\Clock;
use App\Support\DbTime;
use App\Kernel\Database\Connection;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

/**
 * Invitations of one workspace: create, list the open ones, revoke. Looking an invitation up by its
 * emailed token (before any workspace context exists) is `InvitationLookup`.
 */
final class InvitationRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * Store an invitation and close older open ones for the same address, so only the newest link works.
     */
    public function create(WorkspaceContext $context, string $email, Role $role, string $tokenHash, int $invitedBy, int $ttlSeconds): Invitation
    {
        $now = $this->clock->now();
        $publicId = (string) new Ulid();
        $this->db->transaction(function () use ($context, $email, $role, $tokenHash, $invitedBy, $ttlSeconds, $now, $publicId): void {
            $this->scoped($context, 'invitations')->where('email', '=', $email)->whereNull('accepted_at')->whereNull('revoked_at')
                ->update(['revoked_at' => DbTime::format($now)]);
            $this->db->table('invitations')->insert([
                'public_id' => $publicId,
                'workspace_id' => $context->workspaceId,
                'email' => $email,
                'role' => $role->value,
                'token_hash' => $tokenHash,
                'invited_by' => $invitedBy,
                'expires_at' => DbTime::format($now->modify(sprintf('+%d seconds', $ttlSeconds))),
                'created_at' => DbTime::format($now),
            ]);
        });

        return $this->find($context, $publicId) ?? throw new \RuntimeException('The invitation was not saved.');
    }

    /**
     * Invitations that can still be accepted, newest first.
     *
     * @return list<Invitation>
     */
    public function open(WorkspaceContext $context): array
    {
        $rows = $this->scoped($context, 'invitations')->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('expires_at', '>', DbTime::format($this->clock->now()))->orderBy('created_at', 'desc')->get();

        return array_map(self::hydrate(...), $rows);
    }

    public function find(WorkspaceContext $context, string $publicId): ?Invitation
    {
        $row = $this->scoped($context, 'invitations')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Revoke an open invitation. False when it does not exist in this workspace or is already closed.
     */
    public function revoke(WorkspaceContext $context, string $publicId): bool
    {
        return $this->scoped($context, 'invitations')->where('public_id', '=', strtoupper($publicId))->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['revoked_at' => DbTime::format($this->clock->now())]) === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): Invitation
    {
        return new Invitation(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (string) $row['email'],
            Role::from((string) $row['role']),
            isset($row['invited_by']) ? (int) $row['invited_by'] : null,
            DbTime::parse($row['expires_at']) ?? new DateTimeImmutable('@0'),
            DbTime::parse($row['created_at']) ?? new DateTimeImmutable('@0'),
        );
    }
}
