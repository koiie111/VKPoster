<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;

/**
 * Library items of one workspace. Every method is limited to the workspace of the given context;
 * serving a file by its public id, before any context exists, goes through `MediaLookup`.
 */
final class MediaRepository extends WorkspaceScopedRepository
{
    public const PER_PAGE = 36;

    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * @param array{uploader_id: ?int, folder_id: ?int, kind: MediaKind, original_name: string, storage_key: string, thumb_key: ?string, mime: string, size: int, width: ?int, height: ?int, duration_ms: ?int, codec: ?string, animated: bool, sha256: string} $data
     */
    public function insert(WorkspaceContext $context, array $data, string $publicId): Media
    {
        $this->db->table('media')->insert([
            'public_id' => $publicId,
            'workspace_id' => $context->workspaceId,
            'uploader_id' => $data['uploader_id'],
            'folder_id' => $data['folder_id'],
            'kind' => $data['kind']->value,
            'original_name' => $data['original_name'],
            'storage_key' => $data['storage_key'],
            'thumb_key' => $data['thumb_key'],
            'mime' => $data['mime'],
            'size' => $data['size'],
            'width' => $data['width'],
            'height' => $data['height'],
            'duration_ms' => $data['duration_ms'],
            'codec' => $data['codec'],
            'animated' => $data['animated'] ? 1 : 0,
            'sha256' => $data['sha256'],
            'created_at' => DbTime::format($this->clock->now()),
        ]);
        $media = $this->find($context, $publicId);
        if ($media === null) {
            throw new \LogicException('The inserted media row disappeared.');
        }

        return $media;
    }

    public function find(WorkspaceContext $context, string $publicId): ?Media
    {
        $row = $this->scoped($context, 'media')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function findBySha(WorkspaceContext $context, string $sha256): ?Media
    {
        $row = $this->scoped($context, 'media')->where('sha256', '=', $sha256)->first();

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @param list<string> $publicIds
     * @return list<Media> in the order of `$publicIds`, unknown ids skipped
     */
    public function findMany(WorkspaceContext $context, array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }
        $rows = $this->scoped($context, 'media')->whereIn('public_id', array_map('strtoupper', $publicIds))->get();
        $byId = [];
        foreach ($rows as $row) {
            $media = self::hydrate($row);
            $byId[$media->publicId] = $media;
        }
        $result = [];
        foreach ($publicIds as $id) {
            if (isset($byId[strtoupper($id)])) {
                $result[] = $byId[strtoupper($id)];
            }
        }

        return $result;
    }

    /**
     * One page of the library, newest first.
     *
     * @param int|null $folderId only this folder (null: whole library)
     * @return array{rows: list<Media>, total: int, pages: int, page: int}
     */
    public function page(WorkspaceContext $context, ?int $folderId, ?MediaKind $kind, string $search, int $page): array
    {
        $filter = function () use ($context, $folderId, $kind, $search) {
            $query = $this->scoped($context, 'media');
            if ($folderId !== null) {
                $query->where('folder_id', '=', $folderId);
            }
            if ($kind !== null) {
                $query->where('kind', '=', $kind->value);
            }
            if ($search !== '') {
                $query->where('original_name', 'LIKE', '%' . addcslashes($search, '\\%_') . '%');
            }

            return $query;
        };
        $total = $filter()->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $pages);
        $rows = $filter()->orderBy('id', 'desc')->limit(self::PER_PAGE)->offset(($page - 1) * self::PER_PAGE)->get();

        return ['rows' => array_map(self::hydrate(...), $rows), 'total' => $total, 'pages' => $pages, 'page' => $page];
    }

    /**
     * Bytes stored for the workspace (cached variants are not counted).
     */
    public function usedBytes(WorkspaceContext $context): int
    {
        $rows = $this->db->select('SELECT COALESCE(SUM(size), 0) AS total FROM media WHERE workspace_id = ?', [$context->workspaceId]);

        return (int) ($rows[0]['total'] ?? 0);
    }

    public function delete(WorkspaceContext $context, Media $media): void
    {
        $this->scoped($context, 'media')->where('id', '=', $media->id)->delete();
    }

    /**
     * Move files into a folder (null: out of any folder). Returns how many files were moved.
     *
     * @param list<string> $publicIds
     */
    public function move(WorkspaceContext $context, array $publicIds, ?int $folderId): int
    {
        if ($publicIds === []) {
            return 0;
        }

        return $this->scoped($context, 'media')->whereIn('public_id', array_map('strtoupper', $publicIds))->update(['folder_id' => $folderId]);
    }

    public function rename(WorkspaceContext $context, Media $media, string $name): void
    {
        $this->scoped($context, 'media')->where('id', '=', $media->id)->update(['original_name' => $name]);
    }

    /**
     * @param array<string, array{key: string, size: int, width: int, height: int, mime: string}> $variants
     */
    public function setVariants(WorkspaceContext $context, Media $media, array $variants): void
    {
        $this->scoped($context, 'media')->where('id', '=', $media->id)->update([
            'variants_json' => $variants === [] ? null : json_encode($variants, JSON_THROW_ON_ERROR),
        ]);
    }

    public static function newPublicId(): string
    {
        return (string) new Ulid();
    }

    /**
     * @param array<string, mixed> $row
     * @internal shared with `MediaLookup`
     */
    public static function hydrate(array $row): Media
    {
        $variants = [];
        if (is_string($row['variants_json'] ?? null) && $row['variants_json'] !== '') {
            $decoded = json_decode($row['variants_json'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $name => $info) {
                    if (is_array($info) && is_string($info['key'] ?? null)) {
                        $variants[(string) $name] = [
                            'key' => $info['key'],
                            'size' => (int) ($info['size'] ?? 0),
                            'width' => (int) ($info['width'] ?? 0),
                            'height' => (int) ($info['height'] ?? 0),
                            'mime' => is_string($info['mime'] ?? null) ? $info['mime'] : 'image/jpeg',
                        ];
                    }
                }
            }
        }
        $nullableInt = static fn (mixed $v): ?int => $v === null ? null : (int) $v;

        return new Media(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            $nullableInt($row['uploader_id']),
            $nullableInt($row['folder_id']),
            MediaKind::from((string) $row['kind']),
            (string) $row['original_name'],
            (string) $row['storage_key'],
            $row['thumb_key'] === null ? null : (string) $row['thumb_key'],
            (string) $row['mime'],
            (int) $row['size'],
            $nullableInt($row['width']),
            $nullableInt($row['height']),
            $nullableInt($row['duration_ms']),
            $row['codec'] === null ? null : (string) $row['codec'],
            (int) $row['animated'] === 1,
            (string) $row['sha256'],
            $variants,
            DbTime::parse($row['created_at']) ?? new DateTimeImmutable('@0'),
        );
    }
}
