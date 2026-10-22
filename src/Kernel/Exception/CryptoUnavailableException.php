<?php

declare(strict_types=1);

namespace App\Kernel\Exception;

/** Isolated backend failed before a caller can use credentials; carries no request/provider details. */
final class CryptoUnavailableException extends CryptoException
{
}
