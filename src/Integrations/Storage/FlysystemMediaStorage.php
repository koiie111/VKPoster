<?php

declare(strict_types=1);

namespace App\Integrations\Storage;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

/**
 * `MediaStorage` on top of any Flysystem adapter (local disk, S3, MinIO).
 */
final class FlysystemMediaStorage implements MediaStorage
{
    public function __construct(private readonly FilesystemOperator $filesystem)
    {
    }

    public function put(string $key, $stream): void
    {
        try {
            $this->filesystem->writeStream($key, $stream);
        } catch (FilesystemException $e) {
            throw new StorageException('Could not write an object.', 0, $e);
        }
    }

    public function read(string $key)
    {
        try {
            return $this->filesystem->readStream($key);
        } catch (FilesystemException $e) {
            throw new StorageException('Could not read an object.', 0, $e);
        }
    }

    public function delete(string $key): void
    {
        try {
            $this->filesystem->delete($key);
        } catch (FilesystemException $e) {
            throw new StorageException('Could not delete an object.', 0, $e);
        }
    }

    public function exists(string $key): bool
    {
        try {
            return $this->filesystem->fileExists($key);
        } catch (FilesystemException $e) {
            throw new StorageException('Could not check an object.', 0, $e);
        }
    }

    public function size(string $key): int
    {
        try {
            return $this->filesystem->fileSize($key);
        } catch (FilesystemException $e) {
            throw new StorageException('Could not read the size of an object.', 0, $e);
        }
    }
}
