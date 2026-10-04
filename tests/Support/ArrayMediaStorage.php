<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Integrations\Storage\MediaStorage;
use App\Integrations\Storage\StorageException;

/**
 * In-memory `MediaStorage` for tests: keeps object bytes in an array so tests can inspect exactly what was stored.
 */
final class ArrayMediaStorage implements MediaStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    public function put(string $key, $stream): void
    {
        $this->objects[$key] = (string) stream_get_contents($stream);
    }

    public function read(string $key)
    {
        if (!isset($this->objects[$key])) {
            throw new StorageException('missing object ' . $key);
        }
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new StorageException('no temp stream');
        }
        fwrite($stream, $this->objects[$key]);
        rewind($stream);

        return $stream;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }

    public function exists(string $key): bool
    {
        return isset($this->objects[$key]);
    }

    public function size(string $key): int
    {
        return isset($this->objects[$key]) ? strlen($this->objects[$key]) : throw new StorageException('missing object ' . $key);
    }
}
