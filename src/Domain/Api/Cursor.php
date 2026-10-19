<?php

declare(strict_types=1);

namespace App\Domain\Api;

use App\Kernel\Security\Crypto;

/** Authenticated opaque cursor bound to the issuing token and collection/filter, with public IDs only. */
final class Cursor
{
    public function __construct(private readonly Crypto $crypto)
    {
    }

    public function encode(ApiIdentity $who, string $collection, string $lastId): string
    {
        return rtrim(strtr(base64_encode($this->crypto->encrypt(json_encode([$who->tokenId, $collection, $lastId], JSON_THROW_ON_ERROR))), '+/', '-_'), '=');
    }

    public function decode(ApiIdentity $who, string $collection, string $cursor): string
    {
        if ($cursor === '') {
            return '';
        }
        try {
            if (strlen($cursor) > 2000) {
                throw new \RuntimeException();
            }
            $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
            $data = json_decode($this->crypto->decrypt($raw === false ? '' : $raw), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data[0] ?? null) !== $who->tokenId || ($data[1] ?? null) !== $collection || !is_string($data[2] ?? null)) {
                throw new \RuntimeException();
            }
            return $data[2];
        } catch (\Throwable) {
            throw new ApiException(400, 'invalid_cursor', 'Некорректный курсор.');
        }
    }
}
