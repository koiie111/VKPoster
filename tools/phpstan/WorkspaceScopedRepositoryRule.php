<?php

declare(strict_types=1);

namespace App\Tools\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Tenant isolation at the type level: every public method of a class extending `WorkspaceScopedRepository`
 * must take a `WorkspaceContext` as its first parameter, so data of a workspace cannot be reached without a
 * resolved membership. Constructors and static helpers are exempt.
 *
 * @implements Rule<ClassMethod>
 */
final class WorkspaceScopedRepositoryRule implements Rule
{
    private const BASE = 'App\Domain\Workspace\WorkspaceScopedRepository';
    private const CONTEXT = 'App\Domain\Workspace\WorkspaceContext';

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->isPublic() || $node->isStatic() || $node->name->toString() === '__construct') {
            return [];
        }
        $class = $scope->getClassReflection();
        if ($class === null || $class->getName() === self::BASE || !$class->isSubclassOf(self::BASE)) {
            return [];
        }
        $first = $node->params[0] ?? null;
        $type = $first?->type;
        if ($type instanceof Node\Name && $scope->resolveName($type) === self::CONTEXT) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('Method %s::%s() must take a WorkspaceContext as its first parameter.', $class->getName(), $node->name->toString()))
                ->identifier('app.workspaceContextMissing')
                ->build(),
        ];
    }
}
