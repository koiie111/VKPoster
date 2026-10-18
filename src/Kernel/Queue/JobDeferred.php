<?php

declare(strict_types=1);

namespace App\Kernel\Queue;

/** A pre-dispatch capacity gate, not an attempted provider operation. */
final class JobDeferred extends \RuntimeException
{
    public function __construct(public readonly int $delaySeconds)
    {
        parent::__construct('Job deferred until capacity becomes available.');
    }
}
