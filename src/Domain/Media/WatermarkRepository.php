<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/**
 * Watermarks of one workspace. At most one is the default (used when a post asks for "the watermark").
 */
final class WatermarkRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * @return list<Watermark> default first
     */
    public function all(WorkspaceContext $context): array
    {
        $rows = $this->scoped($context, 'watermarks')->orderBy('is_default', 'desc')->orderBy('id', 'asc')->get();

        return array_map(self::hydrate(...), $rows);
    }

    public function find(WorkspaceContext $context, string $publicId): ?Watermark
    {
        $row = $this->scoped($context, 'watermarks')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function default(WorkspaceContext $context): ?Watermark
    {
        $row = $this->scoped($context, 'watermarks')->where('is_default', '=', 1)->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function create(WorkspaceContext $context, string $name, string $storageKey, int $width, int $height): Watermark
    {
        return $this->db->transaction(function () use ($context, $name, $storageKey, $width, $height): Watermark {
            $first = !$this->scoped($context, 'watermarks')->exists();
            $publicId = (string) new Ulid();
            $now = DbTime::format($this->clock->now());
            $this->db->table('watermarks')->insert([
                'public_id' => $publicId,
                'workspace_id' => $context->workspaceId,
                'name' => $name,
                'storage_key' => $storageKey,
                'width' => $width,
                'height' => $height,
                'is_default' => $first ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->find($context, $publicId) ?? throw new \LogicException('The inserted watermark disappeared.');
        });
    }

    public function updateSettings(WorkspaceContext $context, Watermark $watermark, string $name, string $position, int $opacity, int $scale, int $margin): void
    {
        $this->scoped($context, 'watermarks')->where('id', '=', $watermark->id)->update([
            'name' => $name,
            'position' => $position,
            'opacity' => $opacity,
            'scale' => $scale,
            'margin' => $margin,
            'updated_at' => DbTime::format($this->clock->now()),
        ]);
    }

    public function makeDefault(WorkspaceContext $context, Watermark $watermark): void
    {
        $this->db->transaction(function () use ($context, $watermark): void {
            $this->scoped($context, 'watermarks')->update(['is_default' => 0]);
            $this->scoped($context, 'watermarks')->where('id', '=', $watermark->id)->update(['is_default' => 1]);
        });
    }

    /**
     * Delete a watermark. When it was the default, the oldest remaining one takes over.
     */
    public function delete(WorkspaceContext $context, Watermark $watermark): void
    {
        $this->db->transaction(function () use ($context, $watermark): void {
            // Judge by the stored row: the object in hand may predate a change of the default.
            $stored = $this->scoped($context, 'watermarks')->where('id', '=', $watermark->id)->forUpdate()->first();
            $this->scoped($context, 'watermarks')->where('id', '=', $watermark->id)->delete();
            if ($stored !== null && (int) $stored['is_default'] === 1) {
                $next = $this->scoped($context, 'watermarks')->orderBy('id', 'asc')->first();
                if ($next !== null) {
                    $this->scoped($context, 'watermarks')->where('id', '=', (int) $next['id'])->update(['is_default' => 1]);
                }
            }
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Watermark
    {
        return new Watermark(
            (int) $row['id'],
            (string) $row['public_id'],
            (string) $row['name'],
            (string) $row['storage_key'],
            (int) $row['width'],
            (int) $row['height'],
            (string) $row['position'],
            (int) $row['opacity'],
            (int) $row['scale'],
            (int) $row['margin'],
            (int) $row['is_default'] === 1,
        );
    }
}
