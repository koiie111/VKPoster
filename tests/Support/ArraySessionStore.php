<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel\Session\SessionStore;

/**
 * In-memory session store for unit tests.
 */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string, array<string, mixed>> */
    public array $items = [];

    public function read(string $id): ?array
    {
        return $this->items[$id] ?? null;
    }

    public function write(string $id, array $data, int $ttl): void
    {
        $this->items[$id] = $data;
    }

    public function destroy(string $id): void
    {
        unset($this->items[$id]);
    }
}
