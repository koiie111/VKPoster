<?php

declare(strict_types=1);

namespace App\Tests\Feature\Media;

use App\Domain\Media\WatermarkService;
use App\Http\Controllers\Media\WatermarkController;
use App\Tests\Support\MediaFixtures;
use App\Tests\Support\MediaTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(WatermarkController::class)]
#[CoversClass(WatermarkService::class)]
final class WatermarkTest extends MediaTestCase
{
    private function uploadLogo(\App\Domain\Workspace\Workspace $workspace, string $bytes, string $name = 'Лого'): \App\Kernel\Http\Response
    {
        return $this->request(
            'POST',
            $this->base($workspace) . '/media/watermarks',
            ['_token' => $this->csrfToken(), 'name' => $name],
            [],
            ['logo' => $this->uploadedFile($bytes, 'logo.png')],
        );
    }

    private function logoPng(): string
    {
        $image = new \Imagick();
        $image->newImage(200, 100, new \ImagickPixel('white'));
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }

    public function testPageWithoutWatermarksInvitesToUploadOne(): void
    {
        [, $workspace] = $this->ownerSession();

        $page = $this->get($this->base($workspace) . '/media/watermarks');

        self::assertSame(200, $page->status);
        self::assertStringContainsString('Водяного знака пока нет', $this->text($page));
    }

    public function testUploadStoresACleanPngAndMakesTheFirstOneDefault(): void
    {
        [, $workspace] = $this->ownerSession();

        $response = $this->uploadLogo($workspace, $this->logoPng());

        self::assertSame(302, $response->status);
        $row = $this->db->select('SELECT * FROM watermarks')[0];
        self::assertSame('Лого', $row['name']);
        self::assertSame(1, (int) $row['is_default']);
        self::assertSame([200, 100], [(int) $row['width'], (int) $row['height']]);
        self::assertSame('br', $row['position']);
        self::assertMatchesRegularExpression('#^ws/\d+/watermarks/[0-9a-z]{26}\.png$#', (string) $row['storage_key']);
        self::assertContains('media.watermark_created', $this->auditActions($workspace));
        $page = $this->get($this->base($workspace) . '/media/watermarks');
        self::assertStringContainsString('Используется по умолчанию', $this->text($page));
        self::assertStringContainsString('/preview"', $page->body);
    }

    public function testOnlyPngLogosAreAccepted(): void
    {
        [, $workspace] = $this->ownerSession();

        $this->uploadLogo($workspace, MediaFixtures::jpeg());
        $this->uploadLogo($workspace, MediaFixtures::svg());
        $this->uploadLogo($workspace, '<?php echo 1;');
        $this->uploadLogo($workspace, MediaFixtures::pngClaiming(5000, 5000));

        self::assertSame([], $this->db->select('SELECT id FROM watermarks'));
        self::assertSame([], $this->storage->objects);
    }

    public function testSecondLogoIsNotDefaultAndSettingsAreValidated(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->uploadLogo($workspace, $this->logoPng(), 'Первый');
        $this->uploadLogo($workspace, MediaFixtures::png(50, 50), 'Второй');
        $second = $this->db->select("SELECT public_id, is_default FROM watermarks WHERE name = 'Второй'")[0];
        self::assertSame(0, (int) $second['is_default']);
        $url = $this->base($workspace) . '/media/watermarks/' . $second['public_id'] . '/update';

        $this->post($url, ['name' => 'Второй', 'position' => 'tl', 'opacity' => '40', 'scale' => '25', 'margin' => '5', 'make_default' => '1']);

        $row = $this->db->select("SELECT * FROM watermarks WHERE name = 'Второй'")[0];
        self::assertSame(['tl', 40, 25, 5, 1], [$row['position'], (int) $row['opacity'], (int) $row['scale'], (int) $row['margin'], (int) $row['is_default']]);
        self::assertSame(1, (int) $this->db->select("SELECT COUNT(*) AS c FROM watermarks WHERE is_default = 1")[0]['c']);

        foreach ([
            ['position' => 'zz', 'opacity' => '40', 'scale' => '25', 'margin' => '5'],
            ['position' => 'tl', 'opacity' => '0', 'scale' => '25', 'margin' => '5'],
            ['position' => 'tl', 'opacity' => '40', 'scale' => '99', 'margin' => '5'],
            ['position' => 'tl', 'opacity' => '40', 'scale' => '25', 'margin' => '50'],
            ['position' => 'tl', 'opacity' => 'abc', 'scale' => '25', 'margin' => '5'],
        ] as $bad) {
            $this->post($url, ['name' => 'Хак'] + $bad);
        }
        $after = $this->db->select("SELECT * FROM watermarks WHERE public_id = ?", [$second['public_id']])[0];
        self::assertSame('Второй', $after['name'], 'invalid settings change nothing');
        self::assertSame('tl', $after['position']);
    }

