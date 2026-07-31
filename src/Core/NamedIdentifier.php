<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use InvalidArgumentException;

/**
 * A non-empty named value object. The using class supplies LABEL for the
 * message a caller sees when it hands over an empty name.
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
