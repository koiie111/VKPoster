<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Integrations\Social\Contracts\Platform;
use App\Kernel\Database\Connection;
use App\Kernel\Security\Crypto;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use SensitiveParameter;
use Symfony\Component\Uid\Ulid;

/**
 * The only place that writes tokens of connected accounts. Secrets go in encrypted (`Crypto`), never come back out through
 * the UI: pages show `hint` (the masked form saved at the time, e.g. `1234…cdef`). Reading the secret for an actual call
 * is `ChannelCredentials`.
 */
final class CredentialVault extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Crypto $crypto, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * @return int id of the new credential row
     */
    public function store(WorkspaceContext $context, Platform $platform, string $kind, #[SensitiveParameter] string $secret): int
    {
        $now = DbTime::format($this->clock->now());

        return (int) $this->db->table('platform_credentials')->insert([
            'public_id' => (string) new Ulid(),
            'workspace_id' => $context->workspaceId,
            'platform' => $platform->value,
            'kind' => $kind,
            'secret_enc' => $this->crypto->encrypt($secret),
            'hint' => self::mask($secret),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Store an OAuth token pair (VK). The row is shared by every channel connected through this sign-in.
     *
     * @return int id of the new credential row
     */
    public function storeOAuth(WorkspaceContext $context, Platform $platform, #[SensitiveParameter] string $access, #[SensitiveParameter] string $refresh, DateTimeImmutable $expiresAt, string $deviceId, string $accountId, string $scopes): int
    {
        $now = DbTime::format($this->clock->now());

        return (int) $this->db->table('platform_credentials')->insert([
            'public_id' => (string) new Ulid(),
            'workspace_id' => $context->workspaceId,
            'platform' => $platform->value,
            'kind' => 'oauth',
            'secret_enc' => $this->crypto->encrypt($access),
            'refresh_enc' => $this->crypto->encrypt($refresh),
            'expires_at' => DbTime::format($expiresAt),
            'scopes' => mb_substr($scopes, 0, 500),
            'device_id' => $deviceId,
            'account_id' => $accountId,
            'hint' => $platform->label() . ' ' . $accountId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * The access token of an OAuth credential of this workspace, looked up by its public id (a connection that has just been made and is
     * waiting for the person to pick communities). Null for a credential of another workspace or one that does not exist.
     *
     * @return array{id: int, token: string}|null
     */
    public function pendingOAuth(WorkspaceContext $context, Platform $platform, string $publicId): ?array
    {
        $row = $this->scoped($context, 'platform_credentials')->where('public_id', '=', $publicId)->where('platform', '=', $platform->value)->where('kind', '=', 'oauth')->first();

        return $row === null ? null : ['id' => (int) $row['id'], 'token' => $this->crypto->decrypt((string) $row['secret_enc'])];
    }

    /**
     * Drop OAuth credentials of a platform that no channel uses and that are older than a day: sign-ins the person abandoned before choosing
     * communities.
     */
    public function pruneAbandoned(WorkspaceContext $context, Platform $platform): void
    {
        $limit = DbTime::format($this->clock->now()->modify('-1 day'));
        $this->db->execute(
            'DELETE FROM platform_credentials WHERE workspace_id = ? AND platform = ? AND kind = \'oauth\' AND created_at < ? AND id NOT IN (SELECT credential_id FROM channels WHERE credential_id IS NOT NULL)',
            [$context->workspaceId, $platform->value, $limit],
        );
    }

    /**
     * Replace the secret of an existing credential (the customer pasted a fresh token for the same bot).
     */
    public function replace(WorkspaceContext $context, int $credentialId, #[SensitiveParameter] string $secret): void
    {
        $this->scoped($context, 'platform_credentials')->where('id', '=', $credentialId)->update([
            'secret_enc' => $this->crypto->encrypt($secret),
            'hint' => self::mask($secret),
            'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    public function publicIdOf(WorkspaceContext $context, int $credentialId): ?string
    {
        $row = $this->scoped($context, 'platform_credentials')->where('id', '=', $credentialId)->first();

        return $row === null ? null : (string) $row['public_id'];
    }

    public function hint(WorkspaceContext $context, int $credentialId): ?string
    {
        $row = $this->scoped($context, 'platform_credentials')->where('id', '=', $credentialId)->first();

        return $row === null ? null : (string) $row['hint'];
    }

    /**
     * Remove a credential that no channel uses any more.
     */
    public function deleteIfUnused(WorkspaceContext $context, int $credentialId): void
    {
        if (!$this->scoped($context, 'channels')->where('credential_id', '=', $credentialId)->exists()) {
            $this->scoped($context, 'platform_credentials')->where('id', '=', $credentialId)->delete();
        }
    }

    /**
     * `1234…cdef`: enough for the owner to recognise the token, far too little to use it. The bot id before the colon is public.
     */
    public static function mask(#[SensitiveParameter] string $secret): string
    {
        $secret = trim($secret);
        if (strlen($secret) <= 12) {
            return str_repeat('•', max(4, strlen($secret)));
        }

        return substr($secret, 0, 4) . '…' . substr($secret, -4);
    }
}
