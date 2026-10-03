<?php

declare(strict_types=1);

namespace App\Kernel\Exception;

use RuntimeException;

/**
 * Raised on malformed keys, unknown key ids, tampered or undecryptable ciphertext.
 */
final class CryptoException extends RuntimeException
{
}
