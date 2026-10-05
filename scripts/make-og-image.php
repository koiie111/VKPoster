<?php

declare(strict_types=1);

// Draws public/assets/images/og.png (1200x630, the picture shown when a link to the site is shared).
// Run in the app container: `docker compose exec app php scripts/make-og-image.php [name]`. Needs GD with FreeType and DejaVu Sans.
$name = $argv[1] ?? 'ezposter';
$bold = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$regular = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

$image = imagecreatetruecolor(1200, 630);
// Vertical gradient in the brand indigo.
for ($y = 0; $y < 630; ++$y) {
    $t = $y / 629;
    $color = imagecolorallocate($image, (int) (79 - 20 * $t), (int) (70 - 15 * $t), (int) (229 - 50 * $t));
    imageline($image, 0, $y, 1199, $y, $color);
}
$white = imagecolorallocate($image, 255, 255, 255);
$soft = imagecolorallocate($image, 224, 226, 255);
// A little calendar card on the right.
$card = imagecolorallocate($image, 255, 255, 255);
imagefilledrectangle($image, 730, 150, 1110, 480, $card);
$ink = imagecolorallocate($image, 30, 33, 60);
$line = imagecolorallocate($image, 226, 228, 240);
imagettftext($image, 20, 0, 760, 200, $ink, $bold, 'Неделя');
$chips = [[240, 'Telegram', [38, 150, 210]], [310, 'ВКонтакте', [0, 119, 255]], [380, 'MAX', [120, 70, 230]]];
foreach ($chips as [$y, $label, $rgb]) {
    imagefilledrectangle($image, 760, $y, 1080, $y + 50, imagecolorallocate($image, 240, 242, 252));
    imagefilledellipse($image, 785, $y + 25, 16, 16, imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]));
    imagettftext($image, 18, 0, 810, $y + 33, $ink, $regular, $label);
}
imageline($image, 760, 218, 1080, 218, $line);
imagettftext($image, 72, 0, 80, 270, $white, $bold, $name);
imagettftext($image, 34, 0, 80, 360, $soft, $regular, 'Пишите посты заранее.');
imagettftext($image, 34, 0, 80, 415, $soft, $regular, 'Публикуем вовремя.');
imagettftext($image, 24, 0, 80, 560, $soft, $regular, 'Telegram · ВКонтакте · MAX');

$target = dirname(__DIR__) . '/public/assets/images/og.png';
imagepng($image, $target, 9);
echo "Wrote $target\n";
