<?php

declare(strict_types=1);

namespace App\Kernel\Operations;

use RuntimeException;

/** Bounded-memory authenticated archive encryption; the key is external and never part of the archive. */
final class BackupArchive
{
    private const BLOCK = 65536;
    private const MAGIC = "EZBACKUP1\n";

    /** Load an external private key; callers must never log or serialize the returned bytes. */
    public function loadKey(string $path): string
    {
        if (!is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('External backup key must be a private regular file (0600).');
        }
        $key = base64_decode(trim((string) file_get_contents($path)), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Backup key must be base64 encoded 32 bytes.');
        }

        return $key;
    }

    /** Publish only complete authenticated files. Per-target locks survive crashes without stale ownership. */
    public function transform(string $input, string $output, string $key, bool $decrypt = false): void
    {
        if (is_link($output . '.lock')) {
            throw new RuntimeException('Invalid backup lock.');
        }
        $lock = fopen($output . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Backup lock unavailable.');
        }
        chmod($output . '.lock', 0600);
        $partial = $output . '.partial-' . bin2hex(random_bytes(12));
        try {
            if (!flock($lock, LOCK_EX) || file_exists($output) || is_link($output)) {
                throw new RuntimeException('Backup output already exists.');
            }
            // Only files in this target namespace, while holding its exclusive lock.
            foreach (\App\Support\Fs::glob($output . '.partial-*') as $stale) {
                if (is_file($stale) && !is_link($stale)) {
                    unlink($stale);
                }
            }
            $this->transformStream($input, $partial, $key, $decrypt);
            if (!$decrypt) {
                $this->transformStream($partial, $partial . '.verify', $key, true);
                if (!hash_equals((string) hash_file('sha256', $input), (string) hash_file('sha256', $partial . '.verify'))) {
                    throw new RuntimeException('Backup verification failed.');
                }
                unlink($partial . '.verify');
            }
            $sync = fopen($partial, 'r+b');
            if ($sync === false) {
                throw new RuntimeException('Backup sync unavailable.');
            }
            try {
                if (!fflush($sync) || !fsync($sync)) {
                    throw new RuntimeException('Backup sync failed.');
                }
            } finally {
                fclose($sync);
            }
            if (!rename($partial, $output)) {
                throw new RuntimeException('Backup publication failed.');
            }
            // Directory fsync is supported on Linux; platforms without it still retain atomic rename.
            $directory = fopen(dirname($output), 'r');
            if ($directory !== false) {
                @fsync($directory);
                fclose($directory);
            }
        } finally {
            foreach ([$partial, $partial . '.verify'] as $temporary) {
                if (is_file($temporary) && !is_link($temporary)) {
                    unlink($temporary);
                }
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function transformStream(string $input, string $output, string $key, bool $decrypt = false): void
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES || is_link($input) || file_exists($output)) {
            throw new RuntimeException('Invalid backup key/path or existing output.');
        }
        $in = fopen($input, 'rb');
        if ($in === false) {
            throw new RuntimeException('Backup input unavailable.');
        }
        $out = fopen($output, 'xb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Backup output unavailable.');
        }
        chmod($output, 0600);
        $complete = false;
        try {
            if (!$decrypt) {
                [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
                $this->write($out, self::MAGIC . $header);
                while (!feof($in)) {
                    $plain = fread($in, self::BLOCK);
                    if ($plain === false) {
                        throw new RuntimeException('Backup read failed.');
                    }
                    $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain);
                    $this->write($out, pack('N', strlen($cipher)) . $cipher);
                }
                $final = sodium_crypto_secretstream_xchacha20poly1305_push($state, '', '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
                $this->write($out, pack('N', strlen($final)) . $final);
            } else {
                if ($this->read($in, strlen(self::MAGIC)) !== self::MAGIC) {
                    throw new RuntimeException('Unknown backup format.');
                }
                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($this->read($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
                while (true) {
                    $size = unpack('Nsize', $this->read($in, 4));
                    $length = is_array($size) ? $size['size'] : 0;
                    if (!is_int($length) || $length < 17 || $length > self::BLOCK + 17) {
                        throw new RuntimeException('Invalid backup frame.');
                    }
                    $plain = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $this->read($in, $length));
                    if ($plain === false) {
                        throw new RuntimeException('Backup authentication failed.');
                    }
                    [$data, $tag] = $plain;
                    $this->write($out, $data);
                    if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                        if (fread($in, 1) !== '') {
                            throw new RuntimeException('Trailing backup data.');
                        }
                        break;
                    }
                }
            }
            fflush($out);
            $complete = true;
        } finally {
            fclose($in);
            fclose($out);
            if (!$complete) {
                unlink($output);
            }
        }
    }

    /** @param resource $stream */
    private function read($stream, int $size): string
    {
        $buffer = '';
        while (strlen($buffer) < $size) {
            $part = fread($stream, max(1, $size - strlen($buffer)));
            if ($part === false || $part === '') {
                throw new RuntimeException('Truncated backup.');
            }
            $buffer .= $part;
        }

        return $buffer;
    }

    /** @param resource $stream */
    private function write($stream, string $data): void
    {
        while ($data !== '') {
            $written = fwrite($stream, $data);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Backup write failed.');
            }
            $data = substr($data, $written);
        }
    }
}
