<?php

declare(strict_types=1);

namespace App\Integrations\Storage;

/**
 * Where media bytes live (local disk or S3-compatible object storage). Keys look like
 * `ws/{workspaceId}/{yyyy}/{mm}/{ulid}.{ext}` and are never shown to users or put into URLs.
 * Nothing here is reachable through the web server: files are handed out only by `MediaFileController`.
 */
interface MediaStorage
{
    /**
     * Store the content of a stream under `$key` (overwrites).
     *
     * @param resource $stream
     * @throws StorageException
     */
    public function put(string $key, $stream): void;

    /**
     * Open an object for reading.
     *
     * @return resource
     * @throws StorageException when the object does not exist or cannot be read
     */
    public function read(string $key);

    /**
     * Remove an object; a missing one is not an error.
     *
     * @throws StorageException
     */
    public function delete(string $key): void;

    public function exists(string $key): bool;

    /**
     * @throws StorageException
     */
    public function size(string $key): int;
}
