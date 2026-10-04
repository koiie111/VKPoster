<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media;

use App\Domain\Audit\AuditActions;
use App\Domain\Media\Media;
use App\Domain\Media\MediaKind;
use App\Domain\Media\MediaLimits;
use App\Domain\Media\MediaPresenter;
use App\Domain\Media\MediaUrls;
use App\Domain\Media\PlatformRequirements;
use App\Domain\Media\VariantSpec;
use App\Kernel\Security\Crypto;
use App\Kernel\Security\Signer;
use App\Tests\Support\FakeClock;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Small pure classes of the media module: platform rules, presentation, variant slugs, URLs.
 */
#[CoversClass(PlatformRequirements::class)]
#[CoversClass(MediaPresenter::class)]
#[CoversClass(VariantSpec::class)]
#[CoversClass(MediaUrls::class)]
#[CoversClass(Media::class)]
#[CoversClass(MediaKind::class)]
final class MediaValueObjectsTest extends TestCase
{
    private function media(MediaKind $kind = MediaKind::Image, int $size = 1000, ?int $w = 1000, ?int $h = 1000, ?int $durationMs = null, ?string $codec = null): Media
    {
        return new Media(1, '01JABCDEFGHJKMNPQRSTVWXYZ0', 1, 1, null, $kind, 'файл.jpg', 'ws/1/2026/10/x.jpg', 'ws/1/2026/10/x_thumb.webp', 'image/jpeg', $size, $w, $h, $durationMs, $codec, false, str_repeat('a', 64), [], new \DateTimeImmutable('2026-10-04'));
    }

    private function requirements(): PlatformRequirements
    {
        return new PlatformRequirements(MediaLimits::fromConfig(TestEnv::config()));
    }

    public function testAnOrdinaryPhotoFitsEveryNetwork(): void
    {
        foreach (['telegram', 'vk', 'max', 'instagram'] as $platform) {
            self::assertSame([], $this->requirements()->problems($this->media(), $platform), $platform);
        }
        self::assertSame([], $this->requirements()->problems($this->media(), 'unknown-network'));
    }

    public function testHeavyAndOddShapedPhotos(): void
    {
        $heavy = $this->requirements()->problems($this->media(size: 12 * 1024 * 1024), 'telegram');
        self::assertSame(['Telegram: фото больше 10 МБ.'], $heavy);

        $panorama = $this->requirements()->problems($this->media(w: 6000, h: 5000), 'telegram');
        self::assertSame(['Telegram: сумма сторон фото больше 10000 пикселей.'], $panorama);

        $strip = $this->requirements()->problems($this->media(w: 3000, h: 300), 'instagram');
        self::assertCount(1, $strip);
        self::assertStringContainsString('соотношение сторон', $strip[0]);
        self::assertSame([], $this->requirements()->problems($this->media(w: 1080, h: 1350), 'instagram'), '4:5 is allowed');
        self::assertSame([], $this->requirements()->problems($this->media(w: 1910, h: 1000), 'instagram'), '1.91:1 is allowed');
        self::assertNotSame([], $this->requirements()->problems($this->media(w: 1000, h: 1500), 'instagram'));
    }

    public function testVideoRules(): void
    {
        $long = $this->media(MediaKind::Video, 10 * 1024 * 1024, 1080, 1920, 120_000, 'h264');
        self::assertSame(['Instagram: видео длиннее 90 секунд.'], $this->requirements()->problems($long, 'instagram'));
        self::assertSame([], $this->requirements()->problems($long, 'telegram'));

        $heavy = $this->media(MediaKind::Video, 80 * 1024 * 1024, 1080, 1920, 10_000, 'h264');
        self::assertSame(['Telegram: видео больше 50 МБ.'], $this->requirements()->problems($heavy, 'telegram'));

        $odd = $this->media(MediaKind::Video, 1024, 1080, 1920, 10_000, 'prores');
        $problems = $this->requirements()->problems($odd, 'instagram');
        self::assertCount(1, $problems);
        self::assertStringContainsString('prores', $problems[0]);
    }

    public function testDocumentRules(): void
    {
        $pdf = $this->media(MediaKind::Document, 1000, null, null);

        self::assertSame(['Instagram не принимает документы.'], $this->requirements()->problems($pdf, 'instagram'));
        self::assertSame([], $this->requirements()->problems($pdf, 'telegram'));
        self::assertSame(['Telegram: документ больше 50 МБ.'], $this->requirements()->problems($this->media(MediaKind::Document, 60 * 1024 * 1024, null, null), 'telegram'));
    }

