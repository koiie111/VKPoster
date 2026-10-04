<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan;

use App\Tools\PHPStan\WorkspaceScopedRepositoryRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * Methods of workspace-scoped repositories must demand a `WorkspaceContext` first.
 *
 * @extends RuleTestCase<WorkspaceScopedRepositoryRule>
 */
final class WorkspaceScopedRepositoryRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new WorkspaceScopedRepositoryRule();
    }

    public function testRule(): void
    {
        $class = 'App\Tests\PHPStan\Data\BadRepository';
        $this->analyse([__DIR__ . '/../../PHPStan/data/src/ScopedSample.php'], [
            [sprintf('Method %s::all() must take a WorkspaceContext as its first parameter.', $class), 36],
            [sprintf('Method %s::none() must take a WorkspaceContext as its first parameter.', $class), 41],
            [sprintf('Method %s::late() must take a WorkspaceContext as its first parameter.', $class), 46],
        ]);
    }
}
