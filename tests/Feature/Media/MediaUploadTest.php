<?php

declare(strict_types=1);

namespace App\Tests\Feature\Media;

use App\Domain\Media\MediaService;
use App\Domain\Media\VideoInfo;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Media\MediaController;
use App\Tests\Support\MediaFixtures;
use App\Tests\Support\MediaTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MediaController::class)]
#[CoversClass(MediaService::class)]
final class MediaUploadTest extends MediaTestCase
{
    public function testUploadingAPhotoStoresReencodedFileAndThumbnail(): void
    {
        [, $workspace] = $this->ownerSession();

        $result = $this->upload($workspace, MediaFixtures::jpeg(80, 40), 'Лето 2026.jpg');

        self::assertSame(200, $result['response']->status);
        self::assertTrue($result['data']['ok']);
        $item = $result['data']['items'][0];
        self::assertSame('image', $item['kind']);
        self::assertSame('Лето 2026.jpg', $item['name']);
        self::assertSame('80×40', $item['dimensions']);
        self::assertFalse($item['duplicate']);

        $row = $this->db->select('SELECT * FROM media')[0];
        self::assertSame('image/jpeg', $row['mime']);
        self::assertMatchesRegularExpression('#^ws/\d+/\d{4}/\d{2}/[0-9a-z]{26}\.jpg$#', (string) $row['storage_key']);
        self::assertMatchesRegularExpression('#_thumb\.webp$#', (string) $row['thumb_key']);
        self::assertArrayHasKey((string) $row['storage_key'], $this->storage->objects);
        self::assertArrayHasKey((string) $row['thumb_key'], $this->storage->objects);
        self::assertSame(hash('sha256', MediaFixtures::jpeg(80, 40)), $row['sha256']);
    }

    public function testKeyNeverContainsTheClientFileName(): void
    {
        [, $workspace] = $this->ownerSession();

        $this->upload($workspace, MediaFixtures::jpeg(), '../../etc/passwd.jpg');

        $row = $this->db->select('SELECT storage_key, original_name FROM media')[0];
        self::assertStringNotContainsString('passwd', (string) $row['storage_key']);
        self::assertSame('passwd.jpg', $row['original_name']);
    }

    public function testPhpCodeNamedJpgIsRejectedAndNothingIsStored(): void
    {
        [, $workspace] = $this->ownerSession();

        $result = $this->upload($workspace, '<?php echo "owned"; ?>', 'shell.jpg');

        self::assertSame(422, $result['response']->status);
        self::assertFalse($result['data']['ok']);
        self::assertStringContainsString('не поддерживается', $result['data']['errors'][0]);
        self::assertSame([], $this->storage->objects);
        self::assertSame([], $this->db->select('SELECT id FROM media'));
    }

    public function testRealImageWithPhpExtensionIsStoredByContentNotByName(): void
    {
        [, $workspace] = $this->ownerSession();

        $this->upload($workspace, MediaFixtures::png(), 'picture.php');

        $row = $this->db->select('SELECT storage_key, mime FROM media')[0];
        self::assertSame('image/png', $row['mime']);
        self::assertStringEndsWith('.png', (string) $row['storage_key']);
    }

    public function testPolyglotJpegLosesItsPayloadAfterReencoding(): void
    {
        [, $workspace] = $this->ownerSession();
        $original = MediaFixtures::polyglotJpeg();
        self::assertStringContainsString(MediaFixtures::PAYLOAD, $original);

        $result = $this->upload($workspace, $original, 'innocent.jpg');

        self::assertSame(200, $result['response']->status);
        $key = (string) $this->db->select('SELECT storage_key FROM media')[0]['storage_key'];
        $stored = $this->storage->objects[$key];
        self::assertStringNotContainsString('<?php', $stored);
        self::assertStringNotContainsString('system(', $stored);
        self::assertSame("\xFF\xD8", substr($stored, 0, 2), 'still a JPEG');
        self::assertNotSame($original, $stored);
    }

