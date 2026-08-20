<?php

declare(strict_types=1);

namespace Elmphant\Tui\Stan;

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
 * Where the keyboard is belongs to the tree, and a widget's own flag is a message
 * behind by the time a frame is drawn — the tree applies a focus move after
 * update() returns and draws before the next message arrives. So a widget asks
 * the context while drawing, never itself.
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

        $read = (new NodeFinder())->findFirst(
            $node->getOriginalNode()->stmts ?? [],
            static fn (Node $each): bool => $each instanceof PropertyFetch
                && $each->var instanceof Variable
                && $each->var->name === 'this'
                && $each->name instanceof Node\Identifier
                && $each->name->name === 'focused',
        );

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
}
