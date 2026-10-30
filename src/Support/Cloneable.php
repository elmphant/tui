<?php

declare(strict_types=1);

namespace Elmphant\Tui\Support;

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
        return new static(...$fields);
    }
}