    public function testExifOrientationIsAppliedAndMetadataDropped(): void
    {
        [, $workspace] = $this->ownerSession();

        // Orientation 6 (rotate 90° clockwise): a 60×20 picture must come out 20×60 and upright.
        $source = new \Imagick();
        $source->readImageBlob(MediaFixtures::jpegWithOrientation(60, 20, 6));
        self::assertSame(\Imagick::ORIENTATION_RIGHTTOP, $source->getImageOrientation(), 'the fixture carries the tag');
        $this->upload($workspace, MediaFixtures::jpegWithOrientation(60, 20, 6), 'rotated.jpg');

        $row = $this->db->select('SELECT width, height, storage_key FROM media')[0];
        self::assertSame(20, (int) $row['width']);
        self::assertSame(60, (int) $row['height']);
        $probe = new \Imagick();
        $probe->readImageBlob($this->storage->objects[(string) $row['storage_key']]);
        self::assertContains($probe->getImageOrientation(), [\Imagick::ORIENTATION_UNDEFINED, \Imagick::ORIENTATION_TOPLEFT], 'no rotation tag left');
        self::assertFalse($probe->getImageProperty('exif:Orientation'));
    }

    public function testHugeDimensionsAreRefusedBeforeDecoding(): void
    {
        [, $workspace] = $this->ownerSession();

        $result = $this->upload($workspace, MediaFixtures::pngClaiming(20000, 20000), 'bomb.png');

        self::assertSame(422, $result['response']->status);
        self::assertStringContainsString('слишком большое', $result['data']['errors'][0]);
        self::assertSame([], $this->storage->objects);
    }

    public function testSvgIsForbidden(): void
    {
        [, $workspace] = $this->ownerSession();

        $result = $this->upload($workspace, MediaFixtures::svg(), 'logo.svg');

        self::assertSame(422, $result['response']->status);
        self::assertSame([], $this->storage->objects);
    }

    public function testCorruptImageWithImageMimeIsRefused(): void
    {
        [, $workspace] = $this->ownerSession();

        // JPEG signature followed by garbage: content-type detection says JPEG, decoding must still fail cleanly.
        $result = $this->upload($workspace, "\xFF\xD8\xFF\xE0" . str_repeat('x', 200), 'broken.jpg');

        self::assertSame(422, $result['response']->status);
        self::assertSame([], $this->db->select('SELECT id FROM media'));
    }

    public function testAnimatedGifKeepsItsFrames(): void
    {
        [, $workspace] = $this->ownerSession();

        $this->upload($workspace, MediaFixtures::animatedGif(3), 'move.gif');

        $row = $this->db->select('SELECT animated, storage_key FROM media')[0];
        self::assertSame(1, (int) $row['animated']);
        $gif = new \Imagick();
        $gif->readImageBlob($this->storage->objects[(string) $row['storage_key']]);
        self::assertSame(3, $gif->getNumberImages());
    }

    public function testPdfIsStoredAsDocumentWithoutThumbnail(): void
    {
        [, $workspace] = $this->ownerSession();

        $result = $this->upload($workspace, MediaFixtures::pdf(), 'price.pdf');

        self::assertSame(200, $result['response']->status);
        $row = $this->db->select('SELECT kind, thumb_key, mime FROM media')[0];
        self::assertSame('document', $row['kind']);
        self::assertNull($row['thumb_key']);
        self::assertSame('application/pdf', $row['mime']);
    }

    public function testVideoIsProbedAndKeepsItsFactsAndPreview(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->probe->info = new VideoInfo(1080, 1920, 15_300, 'h264', true);

        $result = $this->upload($workspace, MediaFixtures::mp4(), 'clip.mp4');

        self::assertSame(200, $result['response']->status, $result['response']->body);
        $row = $this->db->select('SELECT * FROM media')[0];
        self::assertSame('video', $row['kind']);
        self::assertSame(1080, (int) $row['width']);
        self::assertSame(1920, (int) $row['height']);
        self::assertSame(15_300, (int) $row['duration_ms']);
        self::assertSame('h264', $row['codec']);
        self::assertMatchesRegularExpression('#_thumb\.jpg$#', (string) $row['thumb_key']);
        self::assertSame('0:15', $result['data']['items'][0]['duration']);
    }

    public function testUnreadableVideoAndTooLongVideoAreRefused(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->probe->info = null;
        $broken = $this->upload($workspace, MediaFixtures::mp4(), 'broken.mp4');
        self::assertSame(422, $broken['response']->status);

        $this->probe->info = new VideoInfo(1280, 720, 3_600_000, 'h264', true);
        $long = $this->upload($workspace, MediaFixtures::mp4(), 'long.mp4');
        self::assertSame(422, $long['response']->status);
        self::assertStringContainsString('длиннее', $long['data']['errors'][0]);
        self::assertSame([], $this->db->select('SELECT id FROM media'));
    }

