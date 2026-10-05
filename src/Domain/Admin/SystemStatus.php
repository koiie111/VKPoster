<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Kernel\Log\SecretRedactor;
use App\Support\Clock;
use App\Support\DbTime;
use App\Support\Heartbeat;
use DateTimeImmutable;
use DateTimeZone;

/**
 * How the machinery is doing: the queue (length, age of the oldest waiting job, failed jobs), the worker and scheduler heartbeats, MySQL,
 * Redis, disk, the media storage, the running version, and the latest errors from the log with secrets masked.
 * Every check is answered by the thing itself; a check that cannot be made says so instead of staying silent.
 */
final class SystemStatus
{
    /** A process that has not reported for this long is considered stopped. */
    public const STALE_SECONDS = 180;

    public function __construct(
        private readonly Connection $db,
        private readonly \Redis $redis,
        private readonly Heartbeat $heartbeat,
        private readonly Clock $clock,
        private readonly Config $config,
        private readonly string $logFile,
        private readonly string $diskPath,
    ) {
    }

    /**
     * @return array{queues: array<string, int>, waiting: int, running: int, failed: int, oldest_seconds: ?int, attempts_per_minute: float}
     */
    public function queue(): array
    {
        $now = DbTime::format($this->clock->now());
        $queues = [];
        foreach ($this->db->select('SELECT queue, COUNT(*) AS c FROM jobs GROUP BY queue ORDER BY queue') as $row) {
            $queues[(string) $row['queue']] = (int) $row['c'];
        }
        $oldest = $this->db->select('SELECT MIN(available_at) AS oldest FROM jobs WHERE reserved_at IS NULL AND available_at <= ?', [$now])[0]['oldest'] ?? null;
        $age = is_string($oldest) ? max(0, $this->clock->now()->getTimestamp() - (DbTime::parse($oldest)?->getTimestamp() ?? 0)) : null;
        $perMinute = (int) ($this->db->select('SELECT COUNT(*) AS c FROM publication_attempts WHERE finished_at >= ?', [DbTime::format($this->clock->now()->modify('-15 minutes'))])[0]['c'] ?? 0) / 15;

        return [
            'queues' => $queues,
            'waiting' => (int) ($this->db->select('SELECT COUNT(*) AS c FROM jobs WHERE reserved_at IS NULL')[0]['c'] ?? 0),
            'running' => (int) ($this->db->select('SELECT COUNT(*) AS c FROM jobs WHERE reserved_at IS NOT NULL')[0]['c'] ?? 0),
            'failed' => (int) ($this->db->select('SELECT COUNT(*) AS c FROM failed_jobs')[0]['c'] ?? 0),
            'oldest_seconds' => $age,
            'attempts_per_minute' => round($perMinute, 1),
        ];
    }

