<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Integrations\Social\Contracts\Platform;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/**
 * The bot's side of connect codes: runs when a message arrives, so there is no signed-in member and no workspace context yet.
 * The code itself is the credential. `peek()` looks without using it up (so a failed attempt, e.g. a bot without rights,
 * can be repeated); `consume()` is atomic, so a code connects exactly one channel even if two messages race.
 */
final class ConnectCodeRedeemer
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * @return array{id: int, workspace_id: int, user_id: int}|null null when unknown, expired or already used
     */
    public function peek(string $input, Platform $platform): ?array
    {
        $code = ConnectCodes::normalize($input);
        if (!ConnectCodes::isWellFormed($code)) {
            return null;
        }
        $row = $this->db->table('channel_connect_codes')->where('code_hash', '=', ConnectCodes::hash($code))
            ->where('platform', '=', $platform->value)->whereNull('used_at')->where('expires_at', '>', DbTime::format($this->clock->now()))->first();

        return $row === null ? null : ['id' => (int) $row['id'], 'workspace_id' => (int) $row['workspace_id'], 'user_id' => (int) $row['user_id']];
    }

    public function consume(int $codeId): bool
    {
        $now = DbTime::format($this->clock->now());

        return $this->db->table('channel_connect_codes')->where('id', '=', $codeId)->whereNull('used_at')->where('expires_at', '>', $now)
            ->update(['used_at' => $now, 'failure' => null]) === 1;
    }

    public function attachChannel(int $codeId, int $channelId): void
    {
        $this->db->table('channel_connect_codes')->where('id', '=', $codeId)->update(['channel_id' => $channelId]);
    }

    /**
     * Tell the person waiting on the page why the attempt did not connect.
     */
    public function fail(int $codeId, string $message): void
    {
        $this->db->table('channel_connect_codes')->where('id', '=', $codeId)->whereNull('used_at')->update(['failure' => mb_substr($message, 0, 255)]);
    }
}
