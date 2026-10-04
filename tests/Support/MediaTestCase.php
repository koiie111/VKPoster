<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Media\Media;
use App\Domain\Media\MediaUsageChecker;
use App\Domain\Media\VideoProbe;
use App\Domain\User\User;
use App\Domain\Workspace\Workspace;
use App\Integrations\Storage\MediaStorage;
use App\Kernel\Http\Response;
use App\Kernel\Http\UploadedFile;

/**
 * Base class for media tests: in-memory storage, a fake video probe, and helpers to upload files the way the
 * browser does (multipart with a CSRF token) and to read what ended up in storage.
 */
abstract class MediaTestCase extends WorkspaceTestCase
{
    protected ArrayMediaStorage $storage;
    protected FakeVideoProbe $probe;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new ArrayMediaStorage();
        $this->probe = new FakeVideoProbe();
        $container = $this->app->container();
        $container->instance(MediaStorage::class, $this->storage);
        $container->instance(VideoProbe::class, $this->probe);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * The full body of a response, whether it is held in memory or streamed from a file.
     */
    protected function body(Response $response): string
    {
        if (!is_resource($response->stream)) {
            return $response->body;
        }
        rewind($response->stream);

        return (string) stream_get_contents($response->stream, $response->streamLength, $response->streamStart);
    }

    protected function tempFile(string $bytes): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mt');
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }

    protected function uploadedFile(string $bytes, string $name): UploadedFile
    {
        $path = $this->tempFile($bytes);

        return new UploadedFile($name, $path, strlen($bytes), UPLOAD_ERR_OK);
    }

    /**
     * Upload a file through the HTTP endpoint as the signed-in user (JSON answer, like the drag-and-drop widget).
     *
     * @return array{response: Response, data: array<string, mixed>}
     */
    protected function upload(Workspace $workspace, string $bytes, string $name = 'photo.jpg', string $folder = ''): array
    {
        $response = $this->request(
            'POST',
            $this->base($workspace) . '/media/upload',
            ['_token' => $this->csrfToken(), 'folder' => $folder],
            ['Accept' => 'application/json'],
            ['file' => $this->uploadedFile($bytes, $name)],
        );
        $data = json_decode($response->body, true);
        self::assertIsArray($data);

        return ['response' => $response, 'data' => $data];
    }

    /**
     * Upload and return the stored library item, failing the test when the upload was refused.
     */
    protected function uploadOk(Workspace $workspace, string $bytes, string $name = 'photo.jpg', string $folder = ''): Media
    {
        $result = $this->upload($workspace, $bytes, $name, $folder);
        self::assertSame(200, $result['response']->status, $result['response']->body);
        $items = $result['data']['items'] ?? [];
        self::assertIsArray($items);
        self::assertIsArray($items[0] ?? null);
        $row = $this->db->select('SELECT * FROM media WHERE public_id = ?', [(string) $items[0]['id']]);
        self::assertNotEmpty($row);

        return \App\Domain\Media\MediaRepository::hydrate($row[0]);
    }

    /**
     * Replace the "is this file still needed" check (call before the first media request of the test).
     */
    protected function usageChecker(MediaUsageChecker $checker): void
    {
        $this->app->container()->instance(MediaUsageChecker::class, $checker);
    }

    /**
     * @return array{User, Workspace} the owner of a fresh personal workspace, already signed in
     */
    protected function ownerSession(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);

        return [$owner, $workspace];
    }

    protected function actAsMember(Workspace $workspace, string $email, \App\Domain\Workspace\Role $role): User
    {
        $user = $this->memberOf($workspace, $email, $role);
        $this->actAs($user);

        return $user;
    }
}