    public function testVideoWithoutPreviewFrameIsStillAccepted(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->probe->frameAvailable = false;

        $result = $this->upload($workspace, MediaFixtures::mp4(), 'clip.mp4');

        self::assertSame(200, $result['response']->status);
        self::assertNull($this->db->select('SELECT thumb_key FROM media')[0]['thumb_key']);
    }

    public function testSameFileIsStoredOnceAndReportedAsDuplicate(): void
    {
        [, $workspace] = $this->ownerSession();
        $bytes = MediaFixtures::jpeg(50, 50);

        $first = $this->upload($workspace, $bytes, 'one.jpg');
        $objects = count($this->storage->objects);
        $second = $this->upload($workspace, $bytes, 'two.jpg');

        self::assertFalse($first['data']['items'][0]['duplicate']);
        self::assertTrue($second['data']['items'][0]['duplicate']);
        self::assertSame($first['data']['items'][0]['id'], $second['data']['items'][0]['id']);
        self::assertCount($objects, $this->storage->objects);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM media')[0]['c']);
    }

    public function testSameFileInAnotherWorkspaceIsNotADuplicate(): void
    {
        [, $first] = $this->ownerSession();
        $bytes = MediaFixtures::jpeg(50, 50);
        $this->upload($first, $bytes);
        [$other, $second] = $this->ownerWithWorkspace('other@example.com', 'Борис');
        $this->actAs($other);

        $result = $this->upload($second, $bytes);

        self::assertFalse($result['data']['items'][0]['duplicate']);
        self::assertSame(2, (int) $this->db->select('SELECT COUNT(*) AS c FROM media')[0]['c']);
    }

    public function testQuotaStopsUploadsThatDoNotFit(): void
    {
        [, $workspace] = $this->ownerSession();
        $quota = 40 * 1024 * 1024;
        $this->db->execute(
            'INSERT INTO media (public_id, workspace_id, kind, original_name, storage_key, mime, size, sha256, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6))',
            ['01JABCDEFGHJKMNPQRSTVWXYZ0', $workspace->id, 'document', 'big.pdf', 'ws/x/big.pdf', 'application/pdf', 500 * 1024 * 1024 - 10, str_repeat('a', 64)],
        );
        self::assertGreaterThan(0, $quota);

        $result = $this->upload($workspace, MediaFixtures::jpeg(400, 400), 'late.jpg');

        self::assertSame(422, $result['response']->status);
        self::assertStringContainsString('не хватает места', $result['data']['errors'][0]);
        self::assertSame([], $this->storage->objects);
    }

