<?php

declare(strict_types=1);

namespace Elmphant\Tui\Stan;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Widget\Structure\Split;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bind Split so divider drags can write updated state back to the host.
 *
 * @implements Rule<InClassMethodNode>
 */
final class SplitIsBoundRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (!$class->isSubclassOf(App::class)) {
            return [];
        }

        $statements = $node->getOriginalNode()->stmts ?? [];
        $finder = new NodeFinder();
        $bound = [];

        foreach ($finder->find($statements, static fn (Node $each): bool => self::isCallOn($each, Component::class, 'bind')) as $bind) {
            $bound += self::idsUnder($bind);
        }

        $boundVariables = [];
        $assignedTo = [];

        foreach ($finder->findInstanceOf($statements, Variable::class) as $variable) {
            if (isset($bound[spl_object_id($variable)]) && is_string($variable->name)) {
                $boundVariables[$variable->name] = true;
            }
        }

        foreach ($finder->findInstanceOf($statements, Assign::class) as $assign) {
            if ($assign->var instanceof Variable && is_string($assign->var->name)) {
                $assignedTo += array_fill_keys(array_keys(self::idsUnder($assign->expr)), $assign->var->name);
            }
        }

        $errors = [];

        foreach ($finder->find($statements, static fn (Node $each): bool => self::isCallOn($each, Split::class, 'row') || self::isCallOn($each, Split::class, 'column')) as $split) {
            $id = spl_object_id($split);

            if (isset($bound[$id]) || isset($boundVariables[$assignedTo[$id] ?? ''])) {
                continue;
            }

            \assert($split instanceof StaticCall && $split->name instanceof Identifier);
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Split::%s() in %s::%s() is not inside Component::bind(), so a drag on its divider is dropped. Bind it and keep state() in the write-back, or use Layout for panes that never move.',
                $split->name->name,
                $class->getNativeReflection()->getShortName(),
                $node->getMethodReflection()->getName(),
            ))->identifier('elmphant.split.unbound')->line($split->getStartLine())->build();
        }

        return $errors;
    }

    /**
     * @return array<int, true>
     */
    private static function idsUnder(Node $root): array
    {
        $ids = [];

        foreach ((new NodeFinder())->find($root, static fn (Node $each): bool => true) as $each) {
            $ids[spl_object_id($each)] = true;
        }

        return $ids;
    }

    private static function isCallOn(Node $node, string $class, string $method): bool
    {
        return $node instanceof StaticCall
            && $node->class instanceof Name
            && $node->class->toString() === $class
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === $method;
    }
}
