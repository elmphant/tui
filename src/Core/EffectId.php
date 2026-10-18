<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use InvalidArgumentException;
use Stringable;

/**
 * A name a command's effect answers to. The scope is the part of the app it ran
 * in (`Cmd::scopedTo()`), so two parts can use one name without cancelling each other.
 */
final readonly class EffectId implements Stringable
{
    private function __construct(
        public string $value,
        public ?string $scope = null,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('Effect ID must not be empty');
        }
    }

    public static function named(string $value): self
    {
        return new self($value);
    }

    public function within(string $scope): self
    {
        return new self(value: $this->value, scope: $this->scope === null ? $scope : $scope . '/' . $this->scope);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value && $this->scope === $other->scope;
    }

    public function isWithin(string $scope): bool
    {
        return $this->scope !== null && ($this->scope === $scope || str_starts_with($this->scope, $scope . '/'));
    }

    public function __toString(): string
    {
        return $this->scope === null ? $this->value : $this->scope . '/' . $this->value;
    }
}
