<?php

declare(strict_types=1);

namespace Elmphant\Tui\Support;

use LogicException;
use ReflectionMethod;

/**
 * @internal framework keyed write-back primitive; expose typed withX() methods instead
 */
trait Cloneable
{
    protected function cloneWith(mixed ...$overrides): static
    {
        $current = $this->cloneableFields();
        foreach ($overrides as $name => $value) {
            if (!array_key_exists($name, $current) || $current[$name] !== $value) {
                return $this->rebuiltFrom([...$current, ...$overrides]);
            }
        }

        return $this;
    }

    protected function valueOf(string $property): mixed
    {
        $fields = $this->cloneableFields();
        if (!array_key_exists($property, $fields)) {
            throw new LogicException(sprintf('%s has no property $%s', static::class, $property));
        }

        return $fields[$property];
    }

    /**
     * Cast to an array to include subclass-private properties, which get_object_vars() cannot see from the trait's scope.
     *
     * @return array<string, mixed>
     */
    private function cloneableFields(): array
    {
        $fields = [];
        foreach ((array)$this as $key => $value) {
            $separator = strrpos($key, "\0");
            $fields[$separator === false ? $key : substr($key, $separator + 1)] = $value;
        }

        return $fields;
    }

    /**
     * Use new static to reach private constructors from the same class scope.
     *
     * @param array<string, mixed> $fields
     */
    private function rebuiltFrom(array $fields): static
    {
        static $reachable = [];
        $reachable[static::class] ??= self::constructorIsReachable();
        if (!$reachable[static::class]) {
            throw new LogicException(sprintf('%s needs a public constructor: the framework builds the next one with it when it stores a widget. Keep the static factory for the starting state, and make the constructor public.', static::class));
        }

        return new static(...$fields);
    }

    private static function constructorIsReachable(): bool
    {
        $constructor = new ReflectionMethod(static::class, '__construct');

        return !$constructor->isPrivate() || $constructor->getDeclaringClass()->getName() === self::class;
    }
}
