<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Integrations\Social\Contracts\Platform;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

/**
 * Posts and their variants inside one workspace. Every method is limited to the workspace of the given context; the publication
 * pipeline works across workspaces through `PublicationSystem` instead.
 */
final class PostRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    public function find(WorkspaceContext $context, string $publicId): ?Post
    {
        $row = $this->scoped($context, 'posts')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @param list<string> $mediaIds
     */
    public function create(WorkspaceContext $context, ?int $authorId, string $text, array $mediaIds, PostOptions $options, bool $perNetwork, PostStatus $status, ?DateTimeImmutable $scheduledAt, string $timezone): Post
    {
        $now = DbTime::format($this->clock->now());
        $publicId = (string) new Ulid();
        $this->db->table('posts')->insert([
            'public_id' => $publicId,
            'workspace_id' => $context->workspaceId,
            'author_id' => $authorId,
            'status' => $status->value,
            'base_text' => $text,
            'media_ids_json' => json_encode($mediaIds, JSON_THROW_ON_ERROR),
            'options_json' => json_encode($options->toArray(), JSON_THROW_ON_ERROR),
            'per_network' => $perNetwork ? 1 : 0,
            'scheduled_at' => $scheduledAt === null ? null : DbTime::format($scheduledAt),
            'timezone' => $timezone,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($context, $publicId) ?? throw new \LogicException('The inserted post disappeared.');
    }

    /**
     * Rewrite the content of a post (editing). Status and schedule are changed separately.
     *
     * @param list<string> $mediaIds
     */
    public function updateContent(WorkspaceContext $context, Post $post, string $text, array $mediaIds, PostOptions $options, bool $perNetwork): void
    {
        $this->scoped($context, 'posts')->where('id', '=', $post->id)->update([
            'base_text' => $text,
            'media_ids_json' => json_encode($mediaIds, JSON_THROW_ON_ERROR),
            'options_json' => json_encode($options->toArray(), JSON_THROW_ON_ERROR),
            'per_network' => $perNetwork ? 1 : 0,
            'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    public function setSchedule(WorkspaceContext $context, Post $post, PostStatus $status, ?DateTimeImmutable $scheduledAt, string $timezone): void
    {
        $this->scoped($context, 'posts')->where('id', '=', $post->id)->update([
            'status' => $status->value,
            'scheduled_at' => $scheduledAt === null ? null : DbTime::format($scheduledAt),
            'timezone' => $timezone,
            'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    public function delete(WorkspaceContext $context, Post $post): void
    {
        $this->scoped($context, 'posts')->where('id', '=', $post->id)->delete();
    }

    /**
     * Lock the row for the rest of the transaction, so that two people editing, or an edit and a publishing worker, take turns.
     */
    public function lock(WorkspaceContext $context, Post $post): ?Post
    {
        $row = $this->scoped($context, 'posts')->where('id', '=', $post->id)->forUpdate()->first();

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @return list<PostVariant> in the order the channels were chosen
     */
    public function variants(WorkspaceContext $context, Post $post): array
    {
        $rows = $this->scoped($context, 'post_variants')->where('post_id', '=', $post->id)->orderBy('id')->get();

        return array_map(self::hydrateVariant(...), $rows);
    }

    /**
     * @param list<string>|null $mediaIds
     */
    public function addVariant(WorkspaceContext $context, Post $post, int $channelId, Platform $platform, string $channelName, ?string $text, ?array $mediaIds, ?PostOptions $options): PostVariant
    {
        $id = $this->db->table('post_variants')->insert([
            'post_id' => $post->id,
            'workspace_id' => $context->workspaceId,
            'channel_id' => $channelId,
            'platform' => $platform->value,
            'channel_name' => mb_substr($channelName, 0, 255),
            'text' => $text,
            'media_ids_json' => $mediaIds === null ? null : json_encode($mediaIds, JSON_THROW_ON_ERROR),
            'options_json' => $options === null ? null : json_encode($options->toArray(), JSON_THROW_ON_ERROR),
        ]);
        $row = $this->scoped($context, 'post_variants')->where('id', '=', (int) $id)->first() ?? throw new \LogicException('The inserted variant disappeared.');

        return self::hydrateVariant($row);
    }

    /**
     * @param list<string>|null $mediaIds
     */
    public function updateVariant(WorkspaceContext $context, PostVariant $variant, ?string $text, ?array $mediaIds, ?PostOptions $options): void
    {
        $this->scoped($context, 'post_variants')->where('id', '=', $variant->id)->update([
            'text' => $text,
            'media_ids_json' => $mediaIds === null ? null : json_encode($mediaIds, JSON_THROW_ON_ERROR),
            'options_json' => $options === null ? null : json_encode($options->toArray(), JSON_THROW_ON_ERROR),
        ]);
    }

    public function deleteVariant(WorkspaceContext $context, PostVariant $variant): void
    {
        $this->scoped($context, 'post_variants')->where('id', '=', $variant->id)->delete();
    }

    /**
     * Drafts without a date, newest first (they are not in the calendar grid).
     *
     * @param list<int>|null $allowedChannelIds null = every channel; otherwise only posts with at least one of these channels
     * @return list<Post>
     */
    public function drafts(WorkspaceContext $context, ?array $allowedChannelIds, int $limit = 50): array
    {
        $query = $this->scoped($context, 'posts')->where('status', '=', PostStatus::Draft->value)->whereNull('scheduled_at');
        if ($allowedChannelIds !== null) {
            if ($allowedChannelIds === []) {
                return [];
            }
            $query->whereIn('id', $this->postIdsOfChannels($context, $allowedChannelIds));
        }

        return array_map(self::hydrate(...), $query->orderBy('updated_at', 'desc')->limit($limit)->get());
    }

    /**
     * @param list<int> $channelIds
     * @return list<int>
     */
    private function postIdsOfChannels(WorkspaceContext $context, array $channelIds): array
    {
        $rows = $this->scoped($context, 'post_variants')->select(['post_id'])->whereIn('channel_id', $channelIds)->get();
        $ids = array_map(static fn (array $row): int => (int) $row['post_id'], $rows);

        return $ids === [] ? [0] : array_values(array_unique($ids));
    }

    /**
     * Whether the member may see this post: no restriction, or at least one variant in an allowed channel (a draft without
     * channels is visible to its author only; the caller decides that).
     *
     * @param list<int>|null $allowedChannelIds
     */
    public function visibleTo(WorkspaceContext $context, Post $post, ?array $allowedChannelIds): bool
    {
        if ($allowedChannelIds === null) {
            return true;
        }
        if ($allowedChannelIds === []) {
            return false;
        }

        return $this->scoped($context, 'post_variants')->where('post_id', '=', $post->id)->whereIn('channel_id', $allowedChannelIds)->exists();
    }

    /**
     * Posts that use a library file: used to refuse deleting a file that a plan still needs.
     */
    public function plannedWithMedia(WorkspaceContext $context, string $mediaPublicId): int
    {
        $like = '%"' . $mediaPublicId . '"%';
        $rows = $this->db->select(
            'SELECT COUNT(DISTINCT p.id) AS n FROM posts p LEFT JOIN post_variants v ON v.post_id = p.id
             WHERE p.workspace_id = ? AND p.status IN (\'scheduled\', \'publishing\') AND (p.media_ids_json LIKE ? OR v.media_ids_json LIKE ?)',
            [$context->workspaceId, $like, $like],
        );

        return (int) ($rows[0]['n'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): Post
    {
        $options = is_string($row['options_json'] ?? null) ? json_decode($row['options_json'], true) : [];

        return new Post(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            isset($row['author_id']) ? (int) $row['author_id'] : null,
            PostStatus::from((string) $row['status']),
            (string) $row['base_text'],
            self::mediaList($row['media_ids_json'] ?? null) ?? [],
            PostOptions::fromArray(is_array($options) ? $options : []),
            (int) $row['per_network'] === 1,
            DbTime::parse($row['scheduled_at'] ?? null),
            (string) $row['timezone'],
            DbTime::parse($row['published_at'] ?? null),
            DbTime::parse($row['created_at']) ?? new DateTimeImmutable('@0'),
            DbTime::parse($row['updated_at']) ?? new DateTimeImmutable('@0'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrateVariant(array $row): PostVariant
    {
        $options = is_string($row['options_json'] ?? null) ? json_decode($row['options_json'], true) : null;

        return new PostVariant(
            (int) $row['id'],
            (int) $row['post_id'],
            isset($row['channel_id']) ? (int) $row['channel_id'] : null,
            Platform::from((string) $row['platform']),
            (string) $row['channel_name'],
            is_string($row['text'] ?? null) ? $row['text'] : null,
            self::mediaList($row['media_ids_json'] ?? null),
            is_array($options) ? PostOptions::fromArray($options) : null,
        );
    }

    /**
     * @return list<string>|null
     */
    private static function mediaList(mixed $json): ?array
    {
        if (!is_string($json)) {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : null;
    }
}
