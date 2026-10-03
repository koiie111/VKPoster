<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan;

use App\Tools\PHPStan\RequireClassDocblockRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * The docblock rule flags undocumented classes under `src/` and accepts documented ones.
 *
 * @extends RuleTestCase<RequireClassDocblockRule>
 */
final class RequireClassDocblockRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new RequireClassDocblockRule();
    }

    public function testRule(): void
    {
        $this->analyse([__DIR__ . '/../../PHPStan/data/src/Sample.php'], [
            ['Class Undocumented needs a docblock describing its purpose.', 14],
            ['Class OnlyTags needs a docblock describing its purpose.', 21],
            ['Interface NoDocInterface needs a docblock describing its purpose.', 25],
        ]);
    }

    public function testFilesOutsideSrcAreIgnored(): void
    {
        $this->analyse([__DIR__ . '/../../PHPStan/data/Outside.php'], []);
    }
}
