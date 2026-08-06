<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

/**
 * @internal framework keyed write-back primitive; expose typed withX() methods instead
 */
trait Cloneable
{
    /**
     * @internal Framework keyed write-back primitive. Public APIs should
     *           expose typed withX() methods instead.
     */
    public function cloneWith(mixed ...$overrides): static
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
     * get_object_vars() would run in the scope of the class that uses this trait,
     * hiding properties a subclass declares private; the array cast sees them all,
     * mangled as "\0Class\0name".
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
     * `new static` rather than reflection: most consumers keep a private
     * constructor behind static factories, which only same-scope construction
     * can reach.
     *
     * @param array<string, mixed> $fields
     */
    private function rebuiltFrom(array $fields): static
    {
        return new static(...$fields);
    }
}