    public function testDeletingTheDefaultPassesTheRoleOn(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->uploadLogo($workspace, $this->logoPng(), 'Первый');
        $this->uploadLogo($workspace, MediaFixtures::png(50, 50), 'Второй');
        $first = $this->db->select("SELECT public_id, storage_key FROM watermarks WHERE name = 'Первый'")[0];

        $this->post($this->base($workspace) . '/media/watermarks/' . $first['public_id'] . '/delete');

        self::assertSame(1, (int) $this->db->select("SELECT is_default FROM watermarks WHERE name = 'Второй'")[0]['is_default']);
        self::assertArrayNotHasKey((string) $first['storage_key'], $this->storage->objects);
        self::assertContains('media.watermark_deleted', $this->auditActions($workspace));
    }

    public function testLimitOfWatermarksPerWorkspace(): void
    {
        [, $workspace] = $this->ownerSession();
        for ($i = 0; $i < WatermarkService::MAX_PER_WORKSPACE + 2; ++$i) {
            $this->uploadLogo($workspace, MediaFixtures::png(20 + $i, 20), 'W' . $i);
        }

        self::assertSame(WatermarkService::MAX_PER_WORKSPACE, (int) $this->db->select('SELECT COUNT(*) AS c FROM watermarks')[0]['c']);
    }

    public function testPreviewIsAPngAndFollowsTheQuery(): void
    {
        [, $workspace] = $this->ownerSession();
        $this->uploadLogo($workspace, $this->logoPng());
        $id = (string) $this->db->select('SELECT public_id FROM watermarks')[0]['public_id'];
        $base = $this->base($workspace) . '/media/watermarks/' . $id;

        $default = $this->get($base . '/preview');
        $moved = $this->get($base . '/preview?position=tl&opacity=100&scale=40&margin=0');
        $junk = $this->get($base . '/preview?position=evil&opacity=-5&scale=zzz&margin=999');

        foreach ([$default, $moved, $junk] as $response) {
            self::assertSame(200, $response->status);
            self::assertSame('image/png', $response->header('Content-Type'));
            self::assertStringStartsWith("\x89PNG", $response->body);
        }
        self::assertNotSame($default->body, $moved->body);
        $logo = $this->get($base . '/logo');
        self::assertSame('image/png', $logo->header('Content-Type'));
        self::assertSame(200, $logo->status);
    }

    public function testWatermarkedVariantDiffersFromThePlainOneAndFollowsSettings(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(400, 200, 'black'), 'dark.jpg');
        $this->uploadLogo($workspace, $this->logoPng());
        $id = (string) $this->db->select('SELECT public_id FROM watermarks')[0]['public_id'];

        $plain = $this->get('/media/' . $media->publicId . '/original');
        $stamped = $this->get('/media/' . $media->publicId . '/original-wm');
        self::assertSame(200, $stamped->status);