    public function testOversizedFileIsRefused(): void
    {
        [, $workspace] = $this->ownerSession();
        $path = $this->tempFile('');
        $handle = fopen($path, 'wb');
        self::assertNotFalse($handle);
        ftruncate($handle, 51 * 1024 * 1024);
        fclose($handle);
        $file = new \App\Kernel\Http\UploadedFile('huge.mp4', $path, 51 * 1024 * 1024, UPLOAD_ERR_OK);

        $response = $this->request('POST', $this->base($workspace) . '/media/upload', ['_token' => $this->csrfToken()], ['Accept' => 'application/json'], ['file' => $file]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Файл больше 50 МБ', $response->body);
    }

    public function testIniSizeErrorFromPhpGivesAFriendlyMessage(): void
    {
        [, $workspace] = $this->ownerSession();
        $file = new \App\Kernel\Http\UploadedFile('huge.mp4', '', 0, UPLOAD_ERR_INI_SIZE);

        $response = $this->request('POST', $this->base($workspace) . '/media/upload', ['_token' => $this->csrfToken()], ['Accept' => 'application/json'], ['file' => $file]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('больше', $response->body);
    }

    public function testNoFileGivesAnError(): void
    {
        [, $workspace] = $this->ownerSession();

        $response = $this->request('POST', $this->base($workspace) . '/media/upload', ['_token' => $this->csrfToken()], ['Accept' => 'application/json']);

        self::assertSame(422, $response->status);
    }

    public function testSeveralFilesInOneRequest(): void
    {
        [, $workspace] = $this->ownerSession();

        $response = $this->request(
            'POST',
            $this->base($workspace) . '/media/upload',
            ['_token' => $this->csrfToken()],
            ['Accept' => 'application/json'],
            ['files' => [$this->uploadedFile(MediaFixtures::jpeg(10, 10, 'red'), 'a.jpg'), $this->uploadedFile('nonsense', 'b.jpg'), $this->uploadedFile(MediaFixtures::png(), 'c.png')]],
        );

        /** @var array<string, mixed> $data */
        $data = json_decode($response->body, true);
        self::assertSame(200, $response->status);
        self::assertFalse($data['ok']);
        self::assertCount(2, $data['items']);
        self::assertCount(1, $data['errors']);
        self::assertStringStartsWith('b.jpg:', $data['errors'][0]);
    }

    public function testPlainFormUploadRedirectsWithAToast(): void
    {
        [, $workspace] = $this->ownerSession();

        $response = $this->request('POST', $this->base($workspace) . '/media/upload', ['_token' => $this->csrfToken()], [], ['file' => $this->uploadedFile(MediaFixtures::jpeg(), 'a.jpg')]);

        self::assertSame(302, $response->status);
        self::assertSame($this->base($workspace) . '/media', $response->header('Location'));
        self::assertStringContainsString('Файл добавлен', $this->get($this->base($workspace) . '/media')->body);
    }

    public function testUploadIntoAFolderAndIntoAForeignFolder(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->post($this->base($workspace) . '/media/folders', ['name' => 'Акции']);
        $folder = (string) $this->db->select('SELECT public_id FROM media_folders')[0]['public_id'];

        $ok = $this->upload($workspace, MediaFixtures::jpeg(), 'a.jpg', $folder);
        $bad = $this->upload($workspace, MediaFixtures::png(), 'b.png', '01JABCDEFGHJKMNPQRSTVWXYZ0');

        self::assertSame(200, $ok['response']->status);
        self::assertNotNull($this->db->select('SELECT folder_id FROM media WHERE original_name = ?', ['a.jpg'])[0]['folder_id']);
        self::assertSame(422, $bad['response']->status);
        self::assertStringContainsString('Такой папки нет', $bad['data']['errors'][0]);
    }

    public function testUploadNeedsCsrfToken(): void
    {
        [, $workspace] = $this->ownerSession();

        $response = $this->request('POST', $this->base($workspace) . '/media/upload', [], ['Accept' => 'application/json'], ['file' => $this->uploadedFile(MediaFixtures::jpeg(), 'a.jpg')]);

        self::assertSame(419, $response->status);
        self::assertSame([], $this->db->select('SELECT id FROM media'));
    }

    public function testGuestsViewersAndClientsCannotUpload(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $viewer = $this->memberOf($workspace, 'viewer@example.com', Role::Viewer);
        $client = $this->memberOf($workspace, 'client@example.com', Role::Client);

        $this->useBrowser();
        $guest = $this->request('POST', $this->base($workspace) . '/media/upload', ['_token' => $this->csrfToken()], ['Accept' => 'application/json'], ['file' => $this->uploadedFile(MediaFixtures::jpeg(), 'a.jpg')]);
        self::assertSame(401, $guest->status);

        foreach ([$viewer, $client] as $user) {
            $this->actAs($user);
            $response = $this->request('POST', $this->base($workspace) . '/media/upload', ['_token' => $this->csrfToken()], ['Accept' => 'application/json'], ['file' => $this->uploadedFile(MediaFixtures::jpeg(), 'a.jpg')]);
            self::assertSame(403, $response->status, (string) $user->email);
        }
        self::assertSame([], $this->db->select('SELECT id FROM media'));
    }

    public function testAuthorsMayUpload(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->actAsMember($workspace, 'author@example.com', Role::Author);

        $result = $this->upload($workspace, MediaFixtures::jpeg(), 'a.jpg');

        self::assertSame(200, $result['response']->status);
    }

    public function testOutsidersGetNotFoundOnAnotherWorkspace(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        [$stranger] = $this->ownerWithWorkspace('stranger@example.com', 'Чужой');
        $this->actAs($stranger);

        $result = $this->upload($workspace, MediaFixtures::jpeg(), 'a.jpg');

        self::assertSame(404, $result['response']->status);
        self::assertSame([], $this->db->select('SELECT id FROM media'));
    }

    public function testDisplayNameIsCleaned(): void
    {
        self::assertSame('a.jpg', MediaService::displayName("dir\\sub/a.jpg", 'jpg'));
        self::assertSame('file.png', MediaService::displayName('', 'png'));
        self::assertSame('file.png', MediaService::displayName('..', 'png'));
        self::assertSame('ab.jpg', MediaService::displayName("a\x00b.jpg", 'jpg'));
        self::assertLessThanOrEqual(255, strlen(MediaService::displayName(str_repeat('я', 300) . '.jpg', 'jpg')));
    }
}
