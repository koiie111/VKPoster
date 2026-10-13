<?php

declare(strict_types=1);

// Run once with old app/worker/reader stopped. No data reset, secrets or content output.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = \App\Kernel\Application::create(dirname(__DIR__, 2));
$db = $app->container()->get(\App\Kernel\Database\Connection::class);
(new \App\Domain\Content\Providers\Stage22LegacyUpgrade())->up($db);
$db->execute('UPDATE migrations SET migration=? WHERE migration=?', ['2026_10_05_000023_create_source_selection', '2026_10_05_000022_create_source_selection']);
$db->execute('UPDATE migrations SET migration=? WHERE migration=?', ['2026_10_05_000021_create_sources', '2026_10_05_000013_create_sources']);
$db->execute('UPDATE migrations SET migration=? WHERE migration=?', ['2026_10_06_000026_create_source_video_generations', '2026_10_06_000025_create_source_video_generations']);
$db->execute('UPDATE migrations SET migration=? WHERE migration=?', ['2026_10_05_000022_create_source_incoming', '2026_10_05_000021_create_source_incoming']);
$db->execute('UPDATE migrations SET migration=? WHERE migration=?', ['2026_10_06_000025_create_source_image_processing', '2026_10_06_000024_create_source_image_processing']);
$db->execute('UPDATE migrations SET migration=? WHERE migration=?', ['2026_10_05_000024_create_source_text_processings', '2026_10_05_000023_create_source_text_processings']);
$db->execute('UPDATE migrations SET migration=? WHERE migration=?', ['2026_10_06_000029_create_content_post_origins', '2026_10_06_000028_create_content_post_origins']);
$db->execute('UPDATE migrations SET migration=? WHERE migration=?', ['2026_10_06_000027_create_content_discovery', '2026_10_06_000026_create_content_discovery']);
$db->execute('UPDATE migrations SET migration=? WHERE migration=?', ['2026_10_06_000028_create_semantic_selection', '2026_10_06_000027_create_semantic_selection']);
$db->execute('DELETE FROM migrations WHERE migration=?', ['2026_10_06_000029_add_content_provider_metadata']);
$db->execute('DELETE FROM migrations WHERE migration=?', ['2026_10_07_000032_review_fixes']);
$db->execute('DELETE FROM migrations WHERE migration=?', ['2026_10_07_000033_independent_review']);
echo 'Stage 22 development schema and migration journal upgraded; history retained.', PHP_EOL;
