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
 * Library folders of one workspace. Names are unique inside a workspace; deleting a folder keeps its files.
 */
final class FolderRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /**
     * @return list<MediaFolder> by name, with the number of files in each
     */
    public function all(WorkspaceContext $context): array
    {
        $rows = $this->db->select(
            'SELECT f.id, f.public_id, f.name, (SELECT COUNT(*) FROM media m WHERE m.folder_id = f.id) AS items FROM media_folders f WHERE f.workspace_id = ? ORDER BY f.name ASC',
            [$context->workspaceId],
        );

        return array_map(static fn (array $r): MediaFolder => new MediaFolder((int) $r['id'], (string) $r['public_id'], (string) $r['name'], (int) $r['items']), $rows);
    }

    public function find(WorkspaceContext $context, string $publicId): ?MediaFolder
    {
        $row = $this->scoped($context, 'media_folders')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : new MediaFolder((int) $row['id'], (string) $row['public_id'], (string) $row['name']);
    }

    /**
     * @return MediaFolder|null null when a folder with this name already exists
     */
    public function create(WorkspaceContext $context, string $name): ?MediaFolder
    {
        if ($this->scoped($context, 'media_folders')->where('name', '=', $name)->exists()) {
            return null;
        }
        $publicId = (string) new Ulid();
        try {
            $this->db->table('media_folders')->insert([
                'public_id' => $publicId,
                'workspace_id' => $context->workspaceId,
                'name' => $name,
                'created_at' => DbTime::format($this->clock->now()),
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return null; // lost a race with another request creating the same name
            }
            throw $e;
        }

        return $this->find($context, $publicId);
    }

    /**
     * @return bool false when another folder already has this name
     */
    public function rename(WorkspaceContext $context, MediaFolder $folder, string $name): bool
    {
        if ($this->scoped($context, 'media_folders')->where('name', '=', $name)->where('id', '!=', $folder->id)->exists()) {
            return false;
        }
        $this->scoped($context, 'media_folders')->where('id', '=', $folder->id)->update(['name' => $name]);

        return true;
    }

    public function delete(WorkspaceContext $context, MediaFolder $folder): void
    {
        $this->scoped($context, 'media_folders')->where('id', '=', $folder->id)->delete();
    }
}
