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
