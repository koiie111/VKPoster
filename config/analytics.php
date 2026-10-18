<?php

declare(strict_types=1);

use App\Kernel\Env;

return static fn (Env $env): array => [
    'collection_enabled' => $env->bool('ANALYTICS_COLLECTION_ENABLED', false),
    'pdf_url' => $env->string('ANALYTICS_PDF_URL', 'http://analytics-pdf:3000'),
];
