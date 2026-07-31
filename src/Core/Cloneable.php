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
        $current = get_object_vars($this);
        foreach ($overrides as $name => $value) {
            if (!array_key_exists($name, $current) || $current[$name] !== $value) {
                return new static(...[...$current, ...$overrides]); // @phpstan-ignore argument.type
            }
        }

        return $this;
    }
}
