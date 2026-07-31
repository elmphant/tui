<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<MethodCall>
 */
final class CloneWithEncapsulationRule implements Rule
{
    /**
     * @param list<class-string> $allowList
     */
    public function __construct(private array $allowList = [])
    {
    }

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier || $node->name->toString() !== 'cloneWith') {
            return [];
        }

        if ($node->var instanceof Variable && $node->var->name === 'this') {
            return [];
        }

        $enclosingClass = $scope->getClassReflection();
        if ($enclosingClass !== null) {
            foreach ($scope->getType($node->var)->getObjectClassNames() as $receiverClass) {
                if ($receiverClass === $enclosingClass->getName()) {
                    return [];
                }
            }

            if (in_array($enclosingClass->getName(), $this->allowList, true)) {
                return [];
            }
        }

        return [
            RuleErrorBuilder::message(
                'cloneWith() on a foreign object is an internal keyed write; use the object\'s typed withX() method.',
            )
                ->identifier('elmphant.cloneWith.foreign')
                ->build(),
        ];
    }
}
