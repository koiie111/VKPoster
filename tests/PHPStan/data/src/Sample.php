<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Data;

/**
 * Has a description, so it passes.
 */
final class Documented
{
}

final class Undocumented
{
}

/**
 * @internal
 */
final class OnlyTags
{
}

interface NoDocInterface
{
}

$anonymous = new class () {
};
