<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsPdfKey;
use App\Kernel\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

// Runs only in the trusted PHP init container, never in the Chromium image. No stdout secrets.
try {
    if (!in_array(Env::fromProcess()->string('APP_ENV'), ['local', 'testing'], true)) {
        throw new RuntimeException('Local PDF key generation forbidden in production.');
    }
    $key = AnalyticsPdfKey::derive(Env::fromProcess()->required('APP_KEY'));
    $path = '/run/analytics-pdf-key/key';
    if (file_put_contents($path . '.tmp', $key, LOCK_EX) === false
        || !chmod($path . '.tmp', 0444) || !rename($path . '.tmp', $path)) {
        throw new RuntimeException();
    }
} catch (Throwable) {
    fwrite(STDERR, "PDF key initialization failed.\n");
    exit(1);
}