        $pixel = static function (string $bytes, int $x, int $y): float {
            $image = new \Imagick();
            $image->readImageBlob($bytes);
            $color = $image->getImagePixelColor($x, $y)->getColor();

            return ($color['r'] + $color['g'] + $color['b']) / 3;
        };
        $stampedBytes = $this->body($stamped);
        $plainBytes = $this->body($plain);
        // Bottom-right area (default position) is white-ish with the logo, the top-left stays black.
        self::assertGreaterThan(100, $pixel($stampedBytes, 360, 180));
        self::assertLessThan(10, $pixel($stampedBytes, 5, 5));
        self::assertLessThan(10, $pixel($plainBytes, 360, 180));

        // Move the watermark to the top-left: a new rendition is made, the old one is not served.
        $this->post($this->base($workspace) . '/media/watermarks/' . $id . '/update', ['name' => 'Лого', 'position' => 'tl', 'opacity' => '100', 'scale' => '30', 'margin' => '3']);
        $moved = $this->get('/media/' . $media->publicId . '/original-wm');
        $movedBytes = $this->body($moved);
        self::assertGreaterThan(100, $pixel($movedBytes, 20, 15));
        self::assertLessThan(10, $pixel($movedBytes, 380, 190));
        self::assertCount(2, json_decode((string) $this->db->select('SELECT variants_json FROM media')[0]['variants_json'], true));
    }

    public function testWatermarkedVariantIsAlsoCropped(): void
    {
        [, $workspace] = $this->ownerSession();
        $media = $this->uploadOk($workspace, MediaFixtures::jpeg(800, 400), 'wide.jpg');
        $this->uploadLogo($workspace, $this->logoPng());

        $response = $this->get('/media/' . $media->publicId . '/9x16-wm');

        $image = new \Imagick();
        $image->readImageBlob($this->body($response));
        self::assertEqualsWithDelta(225, $image->getImageWidth(), 1);
        self::assertSame(400, $image->getImageHeight());
    }

    public function testWatermarksOfAnotherWorkspaceAreNotReachable(): void
    {
        [, $first] = $this->ownerSession();
        $this->uploadLogo($first, $this->logoPng());
        $id = (string) $this->db->select('SELECT public_id FROM watermarks')[0]['public_id'];
        [$stranger, $second] = $this->ownerWithWorkspace('stranger@example.com', 'Чужой');
        $this->actAs($stranger);

        foreach (['/preview', '/logo'] as $suffix) {
            self::assertSame(404, $this->get($this->base($second) . '/media/watermarks/' . $id . $suffix)->status);
            self::assertSame(404, $this->get($this->base($first) . '/media/watermarks/' . $id . $suffix)->status);
        }
        self::assertSame(404, $this->post($this->base($second) . '/media/watermarks/' . $id . '/delete')->status);
        self::assertSame(404, $this->post($this->base($second) . '/media/watermarks/' . $id . '/update', ['name' => 'x', 'position' => 'tl', 'opacity' => '50', 'scale' => '20', 'margin' => '3'])->status);
        self::assertSame(1, (int) $this->db->select('SELECT COUNT(*) AS c FROM watermarks')[0]['c']);
    }

    public function testAnotherWorkspaceDoesNotUseOurWatermarkForItsVariants(): void
    {
        [, $first] = $this->ownerSession();
        $this->uploadLogo($first, $this->logoPng());
        [$other, $second] = $this->ownerWithWorkspace('other@example.com', 'Другой');
        $this->actAs($other);
        $media = $this->uploadOk($second, MediaFixtures::jpeg(), 'a.jpg');

        self::assertSame(404, $this->get('/media/' . $media->publicId . '/original-wm')->status, 'no watermark in this workspace');
    }

    public function testEditorsMayManageWatermarksButAuthorsMayNot(): void
    {
        [, $workspace] = $this->ownerWithWorkspace();
        $this->actAsMember($workspace, 'editor@example.com', \App\Domain\Workspace\Role::Editor);
        self::assertSame(200, $this->get($this->base($workspace) . '/media/watermarks')->status);
        $this->actAsMember($workspace, 'author@example.com', \App\Domain\Workspace\Role::Author);
        self::assertSame(403, $this->get($this->base($workspace) . '/media/watermarks')->status);
        self::assertSame(403, $this->uploadLogo($workspace, $this->logoPng())->status);
    }
}
