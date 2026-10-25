<?php

declare(strict_types=1);

namespace App\Tests\Unit\Kernel;

use App\Kernel\Operations\BackupArchive;
use App\Kernel\Operations\BackupDestination;
use App\Tests\Support\TestEnv;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;

/** S3 contracts run against AWS's mock handler, never the network or real credentials. */
final class BackupDestinationTest extends TestCase
{
    public function testVersioningAndEncryptedUploadContract(): void
    {
        $commands = [];
        $handler = new MockHandler([
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = $command;
                return new Result(['Status' => 'Enabled']);
            },
            static function (CommandInterface $command) use (&$commands): Result {
                $commands[] = $command;
                return new Result(['VersionId' => 'fixture-version']);
            },
        ]);
        $this->withArchive(function (string $file) use ($handler, &$commands): void {
            $this->destination($handler)->upload($file);
            self::assertCount(2, $commands);
            self::assertSame('GetBucketVersioning', $commands[0]->getName());
            self::assertSame('PutObject', $commands[1]->getName());
            self::assertSame('backups/' . basename($file), $commands[1]['Key']);
            self::assertSame(hash_file('sha256', $file), $commands[1]['Metadata']['sha256']);
            self::assertSame('application/octet-stream', $commands[1]['ContentType']);
        });
    }

    public function testDisabledVersioningPreventsUpload(): void
    {
        $handler = new MockHandler([new Result(['Status' => 'Suspended'])]);
        $this->expectExceptionMessage('versioning must be enabled');
        $this->withArchive(fn (string $file) => $this->destination($handler)->upload($file));
    }

    public function testTransportFailureIsNotReportedAsSuccess(): void
    {
        $handler = new MockHandler([new Result(['Status' => 'Enabled']),
            static fn (CommandInterface $command) => new AwsException('synthetic transport failure', $command),
        ]);
        $this->expectException(AwsException::class);
        $this->withArchive(fn (string $file) => $this->destination($handler)->upload($file));
    }

    public function testRetentionDropsOldVersionsButKeepsTheNewestSnapshots(): void
    {
        $day = 86400;
        $deleted = [];
        $versions = [];
        foreach ([5, 10, 40, 41, 50] as $age) {
            $versions[] = ['Key' => 'backups/b' . $age . '.ezb', 'VersionId' => 'v' . $age, 'IsLatest' => true, 'LastModified' => new \DateTimeImmutable('-' . $age . ' days')];
        }
        $handler = new MockHandler([
            new Result(['Versions' => $versions]),
            static function (CommandInterface $command) use (&$deleted): Result {
                $deleted = array_column($command['Delete']['Objects'], 'VersionId');

                return new Result([]);
            },
        ]);

        self::assertSame(2, $this->destination($handler)->pruneBackups(time() - 30 * $day));
        self::assertEqualsCanonicalizing(['v41', 'v50'], $deleted);
    }

    public function testPlainSqlIsNeverUploaded(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'plain-backup-');
        self::assertIsString($file);
        file_put_contents($file, 'private SQL contents');
        try {
            $this->expectExceptionMessage('Only encrypted');
            $this->destination(new MockHandler())->upload($file);
        } finally {
            unlink($file);
        }
    }

    public function testMediaBucketMustBeVersioned(): void
    {
        $handler = new MockHandler([new Result(['Status' => 'Enabled']), new Result(['Status' => 'Suspended'])]);
        $config = TestEnv::config(['MEDIA_DISK' => 's3', 'S3_ENDPOINT' => 'https://media.example', 'S3_BUCKET' => 'media-fixture']);
        $client = new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'handler' => $handler,
            'credentials' => ['key' => 'synthetic', 'secret' => 'synthetic'], 'retries' => 0]);
        $destination = new BackupDestination($config, $client);
        $destination->verifyMediaVersioning();
        self::assertSame('media-fixture', $handler->getLastCommand()['Bucket']);
        $this->expectExceptionMessage('Media bucket versioning must be enabled');
        $destination->verifyMediaVersioning();
    }

    public function testLocalMediaCannotBeClaimedAsVersionedOffsiteBackup(): void
    {
        $this->expectExceptionMessage('not configured');
        $this->destination(new MockHandler())->verifyMediaVersioning();
    }

    private function destination(MockHandler $handler): BackupDestination
    {
        $config = TestEnv::config(['BACKUP_S3_ENDPOINT' => 'https://backup.example', 'BACKUP_S3_BUCKET' => 'fixture']);
        $client = new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'handler' => $handler,
            'credentials' => ['key' => 'synthetic', 'secret' => 'synthetic'], 'retries' => 0]);

        return new BackupDestination($config, $client);
    }

    /** @param callable(string): void $callback */
    private function withArchive(callable $callback): void
    {
        $plain = tempnam(sys_get_temp_dir(), 'backup-contract-');
        self::assertIsString($plain);
        $encrypted = $plain . '.ezb';
        try {
            file_put_contents($plain, 'synthetic SQL');
            (new BackupArchive())->transform($plain, $encrypted, random_bytes(32));
            $callback($encrypted);
        } finally {
            unlink($plain);
            if (is_file($encrypted)) {
                unlink($encrypted);
            }
        }
    }
}
