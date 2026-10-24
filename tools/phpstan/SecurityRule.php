<?php

declare(strict_types=1);

namespace App\Tools\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** Reject execution/deserialization primitives that bypass the application's typed boundaries.
 * @implements Rule<Node\Expr>
 */
final class SecurityRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!str_contains(str_replace('\\', '/', $scope->getFile()), '/src/')) {
            return [];
        }
        if ($node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\ShellExec
            || ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name
                && in_array(strtolower($node->name->toString()), ['exec', 'system', 'passthru', 'shell_exec', 'unserialize'], true))) {
            return [RuleErrorBuilder::message('Unsafe execution/deserialization primitive: use a typed, reviewed boundary.')
                ->identifier('app.securityPrimitive')->build()];
        }

        return [];
    }
}
