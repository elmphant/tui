<?php

declare(strict_types=1);

namespace Elmphant\Tui\Stan;

use Elmphant\Tui\Application\App;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ExtendedPropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;

/**
 * App::bind('name') and App::mount('name') read the property they name. So a property an App only binds or
 * mounts is not reported as never read.
 */
final class BoundPropertiesAreRead implements ReadWritePropertiesExtension
{
    private const READING_METHODS = ['bind', 'mount'];

    /**
     * @var array<string, list<string>>
     */
    private array $namesReadByClass = [];

    public function __construct(private readonly Parser $parser)
    {
    }

    public function isAlwaysRead(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        $class = $property->getDeclaringClass();
        $file = $class->getFileName();

        if ($file === null || !$class->isSubclassOf(App::class)) {
            return false;
        }

        $this->namesReadByClass[$class->getName()] ??= $this->namesReadIn($file, $class->getName());

        return \in_array($propertyName, $this->namesReadByClass[$class->getName()], true);
    }

    public function isAlwaysWritten(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return false;
    }

    public function isInitialized(ExtendedPropertyReflection $property, string $propertyName): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    private function namesReadIn(string $file, string $className): array
    {
        $finder = new NodeFinder();
        $declaration = $finder->findFirst(
            $this->parser->parseFile($file),
            static fn (Node $node): bool => $node instanceof Class_ && $node->namespacedName?->toString() === $className,
        );

        if ($declaration === null) {
            return [];
        }

        $names = [];
        foreach ($finder->findInstanceOf([$declaration], MethodCall::class) as $call) {
            $name = self::propertyNamedBy($call);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function propertyNamedBy(MethodCall $call): ?string
    {
        if (!$call->var instanceof Variable || $call->var->name !== 'this'
            || !$call->name instanceof Identifier || !\in_array($call->name->toString(), self::READING_METHODS, true)) {
            return null;
        }

        foreach ($call->getArgs() as $position => $argument) {
            if ($argument->name?->toString() === 'property' || ($argument->name === null && $position === 0)) {
                return $argument->value instanceof String_ ? $argument->value->value : null;
            }
        }

        return null;
    }
}
