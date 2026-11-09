<?php

declare(strict_types=1);

namespace Elmphant\Tui\Stan;

use Elmphant\Tui\Application\BindsProperties;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedPropertyReflection;
use PHPStan\Rules\Properties\ReadWritePropertiesExtension;

/**
 * bind('name') and mount('name') of BindsProperties read the property they name. So a property a class only binds
 * or mounts is not reported as never read.
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

        if ($file === null || !$class->hasTraitUse(BindsProperties::class)) {
            return false;
        }

        $this->namesReadByClass[$class->getName()] ??= $this->namesReadIn($file, $class);

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
    private function namesReadIn(string $file, ClassReflection $class): array
    {
        $finder = new NodeFinder();
        $declaration = $finder->findFirst(
            $this->parser->parseFile($file),
            static fn (Node $node): bool => $node instanceof Class_ && $node->namespacedName?->toString() === $class->getName(),
        );

        if ($declaration === null) {
            return [];
        }

        $names = [];
        foreach ($finder->findInstanceOf([$declaration], MethodCall::class) as $call) {
            $name = self::propertyNamedBy($call, $class);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function propertyNamedBy(MethodCall $call, ClassReflection $class): ?string
    {
        if (!$call->var instanceof Variable || $call->var->name !== 'this'
            || !$call->name instanceof Identifier || !\in_array($call->name->toString(), self::READING_METHODS, true)) {
            return null;
        }

        foreach ($call->getArgs() as $position => $argument) {
            if ($argument->name?->toString() === 'property' || ($argument->name === null && $position === 0)) {
                return self::stringOf($argument->value, $class);
            }
        }

        return null;
    }

    private static function stringOf(Expr $value, ClassReflection $class): ?string
    {
        if ($value instanceof String_) {
            return $value->value;
        }

        if (!$value instanceof ClassConstFetch || !$value->class instanceof Name || !\in_array($value->class->toLowerString(), ['self', 'static'], true)
            || !$value->name instanceof Identifier || !$class->hasConstant($value->name->toString())) {
            return null;
        }

        $strings = $class->getConstant($value->name->toString())->getValueType()->getConstantStrings();

        return \count($strings) === 1 ? $strings[0]->getValue() : null;
    }
}
