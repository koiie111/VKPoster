<?php

declare(strict_types=1);

namespace App\Tests\Feature\Media;

use App\Domain\Media\Media;
use App\Domain\Media\MediaUrls;
use App\Domain\Workspace\Role;
use App\Http\Controllers\Media\MediaFileController;
use App\Kernel\Http\Response;
use App\Tests\Support\MediaFixtures;
use App\Tests\Support\MediaTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MediaFileController::class)]
final class MediaFileTest extends MediaTestCase
{
    private function content(Response $response): string
    {
        return $this->body($response);
    }

    private function path(Media $media, string $variant = 'original'): string
    {
        return '/media/' . $media->publicId . '/' . $variant;
    }

    public function testMemberGetsTheFileWithSafeHeaders(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(80, 40), 'Лето.jpg');

        $response = $this->get($this->path($media));

        self::assertSame(200, $response->status);
        self::assertSame('image/jpeg', $response->header('Content-Type'));
        self::assertSame('nosniff', $response->header('X-Content-Type-Options'));
        self::assertStringContainsString('sandbox', (string) $response->header('Content-Security-Policy'));
        self::assertStringStartsWith('inline;', (string) $response->header('Content-Disposition'));
        self::assertStringContainsString("filename*=UTF-8''%D0%9B%D0%B5%D1%82%D0%BE.jpg", (string) $response->header('Content-Disposition'));
        self::assertSame((string) $media->size, $response->header('Content-Length'));
        self::assertSame($this->storage->objects[$media->storageKey], $this->content($response));
        self::assertSame('same-origin', $response->header('Cross-Origin-Resource-Policy'));
    }

    public function testDocumentsAreDownloadsAndDownloadFlagForcesAttachment(): void
    {
        [, $workspace] = $this->ownerSession();
        $pdf = $this->uploadOk($workspace, MediaFixtures::pdf(), 'price.pdf');
        $photo = $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');

        self::assertStringStartsWith('attachment;', (string) $this->get($this->path($pdf))->header('Content-Disposition'));
        self::assertStringStartsWith('attachment;', (string) $this->get($this->path($photo) . '?download=1')->header('Content-Disposition'));
        self::assertSame('application/pdf', $this->get($this->path($pdf))->header('Content-Type'));
    }

    public function testThumbnailIsServedAndVideoThumbIsJpeg(): void
    {
        [, $workspace] = $this->ownerSession();
        $photo = $this->uploadOk($workspace, MediaFixtures::jpeg(800, 600), 'a.jpg');
        $video = $this->uploadOk($workspace, MediaFixtures::mp4(), 'v.mp4');
        $pdf = $this->uploadOk($workspace, MediaFixtures::pdf(), 'p.pdf');

        $thumb = $this->get($this->path($photo, 'thumb'));
        self::assertSame(200, $thumb->status);
        self::assertSame('image/webp', $thumb->header('Content-Type'));
        $image = new \Imagick();
        $image->readImageBlob($this->content($thumb));
        self::assertSame(320, $image->getImageWidth());
        self::assertSame(240, $image->getImageHeight());
        self::assertSame('image/jpeg', $this->get($this->path($video, 'thumb'))->header('Content-Type'));
        self::assertSame(404, $this->get($this->path($pdf, 'thumb'))->status);
    }

    public function testCroppedVariantIsRenderedOnceAndCached(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(1000, 500), 'wide.jpg');
        $before = count($this->storage->objects);

        $first = $this->get($this->path($media, '1x1'));
        $afterFirst = count($this->storage->objects);
        $second = $this->get($this->path($media, '1x1'));

        self::assertSame(200, $first->status);
        $image = new \Imagick();
        $image->readImageBlob($this->content($first));
        self::assertSame([500, 500], [$image->getImageWidth(), $image->getImageHeight()]);
        self::assertSame($before + 1, $afterFirst);
        self::assertSame($afterFirst, count($this->storage->objects), 'the second request reuses the cached file');
        self::assertSame($this->content($first), $this->content($second));
        $cached = json_decode((string) $this->db->select('SELECT variants_json FROM media')[0]['variants_json'], true);
        self::assertCount(1, $cached);
    }

    public function testVariantRatios(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(1000, 1000), 'sq.jpg');

        foreach (['4x5' => [800, 1000], '191x100' => [1000, 524], '9x16' => [563, 1000]] as $slug => [$w, $h]) {
            $image = new \Imagick();
            $image->readImageBlob($this->content($this->get($this->path($media, $slug))));
            self::assertEqualsWithDelta($w, $image->getImageWidth(), 1, $slug);
            self::assertEqualsWithDelta($h, $image->getImageHeight(), 1, $slug);
        }
    }

    public function testUnknownVariantsAndVariantsOfNonPicturesAreNotFound(): void
    {
        [, $workspace] = $this->ownerSession();
        $photo = $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');
        $gif = $this->uploadOk($workspace, MediaFixtures::animatedGif(), 'a.gif');
        $pdf = $this->uploadOk($workspace, MediaFixtures::pdf(), 'a.pdf');

        self::assertSame(404, $this->get($this->path($photo, '2x3'))->status);
        self::assertSame(404, $this->get($this->path($photo, 'original-wm'))->status, 'no watermark configured yet');
        self::assertSame(404, $this->get($this->path($gif, '1x1'))->status);
        self::assertSame(404, $this->get($this->path($pdf, '1x1'))->status);
        self::assertSame(200, $this->get($this->path($gif))->status);
    }

    public function testRangeRequests(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::mp4(), 'v.mp4');
        $bytes = $this->storage->objects[$media->storageKey];

        $partial = $this->get($this->path($media), ['Range' => 'bytes=0-99']);
        self::assertSame(206, $partial->status);
        self::assertSame('bytes 0-99/' . strlen($bytes), $partial->header('Content-Range'));
        self::assertSame('100', $partial->header('Content-Length'));
        self::assertSame(substr($bytes, 0, 100), $this->content($partial));

        $tail = $this->get($this->path($media), ['Range' => 'bytes=-10']);
        self::assertSame(206, $tail->status);
        self::assertSame(substr($bytes, -10), $this->content($tail));

        $open = $this->get($this->path($media), ['Range' => 'bytes=2000-']);
        self::assertSame(206, $open->status);
        self::assertSame(substr($bytes, 2000), $this->content($open));

        self::assertSame(416, $this->get($this->path($media), ['Range' => 'bytes=999999-'])->status);
        self::assertSame(200, $this->get($this->path($media), ['Range' => 'nonsense'])->status);
    }

    public function testConditionalRequestGets304(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');
        $etag = (string) $this->get($this->path($media))->header('ETag');

        $response = $this->get($this->path($media), ['If-None-Match' => $etag]);

        self::assertSame(304, $response->status);
        self::assertSame('', $this->content($response));
    }

    public function testGuestsGetNotFound(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');

        $this->useBrowser();

        self::assertSame(404, $this->get($this->path($media))->status);
        self::assertSame(404, $this->get($this->path($media, 'thumb'))->status);
    }

    public function testMembersOfAnotherWorkspaceGetNotFound(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');
        [$stranger] = $this->ownerWithWorkspace('stranger@example.com', 'Чужой');
        $this->actAs($stranger);

        self::assertSame(404, $this->get($this->path($media))->status);
        self::assertSame(404, $this->get($this->path($media, 'thumb'))->status);
        self::assertSame(404, $this->get($this->path($media, '1x1'))->status);
    }

    public function testViewersMayLookButClientsMayNot(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $this->actAs($owner);
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');
        $viewer = $this->memberOf($workspace, 'viewer@example.com', Role::Viewer);
        $client = $this->memberOf($workspace, 'client@example.com', Role::Client);

        $this->actAs($viewer);
        self::assertSame(200, $this->get($this->path($media))->status);
        $this->actAs($client);
        self::assertSame(404, $this->get($this->path($media))->status);
    }

    public function testNonexistentMediaIsNotFound(): void
    {
        $this->ownerSession();

        self::assertSame(404, $this->get('/media/01JABCDEFGHJKMNPQRSTVWXYZ0/original')->status);
    }

    // ---- signed links ----

    private function signed(Media $media, string $variant = 'original', int $ttl = 600): string
    {
        $url = $this->app->container()->get(MediaUrls::class)->signed($media, $variant, $ttl);
        self::assertStringStartsWith('http://localhost/media/', $url);

        return substr($url, strlen('http://localhost'));
    }

    public function testSignedLinkWorksWithoutASession(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');
        $link = $this->signed($media);

        $this->useBrowser();
        $response = $this->get($link);

        self::assertSame(200, $response->status);
        self::assertSame('cross-origin', $response->header('Cross-Origin-Resource-Policy'));
        self::assertSame($this->storage->objects[$media->storageKey], $this->content($response));
    }

    public function testSignedLinkWorksForVariantsAndThumbs(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(400, 200), 'a.jpg');
        $square = $this->signed($media, '1x1');
        $thumb = $this->signed($media, 'thumb');

        $this->useBrowser();

        self::assertSame(200, $this->get($square)->status);
        self::assertSame(200, $this->get($thumb)->status);
    }

    public function testExpiredSignedLinkIsForbidden(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');
        $link = $this->signed($media, 'original', 60);

        $this->useBrowser();
        self::assertSame(200, $this->get($link)->status);
        $this->clock->advance(61);

        self::assertSame(403, $this->get($link)->status);
    }

    public function testForgedSignedLinksAreForbidden(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');
        $other = $this->uploadOk($workspace, MediaFixtures::png(), 'b.png');
        $link = $this->signed($media);
        $this->useBrowser();

        // Someone else's file with this file's signature.
        self::assertSame(403, $this->get(str_replace($media->publicId, $other->publicId, $link))->status);
        // A different variant with the same signature.
        self::assertSame(403, $this->get(str_replace('/original', '/1x1', $link))->status);
        // Extended lifetime.
        self::assertSame(403, $this->get((string) preg_replace('/expires=\d+/', 'expires=' . (time() + 99999), $link))->status);
        // Garbage signature, and a signature without expiry made up by hand.
        self::assertSame(403, $this->get($this->path($media) . '?signature=abc')->status);
        self::assertSame(403, $this->get($this->path($media) . '?expires=' . (time() + 600) . '&signature=' . str_repeat('A', 43))->status);
        // Dropping the signature leaves a plain guest request.
        self::assertSame(404, $this->get($this->path($media))->status);
    }

    public function testInvalidSignatureIsForbiddenEvenForMembers(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');

        self::assertSame(403, $this->get($this->path($media) . '?signature=abc')->status);
    }

    public function testSignedLinkForADeletedFileIsNotFound(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(), 'a.jpg');
        $link = $this->signed($media);
        $this->post($this->base($workspace) . '/media/' . $media->publicId . '/delete');
        $this->useBrowser();

        self::assertSame(404, $this->get($link)->status);
    }

    public function testFilesAreNotReachableThroughThePublicDirectory(): void
    {
        $root = dirname(__DIR__, 3);
        $config = (string) file_get_contents($root . '/docker/nginx/default.conf');

        self::assertMatchesRegularExpression('#root /var/www/html/public;#', $config);
        self::assertDirectoryDoesNotExist($root . '/public/media');
        self::assertDirectoryDoesNotExist($root . '/public/uploads');
        self::assertSame($root . '/storage/media', realpath($root . '/storage/media'));
        self::assertStringNotContainsString($root . '/public', (string) realpath($root . '/storage/media'));
        // Not even by guessing the storage path.
        self::assertSame(404, $this->get('/storage/media/ws/1/2026/10/x.jpg')->status);
        self::assertSame(404, $this->get('/ws/1/2026/10/x.jpg')->status);
    }
}
