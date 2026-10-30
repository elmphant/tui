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
 * Missing constructor arguments can silently reset fields while still passing type checks.
 *
 * @implements Rule<New_>
 */
final class CompleteRebuildRule implements Rule
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

        $missing = self::missingParameters($node, $class);

        if ($missing === []) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s() rebuilds %s without %s. An omitted parameter takes its constructor default, resetting the field. Pass every parameter, or use cloneWith().',
                $builder,
                $class->getNativeReflection()->getShortName(),
                implode(', ', $missing),
            ))
                ->identifier('elmphant.builder.partialRebuild')
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
    private static function missingParameters(New_ $node, ClassReflection $class): array
    {
        if (!$class->hasConstructor()) {
            return [];
        }

        $parameters = $class->getConstructor()->getOnlyVariant()->getParameters();
        $covered = [];
        $position = 0;

        foreach ($node->args as $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                return [];
            }

            if ($argument->name instanceof Identifier) {
                $covered[$argument->name->toString()] = true;

                continue;
            }

            if (isset($parameters[$position])) {
                $covered[$parameters[$position]->getName()] = true;
            }

            ++$position;
        }

        $missing = [];

        foreach ($parameters as $parameter) {
            if (!$parameter->isVariadic() && !isset($covered[$parameter->getName()])) {
                $missing[] = '$' . $parameter->getName();
            }
        }

        return $missing;
    }
}
