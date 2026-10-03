<?php

declare(strict_types=1);

namespace App\Tools\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Every class, interface, trait and enum under `src/` must carry a docblock that says what it is for
 * (a text line, not just `@tags`). Enforces ENGINEERING_RULES §7.2.
 *
 * @implements Rule<Node\Stmt\ClassLike>
 */
final class RequireClassDocblockRule implements Rule
{
    public function getNodeType(): string
    {
        return Node\Stmt\ClassLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!($node instanceof Class_ || $node instanceof Interface_ || $node instanceof Trait_ || $node instanceof Enum_)) {
            return [];
        }
        if (($node instanceof Class_ && $node->isAnonymous()) || $node->name === null || !str_contains(str_replace('\\', '/', $scope->getFile()), '/src/')) {
            return [];
        }
        $doc = $node->getDocComment();
        $text = $doc === null ? '' : $this->descriptionOf($doc->getText());
        if ($text !== '') {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('%s %s needs a docblock describing its purpose.', $this->kind($node), $node->name->toString()))
                ->identifier('app.classDocblockMissing')
                ->build(),
        ];
    }

    private function descriptionOf(string $docblock): string
    {
        $lines = [];
        foreach (explode("\n", str_replace("\r", '', $docblock)) as $line) {
            $line = trim($line, " \t*/");
            if ($line !== '' && !str_starts_with($line, '@')) {
                $lines[] = $line;
            }
        }

        return implode(' ', $lines);
    }

    private function kind(Node $node): string
    {
        return match (true) {
            $node instanceof Interface_ => 'Interface',
            $node instanceof Trait_ => 'Trait',
            $node instanceof Enum_ => 'Enum',
            default => 'Class',
        };
    }
}
