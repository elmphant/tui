<?php

declare(strict_types=1);

namespace Elmphant\Tui\Stan;

use Elmphant\Tui\Rendering\Context\Drawable;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * @implements Rule<MethodCall>
 */
final class ChildIsDrawnThroughTheContextRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || $node->name->toLowerString() !== 'render') {
            return [];
        }

        $class = $scope->getClassReflection();

        if ($class === null || !$class->implementsInterface(Drawable::class)) {
            return [];
        }

        if (!(new ObjectType(Drawable::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s::render() calls render() on another widget. Draw it with $context->draw(), or $context->drawChild() for a child the keyboard can reach, so every child is drawn in one place.',
                $class->getNativeReflection()->getShortName(),
            ))->identifier('elmphant.drawing.direct')->build(),
        ];
    }
}
