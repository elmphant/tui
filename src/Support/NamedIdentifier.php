<?php

declare(strict_types=1);

namespace Elmphant\Tui\Support;

use InvalidArgumentException;

/**
 * The using class supplies LABEL for errors about empty names.
 *
 * @internal
 */
trait NamedIdentifier
{
    private function __construct(public readonly string $value)
    {
        if ($value === '') {
            throw new InvalidArgumentException(self::LABEL . ' must not be empty');
        }
    }

    public static function named(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
