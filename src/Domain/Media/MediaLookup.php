<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Kernel\Database\Connection;

/**
 * Finds a media item by its public id when no workspace context exists yet: `MediaFileController` has to
 * learn which workspace the file belongs to before it can check the viewer's membership (or a signed link).
 * Do not use it anywhere else; everything else goes through `MediaRepository`.
 */
final class MediaLookup
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function find(string $publicId): ?Media
    {
        $row = $this->db->table('media')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : MediaRepository::hydrate($row);
    }
}
