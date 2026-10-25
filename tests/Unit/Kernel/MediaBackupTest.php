<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Operations\BackupArchive;
use App\Kernel\Operations\BackupDestination;
use App\Kernel\Operations\MediaBackup;
use App\Tests\Support\FakeClock;
use App\Tests\Support\TestEnv;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Media offsite contract against AWS's mock handler: no network, no real credentials. */
final class MediaBackupTest extends TestCase
{
    private string $dir;
    private string $media;
    private string $backup;
    private string $keyFile;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/media-backup-' . bin2hex(random_bytes(6));
        $this->media = $this->dir . '/media';
        $this->backup = $this->dir . '/backup';
        mkdir($this->media . '/ws/1/2026/10', 0700, true);
        mkdir($this->backup, 0700);
        file_put_contents($this->media . '/ws/1/2026/10/a.jpg', 'synthetic image bytes');
        $this->keyFile = $this->dir . '/backup.key';
        file_put_contents($this->keyFile, base64_encode(random_bytes(32)));
        chmod($this->keyFile, 0600);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testNewFileIsEncryptedAndUploadedUnderMediaPrefix(): void
    {
        $commands = [];
        $body = '';
        $handler = $this->handler([
            new Result(['Status' => 'Enabled']),
            new Result(['Contents' => []]),
            static function (CommandInterface $command) use (&$commands, &$body): Result {
                $commands[] = $command;
                $body = (string) $command['Body'];

                return new Result(['VersionId' => 'v1']);
            },
            new Result(['Versions' => []]),
        ]);

        $result = $this->service($handler)->backup($this->backup);

        self::assertSame(['uploaded' => 1, 'unchanged' => 0, 'pruned' => 0], $result);
        self::assertCount(1, $commands);
        self::assertSame('media/ws/1/2026/10/a.jpg.ezb', $commands[0]['Key']);
        self::assertStringStartsWith("EZBACKUP1\n", $body);
        self::assertStringNotContainsString('synthetic image bytes', $body);
        self::assertSame([], glob($this->backup . '/media-*.ezb*'), 'temporary encrypted copies are removed');
    }

    public function testUnchangedFilesAreNotUploadedAgain(): void
    {
        $handler = $this->handler([
            new Result(['Status' => 'Enabled']),
            new Result(['Contents' => [['Key' => 'media/ws/1/2026/10/a.jpg.ezb', 'LastModified' => new DateTimeImmutable('+1 hour')]]]),
            new Result(['Versions' => []]),
        ]);

        self::assertSame(['uploaded' => 0, 'unchanged' => 1, 'pruned' => 0], $this->service($handler)->backup($this->backup));
    }

    public function testExpiredVersionsAndRemovedFilesArePruned(): void
    {
        $old = new DateTimeImmutable('2025-10-01');
        $deleted = [];
        $handler = $this->handler([
            new Result(['Status' => 'Enabled']),
            new Result(['Contents' => [['Key' => 'media/ws/1/2026/10/a.jpg.ezb', 'LastModified' => new DateTimeImmutable('+1 hour')]]]),
            new Result(['Versions' => [
                ['Key' => 'media/ws/1/2026/10/a.jpg.ezb', 'VersionId' => 'new', 'IsLatest' => true, 'LastModified' => $old],
                ['Key' => 'media/ws/1/2026/10/a.jpg.ezb', 'VersionId' => 'superseded', 'IsLatest' => false, 'LastModified' => $old],
                ['Key' => 'media/ws/1/2026/10/gone.jpg.ezb', 'VersionId' => 'gone', 'IsLatest' => true, 'LastModified' => $old],
                ['Key' => 'media/ws/1/2026/10/gone-recent.jpg.ezb', 'VersionId' => 'recent', 'IsLatest' => true, 'LastModified' => new DateTimeImmutable('-2 days')],
            ]]),
            static function (CommandInterface $command) use (&$deleted): Result {
                $deleted = array_column($command['Delete']['Objects'], 'VersionId');

                return new Result([]);
            },
        ]);

        self::assertSame(2, $this->service($handler)->backup($this->backup)['pruned']);
        self::assertSame(['superseded', 'gone'], $deleted);
    }

    public function testEmptyLibraryNeverPrunesTheOffsiteCopy(): void
    {
        unlink($this->media . '/ws/1/2026/10/a.jpg');
        $handler = $this->handler([new Result(['Status' => 'Enabled']), new Result(['Contents' => []])]);

        self::assertSame(['uploaded' => 0, 'unchanged' => 0, 'pruned' => 0], $this->service($handler)->backup($this->backup));
    }

    public function testMediaInS3IsLeftToBucketVersioning(): void
    {
        $service = $this->service($this->handler([]), ['MEDIA_DISK' => 's3']);

        self::assertSame(['uploaded' => 0, 'unchanged' => 0, 'pruned' => 0], $service->backup($this->backup));
    }

    public function testRestoreRoundTripsAndRefusesTheLiveLibrary(): void
    {
        $encrypted = $this->dir . '/a.jpg.ezb';
        $plain = $this->media . '/ws/1/2026/10/a.jpg';
        (new BackupArchive())->transform($plain, $encrypted, (new BackupArchive())->loadKey($this->keyFile));
        $handler = $this->handler([
            new Result(['Contents' => [['Key' => 'media/ws/1/2026/10/a.jpg.ezb', 'LastModified' => new DateTimeImmutable('-1 hour')]]]),
            static function (CommandInterface $command) use ($encrypted): Result {
                copy($encrypted, (string) ($command['@http']['sink'] ?? $command['SaveAs']));

                return new Result(['Metadata' => ['sha256' => hash_file('sha256', $encrypted)]]);
            },
        ]);
        $target = $this->dir . '/restored';

        self::assertSame(1, $this->service($handler)->restore($this->backup, $target));
        self::assertSame('synthetic image bytes', file_get_contents($target . '/ws/1/2026/10/a.jpg'));

        $this->expectExceptionMessage('overlap the live media library');
        $this->service($this->handler([]))->restore($this->backup, $this->media . '/inside');
    }

    /** @param list<mixed> $queue */
    private function handler(array $queue): MockHandler
    {
        return new MockHandler($queue);
    }

    /** @param array<string, string> $env */
    private function service(MockHandler $handler, array $env = []): MediaBackup
    {
        $config = TestEnv::config($env + ['MEDIA_DISK' => 'local', 'BACKUP_KEY_FILE' => $this->keyFile, 'BACKUP_RETENTION_DAYS' => '30',
            'BACKUP_S3_ENDPOINT' => 'https://backup.example', 'BACKUP_S3_BUCKET' => 'fixture']);
        $client = new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'handler' => $handler,
            'credentials' => ['key' => 'synthetic', 'secret' => 'synthetic'], 'retries' => 0]);

        return new MediaBackup($config, new FakeClock(gmdate('Y-m-d H:i:s')), new BackupArchive(), $this->media, new BackupDestination($config, $client));
    }
}
