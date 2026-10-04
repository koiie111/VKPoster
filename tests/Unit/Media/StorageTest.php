<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Integrations\Storage\FlysystemMediaStorage;
use App\Integrations\Storage\MediaStorageFactory;
use App\Integrations\Storage\StorageException;
use App\Kernel\Http\Response;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The storage layer on a real temp directory, and the streaming response used to hand files out.
 */
#[CoversClass(FlysystemMediaStorage::class)]
#[CoversClass(MediaStorageFactory::class)]
#[CoversClass(Response::class)]
final class StorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/media-storage-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    /**
     * @return resource
     */
    private function stream(string $content)
    {
        $stream = fopen('php://temp', 'r+b');
        self::assertNotFalse($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    private function storage(): FlysystemMediaStorage
    {
        $storage = MediaStorageFactory::create(TestEnv::config(['MEDIA_DISK' => 'local', 'MEDIA_LOCAL_ROOT' => $this->root]), '/ignored');
        self::assertInstanceOf(FlysystemMediaStorage::class, $storage);

        return $storage;
    }

    public function testRoundTripOnLocalDisk(): void
    {
        $storage = $this->storage();

        $storage->put('ws/1/2026/10/a.jpg', $this->stream('bytes'));

        self::assertTrue($storage->exists('ws/1/2026/10/a.jpg'));
        self::assertSame(5, $storage->size('ws/1/2026/10/a.jpg'));
        self::assertSame('bytes', stream_get_contents($storage->read('ws/1/2026/10/a.jpg')));
        self::assertSame('bytes', file_get_contents($this->root . '/ws/1/2026/10/a.jpg'));
        self::assertSame(0600, fileperms($this->root . '/ws/1/2026/10/a.jpg') & 0777, 'files are private to the app user');

        $storage->delete('ws/1/2026/10/a.jpg');
        self::assertFalse($storage->exists('ws/1/2026/10/a.jpg'));
        $storage->delete('ws/1/2026/10/a.jpg'); // deleting a missing object is not an error
    }

    public function testMissingObjectsRaiseStorageExceptions(): void
    {
        $storage = $this->storage();

        foreach ([static fn () => $storage->read('nope'), static fn () => $storage->size('nope')] as $call) {
            try {
                $call();
                self::fail('expected a StorageException');
            } catch (StorageException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRelativeRootIsResolvedAgainstTheProjectAndS3FactoryBuilds(): void
    {
        $local = MediaStorageFactory::create(TestEnv::config(['MEDIA_LOCAL_ROOT' => 'storage/media']), dirname(__DIR__, 3));
        self::assertFalse($local->exists('definitely/not/there.jpg'));

        $s3 = MediaStorageFactory::create(TestEnv::config(['MEDIA_DISK' => 's3', 'S3_ENDPOINT' => 'http://minio:9000', 'S3_KEY' => 'k', 'S3_SECRET' => 's', 'S3_BUCKET' => 'b']), '/x');
        self::assertInstanceOf(FlysystemMediaStorage::class, $s3);
    }

    public function testStreamResponseKeepsItsStreamThroughHeaderChanges(): void
    {
        $stream = $this->stream('0123456789');

        $response = Response::stream($stream, ['Content-Type' => 'text/plain'], 206, 2, 4)
            ->withHeader('X-Test', '1')->withStatus(206)->withBody('')->withCookie('a', 'b');

        self::assertSame($stream, $response->stream);
        self::assertSame([206, 2, 4, '1'], [$response->status, $response->streamStart, $response->streamLength, $response->header('X-Test')]);
    }

    public function testConfigDefaults(): void
    {
        $config = TestEnv::config();

        self::assertSame('local', $config->string('media.disk'));
        self::assertSame('storage/media', $config->string('media.local_root'));
        self::assertSame(3600, $config->int('media.signed_url_ttl'));
    }
}
