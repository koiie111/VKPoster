<?php

declare(strict_types=1);

namespace App\Kernel\Security;

use App\Kernel\Exception\HttpException;

/** Bounded ClamAV INSTREAM client over a private TCP/Unix socket, with a total deadline and fail-closed errors. */
final class ClamdScanner implements MalwareScanner
{
    /** @param null|\Closure(): resource $connector Test-only duplex stream factory. */
    public function __construct(private readonly string $endpoint, private readonly int $limit = 52428800, private readonly int $timeout = 15, private readonly ?\Closure $connector = null)
    {
        $parts = parse_url($endpoint);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $private = $host === 'localhost' || $host === 'clamav' || (filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);
        if ((!str_starts_with($endpoint, 'unix:///') && (!is_array($parts) || ($parts['scheme'] ?? '') !== 'tcp' || !$private || isset($parts['user']) || isset($parts['pass']) || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])))
            || $limit < 1 || $limit > 524288000 || $timeout < 1 || $timeout > 60) {
            throw new \InvalidArgumentException('Invalid malware scanner configuration.');
        }
    }

    public function scanFile(string $path): void
    {
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new HttpException(503, 'Проверка безопасности файла временно недоступна.');
        }
        try {
            $this->scan($file);
        } finally {
            fclose($file);
        }
    }

    public function scanBytes(string $bytes): void
    {
        if (strlen($bytes) > $this->limit) {
            throw new HttpException(413, 'Файл слишком большой для проверки безопасности.');
        }
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) {
            throw new HttpException(503, 'Проверка безопасности файла временно недоступна.');
        }
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes)) {
                throw new HttpException(503, 'Проверка безопасности файла временно недоступна.');
            }
            rewind($stream);
            $this->scan($stream);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $input */
    private function scan(mixed $input): void
    {
        $socket = null;
        $deadline = microtime(true) + $this->timeout;
        try {
            $socket = $this->connector !== null ? ($this->connector)() : @stream_socket_client($this->endpoint, $errno, $error, min(3, $this->timeout));
            if (!is_resource($socket)) {
                throw new \RuntimeException('scanner unavailable');
            }
            stream_set_timeout($socket, $this->timeout);
            $this->write($socket, "zINSTREAM\0", $deadline);
            $size = 0;
            while (!feof($input)) {
                $bytes = fread($input, 65536);
                if ($bytes === false) {
                    throw new \RuntimeException('read failed');
                }
                $size += strlen($bytes);
                if ($size > $this->limit) {
                    throw new HttpException(413, 'Файл слишком большой для проверки безопасности.');
                }
                if ($bytes !== '') {
                    $this->write($socket, pack('N', strlen($bytes)) . $bytes, $deadline);
                }
            }
            $this->write($socket, pack('N', 0), $deadline);
            $response = '';
            while (!str_contains($response, "\0") && strlen($response) < 1024 && !feof($socket)) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new \RuntimeException('deadline');
                }
                stream_set_timeout($socket, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
                $part = fread($socket, 1024 - strlen($response));
                if ($part === false || $part === '') {
                    throw new \RuntimeException('incomplete response');
                }
                $response .= $part;
            }
            if ($response === "stream: OK\0") {
                return;
            }
            if (preg_match('/^stream: [^\x00\r\n]{1,900} FOUND\x00$/D', $response) === 1) {
                throw new HttpException(422, 'Файл не прошёл проверку безопасности.');
            }
            throw new \RuntimeException('scanner rejected');
        } catch (HttpException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new HttpException(503, 'Проверка безопасности файла временно недоступна.');
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    /** @param resource $stream */
    private function write(mixed $stream, string $bytes, float $deadline): void
    {
        while ($bytes !== '') {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new \RuntimeException('deadline');
            }
            stream_set_timeout($stream, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
            $n = fwrite($stream, $bytes);
            if ($n === false || $n === 0) {
                throw new \RuntimeException('write failed');
            }
            $bytes = substr($bytes, $n);
        }
    }
}
