<?php

declare(strict_types=1);

namespace Elmphant\Tui\Stan;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A positional rebuild still carries every field today, so no test can fail on
 * it; it fails the day a constructor parameter moves.
 *
 * @implements Rule<New_>
 */
final class NamedRebuildRule implements Rule
{
    public function getNodeType(): string
    {
        return New_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $builder = $scope->getFunctionName();
        $class = $scope->getClassReflection();

        if ($builder === null || $class === null || !str_starts_with($builder, 'with')) {
            return [];
        }

        if (!self::rebuildsItsOwnClass($node, $class->getName())) {
            return [];
        }

        $positional = self::positionalParameters($node, $class);

        if ($positional === []) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s() rebuilds %s passing %s by position. Reordering the constructor would silently reassign them. Name every parameter, or use cloneWith().',
                $builder,
                $class->getNativeReflection()->getShortName(),
                implode(', ', $positional),
            ))
                ->identifier('elmphant.builder.positionalRebuild')
                ->build(),
        ];
    }

    private static function rebuildsItsOwnClass(New_ $node, string $enclosingClass): bool
    {
        if (!$node->class instanceof Name) {
            return false;
        }

        $name = $node->class->toString();

        return in_array(strtolower($name), ['self', 'static'], true) || ltrim($name, '\\') === $enclosingClass;
    }

    /**
     * @return list<string>
     */
    private static function positionalParameters(New_ $node, ClassReflection $class): array
    {
        if (!$class->hasConstructor()) {
            return [];
        }

        $parameters = $class->getConstructor()->getOnlyVariant()->getParameters();
        $positional = [];
        $position = 0;

        foreach ($node->args as $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                return [];
            }

            if ($argument->name instanceof Identifier) {
                continue;
            }

            $parameter = $parameters[$position] ?? null;
            ++$position;

            if ($parameter !== null && !$parameter->isVariadic()) {
                $positional[] = '$' . $parameter->getName();
            }
        }

        return $positional;
    }
}
