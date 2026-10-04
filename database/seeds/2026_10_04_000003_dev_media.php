<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Seeder;

/**
 * Demo library for screenshots and manual checks (idempotent, local disk only): a few generated pictures in two
 * folders, a PDF and a watermark logo for the demo workspace. Files are written straight into `storage/media`.
 */
return new class () implements Seeder {
    public function run(Connection $db): void
    {
        $workspace = $db->select('SELECT id FROM workspaces WHERE name = ? LIMIT 1', ['Кофейня «Зерно»']);
        if ($workspace === [] || $db->select('SELECT id FROM media WHERE workspace_id = ? LIMIT 1', [$workspace[0]['id']]) !== []) {
            return;
        }
        // Without an explicit font ImageMagick may crash in this image (no default font configured); DejaVu has Cyrillic.
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
        if (!class_exists(Imagick::class) || !is_file($font)) {
            return;
        }
        $workspaceId = (int) $workspace[0]['id'];
        $owner = $db->select('SELECT owner_id FROM workspaces WHERE id = ?', [$workspaceId]);
        $uploader = (int) $owner[0]['owner_id'];
        $root = dirname(__DIR__, 2) . '/storage/media';
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $folders = [];
        foreach (['Акции', 'Меню'] as $name) {
            $folders[$name] = (int) $db->table('media_folders')->insert([
                'public_id' => (string) new Symfony\Component\Uid\Ulid(),
                'workspace_id' => $workspaceId,
                'name' => $name,
                'created_at' => $now,
            ]);
        }

        $write = static function (string $key, string $bytes) use ($root): void {
            $path = $root . '/' . $key;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            file_put_contents($path, $bytes);
            chmod($path, 0600);
        };
        $picture = static function (int $w, int $h, string $from, string $to, string $label) use ($font): Imagick {
            $image = new Imagick();
            $image->newPseudoImage($w, $h, 'gradient:' . $from . '-' . $to);
            $draw = new ImagickDraw();
            $draw->setFont($font);
            $draw->setFillColor(new ImagickPixel('white'));
            $draw->setFontSize((int) ($h / 9));
            $draw->setGravity(Imagick::GRAVITY_CENTER);
            $image->annotateImage($draw, 0, 0, 0, $label);

            return $image;
        };

        $items = [
            ['Латте.jpg', 1200, 900, '#f59e0b', '#7c2d12', 'Латте', 'Меню'],
            ['Капучино.jpg', 1080, 1350, '#fb7185', '#7f1d1d', 'Капучино', 'Меню'],
            ['Круассан.jpg', 1200, 800, '#fde68a', '#b45309', 'Круассан', 'Меню'],
            ['Баннер акции.jpg', 1600, 840, '#6366f1', '#1e1b4b', 'Скидка 20%', 'Акции'],
            ['Сторис.jpg', 1080, 1920, '#14b8a6', '#134e4a', 'Новинка', 'Акции'],
            ['Витрина.jpg', 1000, 1000, '#94a3b8', '#1e293b', 'Витрина', null],
        ];
        foreach ($items as $i => [$name, $w, $h, $from, $to, $label, $folder]) {
            $image = $picture($w, $h, $from, $to, $label);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(85);
            $stored = $image->getImageBlob();
            $thumb = clone $image;
            $thumb->thumbnailImage(320, 320, true);
            $thumb->setImageFormat('webp');
            $base = sprintf('ws/%d/%s/%s', $workspaceId, date('Y/m'), strtolower((string) new Symfony\Component\Uid\Ulid()));
            $write($base . '.jpg', $stored);
            $write($base . '_thumb.webp', $thumb->getImageBlob());
            $db->table('media')->insert([
                'public_id' => (string) new Symfony\Component\Uid\Ulid(),
                'workspace_id' => $workspaceId,
                'uploader_id' => $uploader,
                'folder_id' => $folder === null ? null : $folders[$folder],
                'kind' => 'image',
                'original_name' => $name,
                'storage_key' => $base . '.jpg',
                'thumb_key' => $base . '_thumb.webp',
                'mime' => 'image/jpeg',
                'size' => strlen($stored),
                'width' => $w,
                'height' => $h,
                'animated' => 0,
                'sha256' => hash('sha256', 'seed-media-' . $i),
                'created_at' => $now,
            ]);
        }

        $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
        $base = sprintf('ws/%d/%s/%s', $workspaceId, date('Y/m'), strtolower((string) new Symfony\Component\Uid\Ulid()));
        $write($base . '.pdf', $pdf);
        $db->table('media')->insert([
            'public_id' => (string) new Symfony\Component\Uid\Ulid(),
            'workspace_id' => $workspaceId,
            'uploader_id' => $uploader,
            'kind' => 'document',
            'original_name' => 'Прайс.pdf',
            'storage_key' => $base . '.pdf',
            'mime' => 'application/pdf',
            'size' => strlen($pdf),
            'animated' => 0,
            'sha256' => hash('sha256', 'seed-media-pdf'),
            'created_at' => $now,
        ]);

        $logo = new Imagick();
        $logo->newImage(320, 120, new ImagickPixel('transparent'));
        $draw = new ImagickDraw();
        $draw->setFont($font);
        $draw->setFillColor(new ImagickPixel('white'));
        $draw->setFontSize(64);
        $draw->setGravity(Imagick::GRAVITY_CENTER);
        $logo->annotateImage($draw, 0, 0, 0, 'ЗЕРНО');
        $logo->setImageFormat('png');
        $key = sprintf('ws/%d/watermarks/%s.png', $workspaceId, strtolower((string) new Symfony\Component\Uid\Ulid()));
        $write($key, $logo->getImageBlob());
        $db->table('watermarks')->insert([
            'public_id' => (string) new Symfony\Component\Uid\Ulid(),
            'workspace_id' => $workspaceId,
            'name' => 'Логотип «Зерно»',
            'storage_key' => $key,
            'width' => 320,
            'height' => 120,
            'is_default' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
