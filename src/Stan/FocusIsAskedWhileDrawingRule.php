<?php

declare(strict_types=1);

namespace Elmphant\Tui\Stan;

use Closure;
use Elmphant\Tui\Widget\Focusable;
use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Read focus from the render context: stored widget flags may lag behind focus changes applied after update().
 *
 * @implements Rule<InClassMethodNode>
 */
final class FocusIsAskedWhileDrawingRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->getMethodReflection()->getName() !== 'render') {
            return [];
        }

        $class = $node->getClassReflection();

        if (!$class->implementsInterface(Focusable::class)) {
            return [];
        }

        $statements = $node->getOriginalNode()->stmts ?? [];
        $read = (new NodeFinder())->findFirst($statements, self::isFocusFlagRead());

        if ($read === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s::render() draws from its own focus flag. Where the keyboard is belongs to the tree, so ask $context through drawsFocused() — a flag is a message behind by the time a frame is drawn.',
                $class->getDisplayName(),
            ))->identifier('elmphant.focus.askedWhileDrawing')->line($read->getStartLine())->build(),
        ];
    }

    private static function isFocusFlagRead(): Closure
    {
        return static fn (Node $each): bool => $each instanceof PropertyFetch
            && $each->var instanceof Variable
            && $each->var->name === 'this'
            && $each->name instanceof Node\Identifier
            && $each->name->name === 'focused';
    }
}
