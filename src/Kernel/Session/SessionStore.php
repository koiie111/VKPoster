<?php

declare(strict_types=1);

namespace App\Kernel\Session;

/**
 * Persistence for session data, keyed by session id. Implementations must never expose the raw id
 * in the backing store.
 */
interface SessionStore
{
    /**
     * @return array<string, mixed>|null null when the session does not exist or expired
     */
    public function read(string $id): ?array;

    /**
     * @param array<string, mixed> $data
     * @param int $ttl seconds until the entry expires
     */
    public function write(string $id, array $data, int $ttl): void;

    public function destroy(string $id): void;

    /**
     * End a session knowing only the SHA-256 hash of its id (what `user_sessions.session_id_hash` holds).
     */
    public function destroyHashed(string $idHash): void;
}