    /**
     * Checks as rows: name, state (`ok`, `warn` or `bad`) and a short explanation.
     *
     * @return list<array{name: string, state: string, text: string}>
     */
    public function checks(): array
    {
        $checks = [];
        foreach (['worker' => 'Обработчик задач', 'scheduler' => 'Планировщик'] as $name => $label) {
            $at = $this->heartbeat->at($name);
            $age = $at === null ? null : $this->clock->now()->getTimestamp() - $at->getTimestamp();
            $checks[] = [
                'name' => $label,
                'state' => $age === null ? 'bad' : ($age <= self::STALE_SECONDS ? 'ok' : 'bad'),
                'text' => $age === null ? 'Не отзывается: процесс не запущен или давно остановился.' : ($age <= self::STALE_SECONDS ? 'Работает, последний отклик ' . $age . ' с назад.' : 'Молчит уже ' . intdiv($age, 60) . ' мин.'),
            ];
        }
        try {
            $version = (string) ($this->db->select('SELECT VERSION() AS v')[0]['v'] ?? '');
            $checks[] = ['name' => 'MySQL', 'state' => 'ok', 'text' => 'Отвечает, версия ' . $version . '.'];
        } catch (\Throwable) {
            $checks[] = ['name' => 'MySQL', 'state' => 'bad', 'text' => 'Не отвечает.'];
        }
        try {
            $info = $this->redis->info('memory');
            $used = is_array($info) ? (int) ($info['used_memory'] ?? 0) : 0;
            $checks[] = ['name' => 'Redis', 'state' => 'ok', 'text' => 'Отвечает, занято памяти ' . self::bytes($used) . '.'];
        } catch (\Throwable) {
            $checks[] = ['name' => 'Redis', 'state' => 'bad', 'text' => 'Не отвечает: сессии и ограничения запросов не работают.'];
        }
        $free = @disk_free_space($this->diskPath);
        $total = @disk_total_space($this->diskPath);
        if ($free === false || $total === false || $total <= 0) {
            $checks[] = ['name' => 'Диск', 'state' => 'warn', 'text' => 'Не удалось узнать свободное место.'];
        } else {
            $share = $free / $total * 100;
            $checks[] = ['name' => 'Диск', 'state' => $share < 5 ? 'bad' : ($share < 15 ? 'warn' : 'ok'), 'text' => 'Свободно ' . self::bytes((int) $free) . ' из ' . self::bytes((int) $total) . ' (' . round($share) . '%).'];
        }
        $disk = $this->config->string('media.disk', 'local');
        $checks[] = $disk === 'local'
            ? ['name' => 'Хранилище файлов', 'state' => is_writable($this->diskPath) ? 'ok' : 'bad', 'text' => is_writable($this->diskPath) ? 'Локальный диск, запись доступна.' : 'Локальный диск недоступен для записи.']
            : ['name' => 'Хранилище файлов', 'state' => 'ok', 'text' => 'Объектное хранилище (S3), настройки заданы.'];

        return $checks;
    }

    /**
     * @return array{version: string, deployed_at: ?DateTimeImmutable, environment: string, php: string}
     */
    public function build(): array
    {
        $deployed = $this->config->string('app.deployed_at');
        try {
            $at = $deployed === '' ? null : new DateTimeImmutable($deployed, new DateTimeZone('UTC'));
        } catch (\Exception) {
            $at = null;
        }

        return [
            'version' => $this->config->string('app.version', 'не указана (APP_VERSION)') === '' ? 'не указана (APP_VERSION)' : $this->config->string('app.version'),
            'deployed_at' => $at,
            'environment' => $this->config->string('app.env'),
            'php' => PHP_VERSION,
        ];
    }

    /**
     * The newest errors of the log (level ERROR and above), without their context (which may hold personal data), secrets masked.
     *
     * @return list<array{at: string, level: string, message: string, exception: string, where: string}>
     */
    public function errors(int $limit = 20): array
    {
        if (!is_file($this->logFile) || !is_readable($this->logFile)) {
            return [];
        }
        $size = (int) filesize($this->logFile);
        $handle = fopen($this->logFile, 'rb');
        if ($handle === false) {
            return [];
        }
        $chunk = 1024 * 1024;
        fseek($handle, max(0, $size - $chunk));
        $text = (string) stream_get_contents($handle);
        fclose($handle);
        $lines = array_reverse(explode("\n", trim($text)));
        $redactor = new SecretRedactor();
        $result = [];
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record) || (int) ($record['level'] ?? 0) < 400) {
                continue;
            }
            $context = is_array($record['context'] ?? null) ? $redactor->redact($record['context']) : [];
            $exception = is_array($context['exception'] ?? null) ? $context['exception'] : [];
            $result[] = [
                'at' => (string) ($record['datetime'] ?? ''),
                'level' => (string) ($record['level_name'] ?? 'ERROR'),
                'message' => mb_substr((string) ($record['message'] ?? ''), 0, 200),
                'exception' => mb_substr(is_string($exception['class'] ?? null) ? $exception['class'] : '', 0, 120),
                'where' => mb_substr(is_string($exception['file'] ?? null) ? basename($exception['file']) : (is_string($context['path'] ?? null) ? $context['path'] : ''), 0, 120),
            ];
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public static function bytes(int $bytes): string
    {
        foreach (['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'] as $i => $unit) {
            if ($bytes < 1024 || $i === 4) {
                return ($i === 0 ? (string) $bytes : number_format($bytes, $bytes < 10 ? 1 : 0, ',', "\u{00A0}")) . "\u{00A0}" . $unit;
            }
            $bytes /= 1024;
        }

        return (string) $bytes;
    }
}