    public function testPresenterFormatsForPeople(): void
    {
        self::assertSame('512 Б', MediaPresenter::size(512));
        self::assertSame('1,5 КБ', MediaPresenter::size(1536));
        self::assertSame('120 КБ', MediaPresenter::size(120 * 1024));
        self::assertSame('2,5 МБ', MediaPresenter::size((int) (2.5 * 1048576)));
        self::assertSame('48 МБ', MediaPresenter::size(48 * 1048576));
        self::assertSame('1,2 ГБ', MediaPresenter::size((int) (1.2 * 1073741824)));
        self::assertSame('0:05', MediaPresenter::duration(5000));
        self::assertSame('1:05', MediaPresenter::duration(65_000));
        self::assertSame('1:01:01', MediaPresenter::duration(3_661_000));
    }

    public function testPresentedMediaHidesStorageDetails(): void
    {
        $urls = new MediaUrls(new Signer(new Crypto('k1', ['k1' => base64_encode(str_repeat('k', 32))]), new FakeClock()), TestEnv::config(), MediaLimits::fromConfig(TestEnv::config()));
        $row = (new MediaPresenter($urls))->present($this->media(MediaKind::Video, 2048, 1920, 1080, 61_000, 'h264'));

        self::assertSame('01JABCDEFGHJKMNPQRSTVWXYZ0', $row['id']);
        self::assertSame('1920×1080', $row['dimensions']);
        self::assertSame('1:01', $row['duration']);
        self::assertSame('/media/01JABCDEFGHJKMNPQRSTVWXYZ0/thumb', $row['thumb']);
        self::assertSame('Видео', $row['kind_label']);
        self::assertStringNotContainsString('ws/1', json_encode($row, JSON_THROW_ON_ERROR));
        $noThumb = new Media(1, 'ID', 1, null, null, MediaKind::Document, 'a.pdf', 'k', null, 'application/pdf', 1, null, null, null, null, false, 'h', [], new \DateTimeImmutable());
        self::assertNull((new MediaPresenter($urls))->present($noThumb)['thumb']);
        self::assertNull($noThumb->ratio());
        self::assertSame(1.0, $this->media()->ratio());
        self::assertTrue($this->media()->isImage());
        self::assertSame('Документ', MediaKind::Document->label());
    }

    public function testVariantSlugs(): void
    {
        self::assertSame('1x1', VariantSpec::fromSlug('1x1')?->slug());
        self::assertSame('4x5-wm', VariantSpec::fromSlug('4x5-wm')?->slug());
        self::assertTrue(VariantSpec::fromSlug('original-wm')?->watermark);
        self::assertSame(0.5625, VariantSpec::fromSlug('9x16')?->ratio());
        self::assertSame(1.91, VariantSpec::fromSlug('191x100')?->ratio());
        self::assertNull(VariantSpec::fromSlug('original')?->ratio());
        foreach (['', 'thumb', '2x3', '1x1-wm-wm', '../1x1', '1X1', '1x1 ', 'original-WM'] as $bad) {
            self::assertNull(VariantSpec::fromSlug($bad), $bad);
        }
        self::assertFalse((new VariantSpec())->transforms());
        self::assertTrue((new VariantSpec('1x1'))->transforms());
        self::assertTrue((new VariantSpec('original', true))->transforms());
        self::assertTrue((new VariantSpec('original', false, null, 2560, 1000))->transforms());
        $this->expectException(\InvalidArgumentException::class);
        new VariantSpec('3x1');
    }

    public function testSignedUrlsAreAbsoluteAndExpire(): void
    {
        $clock = new FakeClock('2026-10-04 12:00:00');
        $signer = new Signer(new Crypto('k1', ['k1' => base64_encode(str_repeat('k', 32))]), $clock);
        $urls = new MediaUrls($signer, TestEnv::config(['APP_URL' => 'https://ezposter.example/']), MediaLimits::fromConfig(TestEnv::config()));
        $media = $this->media();

        $url = $urls->signed($media, '1x1', 120);

        self::assertStringStartsWith('https://ezposter.example/media/01JABCDEFGHJKMNPQRSTVWXYZ0/1x1?expires=' . ($clock->now()->getTimestamp() + 120) . '&signature=', $url);
        self::assertTrue($signer->verifyUrl(substr($url, strlen('https://ezposter.example'))));
        self::assertSame('/media/01JABCDEFGHJKMNPQRSTVWXYZ0/original', $urls->path($media));
        $clock->advance(121);
        self::assertFalse($signer->verifyUrl(substr($url, strlen('https://ezposter.example'))));
        self::assertStringContainsString('expires=' . ($clock->now()->getTimestamp() + 3600), $urls->signed($media), 'default lifetime is one hour');
    }

    public function testJournalKnowsMediaEvents(): void
    {
        self::assertSame('Удалён файл из медиатеки', AuditActions::label('media.deleted'));
        self::assertSame('«a.jpg»', AuditActions::describe('media.deleted', ['name' => 'a.jpg']));
        self::assertSame('«Лого»', AuditActions::describe('media.watermark_created', ['name' => 'Лого']));
        self::assertArrayHasKey('media', AuditActions::groups());
    }
}
