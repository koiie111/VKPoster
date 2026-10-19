<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Kernel\Database\Connection;
use App\Kernel\Http\{Request, Response, UploadedFile};
use App\Support\{Clock, DbTime};
use Closure;

/** Transactional request/result fence: concurrent same-key requests serialize on a unique token/key row. */
final class Idempotency
{
    /** Longest a claimed-but-unfinished slow request is trusted before another one may take the key over. */
    private const LEASE_SECONDS = 180;

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** @param Closure():Response $operation
     * @param Closure(Response):void $authorizeReplay
     * @param bool $slow the operation does network/file work: claim the key in a short transaction and run outside it
     */
    public function run(ApiIdentity $identity, Request $request, Closure $operation, Closure $authorizeReplay, bool $slow = false): Response
    {
        $key = $request->header('Idempotency-Key') ?? '';
        if (preg_match('/^[!-~]{1,128}$/D', $key) !== 1) {
            throw new ApiException(400, 'idempotency_key_required', 'Передайте Idempotency-Key длиной 1–128 символов.');
        }
        $files = [];
        foreach ($request->files as $name => $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new ApiException(422, 'invalid_file', 'Передайте один корректный файл.');
            }
            $files[$name] = [$file->clientName, hash_file('sha256', $file->path)];
        }
        $requestHash = hash('sha256', json_encode(self::canonical([$request->method, $request->path, $request->query, $request->body, $files]), JSON_THROW_ON_ERROR));
        if ($slow) {
            return $this->runClaimed($identity, hash('sha256', $key), $requestHash, $operation, $authorizeReplay);
        }
        return $this->db->transaction(function () use ($identity, $key, $requestHash, $operation, $authorizeReplay): Response {
            $now = DbTime::format($this->clock->now());
            $keyHash = hash('sha256', $key);
            $this->db->execute('INSERT IGNORE INTO api_idempotency(workspace_id,token_id,key_hash,request_hash,created_at,expires_at) VALUES(?,?,?,?,?,?)', [$identity->context->workspaceId, $identity->tokenId, $keyHash, $requestHash, $now, DbTime::format($this->clock->now()->modify('+24 hours'))]);
            $row = $this->db->select('SELECT * FROM api_idempotency WHERE workspace_id=? AND token_id=? AND key_hash=? FOR UPDATE', [$identity->context->workspaceId, $identity->tokenId, $keyHash])[0];
            // Reuse expired keys under the row lock; no delete/gap-lock race between concurrent first requests.
            if ($row['expires_at'] <= $now) {
                $this->db->execute('UPDATE api_idempotency SET request_hash=?,status=NULL,response_json=NULL,created_at=?,expires_at=? WHERE id=?', [$requestHash, $now, DbTime::format($this->clock->now()->modify('+24 hours')), $row['id']]);
                $row['request_hash'] = $requestHash;
                $row['status'] = null;
            }
            if (!hash_equals((string) $row['request_hash'], $requestHash)) {
                throw new ApiException(409, 'idempotency_conflict', 'Этот Idempotency-Key использован с другим запросом.');
            }
            if ($row['status'] !== null) {
                $response = new Response((int) $row['status'], (string) $row['response_json'], ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'Idempotency-Replayed' => 'true']);
                $authorizeReplay($response);
                return $response;
            }
            $response = $operation();
            if ($response->status >= 200 && $response->status < 300) {
                $this->db->execute('UPDATE api_idempotency SET status=?,response_json=? WHERE id=?', [$response->status, $response->body, $row['id']]);
            } else {
                $this->db->execute('DELETE FROM api_idempotency WHERE id=?', [$row['id']]);
            }
            return $response;
        });
    }

    /**
     * Same contract as the transactional path, but no DB transaction (and row lock) is held while the operation runs: the key is
     * claimed and committed first, a concurrent request with the same key gets 409, and a claim abandoned by a crashed worker
     * can be taken over after the lease.
     *
     * @param Closure():Response $operation
     * @param Closure(Response):void $authorizeReplay
     */
    private function runClaimed(ApiIdentity $identity, string $keyHash, string $requestHash, Closure $operation, Closure $authorizeReplay): Response
    {
        $workspace = $identity->context->workspaceId;
        $rowId = 0;
        $replay = $this->db->transaction(function () use ($identity, $workspace, $keyHash, $requestHash, $authorizeReplay, &$rowId): ?Response {
            $now = DbTime::format($this->clock->now());
            $expires = DbTime::format($this->clock->now()->modify('+24 hours'));
            $inserted = $this->db->execute('INSERT IGNORE INTO api_idempotency(workspace_id,token_id,key_hash,request_hash,created_at,expires_at) VALUES(?,?,?,?,?,?)', [$workspace, $identity->tokenId, $keyHash, $requestHash, $now, $expires]);
            $row = $this->db->select('SELECT * FROM api_idempotency WHERE workspace_id=? AND token_id=? AND key_hash=? FOR UPDATE', [$workspace, $identity->tokenId, $keyHash])[0];
            $rowId = (int) $row['id'];
            $claim = $inserted === 1;
            if (!$claim && $row['expires_at'] <= $now) {
                $claim = true;
            }
            if (!$claim && $row['status'] === null && $row['created_at'] <= DbTime::format($this->clock->now()->modify('-' . self::LEASE_SECONDS . ' seconds'))) {
                $claim = true;
            }
            if ($claim) {
                $this->db->execute('UPDATE api_idempotency SET request_hash=?,status=NULL,response_json=NULL,created_at=?,expires_at=? WHERE id=?', [$requestHash, $now, $expires, $rowId]);
                return null;
            }
            if (!hash_equals((string) $row['request_hash'], $requestHash)) {
                throw new ApiException(409, 'idempotency_conflict', 'Этот Idempotency-Key использован с другим запросом.');
            }
            if ($row['status'] === null) {
                throw new ApiException(409, 'idempotency_in_progress', 'Запрос с этим Idempotency-Key ещё выполняется.');
            }
            $response = new Response((int) $row['status'], (string) $row['response_json'], ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'Idempotency-Replayed' => 'true']);
            $authorizeReplay($response);
            return $response;
        });
        if ($replay !== null) {
            return $replay;
        }
        try {
            $response = $operation();
        } catch (\Throwable $e) {
            $this->db->execute('DELETE FROM api_idempotency WHERE id=?', [$rowId]);
            throw $e;
        }
        if ($response->status >= 200 && $response->status < 300) {
            $this->db->execute('UPDATE api_idempotency SET status=?,response_json=? WHERE id=?', [$response->status, $response->body, $rowId]);
        } else {
            $this->db->execute('DELETE FROM api_idempotency WHERE id=?', [$rowId]);
        }
        return $response;
    }

    /** @param array<array-key,mixed> $input
     * @return array<array-key,mixed>
     */
    private static function canonical(array $input): array
    {
        if (!array_is_list($input)) {
            ksort($input);
        }
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = self::canonical($value);
            }
        }
        return $input;
    }
}
