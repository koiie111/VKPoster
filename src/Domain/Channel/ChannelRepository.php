<?php

declare(strict_types=1);

namespace App\Domain\Channel;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Integrations\Social\Contracts\Platform;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/**
 * Channels of one workspace. Every method is limited to the workspace of the given context. Code that works across
 * workspaces (health checks, the bot's webhook) uses `ChannelSystem` instead.
 */
final class ChannelRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * @param list<int>|null $allowedIds ids a restricted member may see; null = all channels of the workspace
     * @return list<Channel> oldest connection first
     */
    public function all(WorkspaceContext $context, ?array $allowedIds = null): array
    {
        if ($allowedIds === []) {
            return [];
        }
        $query = $this->scoped($context, 'channels')->orderBy('id');
        if ($allowedIds !== null) {
            $query->whereIn('id', $allowedIds);
        }

        return array_map(self::hydrate(...), $query->get());
    }

    public function find(WorkspaceContext $context, string $publicId): ?Channel
    {
        $row = $this->scoped($context, 'channels')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function findById(WorkspaceContext $context, int $id): ?Channel
    {
        $row = $this->scoped($context, 'channels')->where('id', '=', $id)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function findByExternal(WorkspaceContext $context, Platform $platform, string $externalId): ?Channel
    {
        $row = $this->scoped($context, 'channels')->where('platform', '=', $platform->value)->where('external_id', '=', $externalId)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function count(WorkspaceContext $context): int
    {
        return $this->scoped($context, 'channels')->count();
    }

    /**
     * Add a channel, or refresh it when the same chat is connected again (reconnecting after it broke): the row keeps its id,
     * so scheduled posts and access lists stay attached. Returns the channel and whether it is new.
     *
     * @param array<string, mixed> $settings
     * @return array{channel: Channel, created: bool}
     */
    public function connect(
        WorkspaceContext $context,
        Platform $platform,
        string $externalId,
        ChannelMode $mode,
        string $title,
        ?string $username,
        string $kind,
        ?int $credentialId,
        array $settings,
        ?int $createdBy,
    ): array {
        $now = DbTime::format($this->clock->now());
        $existing = $this->findByExternal($context, $platform, $externalId);
        $settingsJson = json_encode($settings, JSON_THROW_ON_ERROR);
        if ($existing !== null) {
            $this->scoped($context, 'channels')->where('id', '=', $existing->id)->update([
                'mode' => $mode->value,
                'title' => $title,
                'username' => $username,
                'kind' => $kind,
                'status' => ChannelStatus::Active->value,
                'credential_id' => $credentialId,
                'settings_json' => $settingsJson,
                'last_health_at' => $now,
                'last_error' => null,
                'updated_at' => $now,
            ]);

            return ['channel' => $this->find($context, $existing->publicId) ?? $existing, 'created' => false];
        }
        $publicId = (string) new Ulid();
        $this->db->table('channels')->insert([
            'public_id' => $publicId,
            'workspace_id' => $context->workspaceId,
            'platform' => $platform->value,
            'external_id' => $externalId,
            'mode' => $mode->value,
            'title' => $title,
            'username' => $username,
            'kind' => $kind,
            'status' => ChannelStatus::Active->value,
            'credential_id' => $credentialId,
            'settings_json' => $settingsJson,
            'last_health_at' => $now,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $channel = $this->find($context, $publicId);
        if ($channel === null) {
            throw new \LogicException('The inserted channel row disappeared.');
        }

        return ['channel' => $channel, 'created' => true];
    }

    public function setStatus(WorkspaceContext $context, Channel $channel, ChannelStatus $status, ?string $error = null): void
    {
        $this->scoped($context, 'channels')->where('id', '=', $channel->id)->update([
            'status' => $status->value,
            'last_error' => $error === null ? null : mb_substr($error, 0, 500),
            'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    /**
     * Empty name removes the alias (the channel shows its own title again).
     */
    public function rename(WorkspaceContext $context, Channel $channel, string $alias): void
    {
        $this->scoped($context, 'channels')->where('id', '=', $channel->id)->update([
            'alias' => $alias === '' ? null : $alias,
            'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    public function delete(WorkspaceContext $context, Channel $channel): void
    {
        $this->scoped($context, 'channels')->where('id', '=', $channel->id)->delete();
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): Channel
    {
        $settings = is_string($row['settings_json'] ?? null) ? json_decode($row['settings_json'], true) : [];

        return new Channel(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            Platform::from((string) $row['platform']),
            (string) $row['external_id'],
            ChannelMode::from((string) $row['mode']),
            (string) $row['title'],
            is_string($row['alias'] ?? null) ? $row['alias'] : null,
            is_string($row['username'] ?? null) ? $row['username'] : null,
            (string) $row['kind'],
            is_string($row['avatar_key'] ?? null) ? $row['avatar_key'] : null,
            ChannelStatus::from((string) $row['status']),
            isset($row['credential_id']) ? (int) $row['credential_id'] : null,
            is_array($settings) ? $settings : [],
            DbTime::parse($row['last_health_at'] ?? null),
            is_string($row['last_error'] ?? null) ? $row['last_error'] : null,
            DbTime::parse($row['created_at']) ?? new \DateTimeImmutable('@0'),
        );
    }
}
