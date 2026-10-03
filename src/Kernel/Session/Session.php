<?php

declare(strict_types=1);

namespace App\Kernel\Session;

use App\Support\Clock;

/**
 * Server-side session. Data lives in a `SessionStore`; the browser only holds a random id.
 *
 * - `regenerate()` issues a new id (call on login and on privilege change) and drops the old one.
 * - Flash values written during one request are readable during the next one only.
 * - Idle timeout (default 2 h) and absolute timeout (default 30 days) end a session on load.
 */
final class Session
{
    public const ID_PATTERN = '/^[0-9a-f]{64}$/';

    private string $id = '';

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, mixed> */
    private array $flashNew = [];

    /** @var array<string, mixed> */
    private array $flashOld = [];

    private int $createdAt = 0;
    private bool $dirty = false;
    private bool $isNew = true;
    private ?string $previousId = null;
    private bool $destroyed = false;

    public function __construct(
        private readonly SessionStore $store,
        private readonly Clock $clock,
        private readonly int $idleTtl = 7200,
        private readonly int $absoluteTtl = 2592000,
    ) {
    }

    /**
     * Load the session for `$id` (a cookie value) or start a new one when it is unknown, malformed or expired.
     */
    public function start(?string $id): void
    {
        $now = $this->clock->now()->getTimestamp();
        $stored = ($id !== null && preg_match(self::ID_PATTERN, $id) === 1) ? $this->store->read($id) : null;
        if ($id !== null && $stored !== null && $this->isExpired($stored, $now)) {
            $this->store->destroy($id);
            $stored = null;
        }
        if ($stored === null) {
            $this->id = bin2hex(random_bytes(32));
            $this->data = [];
            $this->flashOld = [];
            $this->createdAt = $now;
            $this->isNew = true;

            return;
        }
        $this->id = $id;
        $this->isNew = false;
        $this->createdAt = is_int($stored['_created'] ?? null) ? $stored['_created'] : $now;
        $this->flashOld = is_array($stored['_flash'] ?? null) ? $stored['_flash'] : [];
        unset($stored['_created'], $stored['_last'], $stored['_flash']);
        $this->data = $stored;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isNew(): bool
    {
        return $this->isNew;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
        $this->dirty = true;
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    /**
     * Store a value readable only during the next request.
     */
    public function flash(string $key, mixed $value): void
    {
        $this->flashNew[$key] = $value;
        $this->dirty = true;
    }

    /**
     * Flash the submitted form values and validation errors for the next page render.
     * Password and token fields are never stored.
     *
     * @param array<string, mixed> $input
     * @param array<string, list<string>> $errors
     */
    public function flashValidation(array $input, array $errors): void
    {
        $safe = array_filter(
            $input,
            static fn (string $key): bool => preg_match('/password|token|secret/i', $key) !== 1,
            ARRAY_FILTER_USE_KEY,
        );
        $this->flash('_old', $safe);
        $this->flash('_errors', $errors);
    }

    /**
     * Flash value written by the previous request.
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->flashOld[$key] ?? $default;
    }

    /**
     * New session id with the same data; the old id stops working immediately.
     */
    public function regenerate(): void
    {
        if (!$this->isNew) {
            $this->previousId = $this->id;
        }
        $this->id = bin2hex(random_bytes(32));
        $this->isNew = true;
        $this->createdAt = $this->createdAt > 0 ? $this->createdAt : $this->clock->now()->getTimestamp();
        $this->dirty = true;
    }

    /**
     * End the session: clear data and delete it from the store.
     */
    public function invalidate(): void
    {
        $this->store->destroy($this->id);
        if ($this->previousId !== null) {
            $this->store->destroy($this->previousId);
        }
        $this->data = [];
        $this->flashNew = [];
        $this->flashOld = [];
        $this->destroyed = true;
        $this->dirty = false;
    }

    public function wasDestroyed(): bool
    {
        return $this->destroyed;
    }

    /**
     * True when the response must carry a (new) session cookie.
     */
    public function needsCookie(): bool
    {
        return !$this->destroyed && $this->isNew && $this->dirty;
    }

    /**
     * Persist the session. Anonymous sessions that never stored anything are not written.
     */
    public function save(): void
    {
        if ($this->destroyed || ($this->isNew && !$this->dirty)) {
            return;
        }
        if ($this->previousId !== null) {
            $this->store->destroy($this->previousId);
            $this->previousId = null;
        }
        $now = $this->clock->now()->getTimestamp();
        $payload = $this->data;
        $payload['_created'] = $this->createdAt;
        $payload['_last'] = $now;
        $payload['_flash'] = $this->flashNew;
        $ttl = min($this->idleTtl, max(1, $this->createdAt + $this->absoluteTtl - $now));
        $this->store->write($this->id, $payload, $ttl);
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function isExpired(array $stored, int $now): bool
    {
        $created = is_int($stored['_created'] ?? null) ? $stored['_created'] : 0;
        $last = is_int($stored['_last'] ?? null) ? $stored['_last'] : 0;

        return $now - $last > $this->idleTtl || $now - $created > $this->absoluteTtl;
    }
}
