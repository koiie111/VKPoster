<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Kernel\Console\Command;
use App\Kernel\Console\Output;

/**
 * `key:generate`: print a fresh base64 32-byte key for `APP_KEY` (does not touch any file).
 */
final class KeyGenerateCommand implements Command
{
    public function name(): string
    {
        return 'key:generate';
    }

    public function description(): string
    {
        return 'Print a new random APP_KEY value';
    }

    public function run(array $args, Output $out): int
    {
        $out->line(base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

        return 0;
    }
}
