<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Integrations\Social\Contracts\Platform;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateInterval;
use Symfony\Component\Uid\Ulid;

/**
 * One-time codes that connect a channel through the shared bot ("write `/connect CODE` in the channel"). Ten characters of
 * a 32-letter alphabet without look-alikes (about 50 bits), valid 15 minutes, stored only as SHA-256. Making a new code
 * cancels the earlier unused ones of the same person. Redeeming happens in `ConnectCodeRedeemer`, where no member is signed in.
 */
final class ConnectCodes extends WorkspaceScopedRepository
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const LENGTH = 10;

    public function __construct(Connection $db, private readonly Clock $clock, private readonly int $ttlSeconds = 900)
    {
        parent::__construct($db);
    }

    public function issue(WorkspaceContext $context, Platform $platform): IssuedCode
    {
        $this->scoped($context, 'channel_connect_codes')->where('user_id', '=', $context->userId)
            ->where('platform', '=', $platform->value)->whereNull('used_at')->delete();

        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $now = $this->clock->now();
        $expires = $now->add(new DateInterval('PT' . $this->ttlSeconds . 'S'));
        $publicId = (string) new Ulid();
        $this->db->table('channel_connect_codes')->insert([
            'public_id' => $publicId,
            'workspace_id' => $context->workspaceId,
            'user_id' => $context->userId,
            'platform' => $platform->value,
            'code_hash' => self::hash($code),
            'expires_at' => DbTime::format($expires),
            'created_at' => DbTime::format($now),
        ]);

        return new IssuedCode($publicId, $code, $expires);
    }

    /**
     * Where a code stands, for the waiting page: `waiting`, `problem` (last attempt failed, `message` says why), `connected`
     * (with the channel) or `expired`. Null when the code is not this person's.
     *
     * @return array{state: string, message: string, channel: ?string}|null
     */
    public function status(WorkspaceContext $context, string $publicId): ?array
    {
        $row = $this->scoped($context, 'channel_connect_codes')->where('public_id', '=', strtoupper($publicId))->where('user_id', '=', $context->userId)->first();
        if ($row === null) {
            return null;
        }
        if ($row['channel_id'] !== null && $row['used_at'] !== null) {
            $channel = $this->scoped($context, 'channels')->where('id', '=', (int) $row['channel_id'])->first();

            return ['state' => 'connected', 'message' => '', 'channel' => $channel === null ? null : (is_string($channel['alias'] ?? null) && $channel['alias'] !== '' ? $channel['alias'] : (string) $channel['title'])];
        }
        $expires = DbTime::parse($row['expires_at']);
        if ($row['used_at'] !== null || $expires === null || $expires <= $this->clock->now()) {
            return ['state' => 'expired', 'message' => '', 'channel' => null];
        }
        if (is_string($row['failure'] ?? null) && $row['failure'] !== '') {
            return ['state' => 'problem', 'message' => $row['failure'], 'channel' => null];
        }

        return ['state' => 'waiting', 'message' => '', 'channel' => null];
    }

    /**
     * What a person may type: any case, spaces and dashes allowed.
     */
    public static function normalize(string $input): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $input));
    }

    public static function isWellFormed(string $normalized): bool
    {
        return preg_match('/^[' . self::ALPHABET . ']{' . self::LENGTH . '}$/', $normalized) === 1;
    }

    public static function hash(string $normalized): string
    {
        return hash('sha256', $normalized);
    }

    public static function pretty(string $code): string
    {
        return substr($code, 0, 5) . '-' . substr($code, 5);
    }
}
