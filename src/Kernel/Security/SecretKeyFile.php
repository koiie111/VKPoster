<?php

declare(strict_types=1);

namespace App\Kernel\Security;

use App\Kernel\Exception\ConfigException;

/** Loads an external private key; errors never echo paths or key material. */
final class SecretKeyFile
{
    public static function read(string $path): string
    {
        if (!is_file($path) || is_link($path) || !in_array(fileperms($path) & 0777, [0400, 0600], true)) {
            throw new ConfigException('Secret key file must be a private regular file (0400/0600).');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new ConfigException('Secret key file is not readable.');
        }
        try {
            $stat = fstat($handle);
            clearstatcache(true, $path);
            $pathStat = lstat($path);
            if ($stat === false || $pathStat === false || $stat['ino'] !== $pathStat['ino'] || !in_array($stat['mode'] & 0777, [0400, 0600], true)) {
                throw new ConfigException('Secret key file changed while opening.');
            }
            $data = fread($handle, 129);
            if ($data === false || strlen($data) > 128) {
                throw new ConfigException('Invalid secret key encoding.');
            }
            $key = base64_decode(trim($data), true);
            if ($key === false || strlen($key) !== 32) {
                throw new ConfigException('Secret key must be 32 bytes, base64 encoded.');
            }
            return $key;
        } finally {
            fclose($handle);
        }
    }
}
