<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan;

use App\Tools\PHPStan\SecurityRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** Security guard rejects dangerous language primitives even when syntax/type checks pass.
 * @extends RuleTestCase<SecurityRule>
 */
final class SecurityRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new SecurityRule();
    }

    public function testRule(): void
    {
        $message = 'Unsafe execution/deserialization primitive: use a typed, reviewed boundary.';
        $this->analyse([__DIR__ . '/../../PHPStan/data/src/Unsafe.php'], [[$message, 7], [$message, 8], [$message, 9]]);
    }
}
