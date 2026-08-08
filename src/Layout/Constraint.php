<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use InvalidArgumentException;

final readonly class Constraint
{
    public function __construct(
        public int $min = 0,
        public int $max = PHP_INT_MAX,
        public int $flex = 0,
    ) {
        if ($min < 0 || $max < 0) {
            throw new InvalidArgumentException(sprintf('Constraint bounds must be non-negative, got min %d and max %d', $min, $max));
        }
        if ($max < $min) {
            throw new InvalidArgumentException(sprintf('Constraint max must be greater than or equal to min, got max %d and min %d', $max, $min));
        }
    }

    public static function exact(int $size): self
    {
        return match ($size) {
            0 => self::exactZero(),
            1 => self::exactOne(),
            default => new self(min: $size, max: $size),
        };
    }

    public static function min(int $size): self
    {
        return new self(min: $size);
    }

    /**
     * Grows to fill, but never past `$size`. Carries a weight because a
     * ceiling with no growth would pin the child at nothing.
     */
    public static function max(int $size): self
    {
        return new self(max: $size, flex: 1);
    }

    /**
     * Adds a growth weight, so `min(3)->withFlex()` means "3 rows at the very
     * least, then a share of whatever is left".
     */
    public function withFlex(int $weight = 1): self
    {
        return new self(min: $this->min, max: $this->max, flex: $weight);
    }

    public static function flex(int $weight = 1): self
    {
        return $weight === 1
            ? self::flexOne()
            : new self(flex: $weight);
    }

    private static function exactZero(): self
    {
        static $constraint;

        return $constraint ??= new self(min: 0, max: 0);
    }

    private static function exactOne(): self
    {
        static $constraint;

        return $constraint ??= new self(min: 1, max: 1);
    }

    private static function flexOne(): self
    {
        static $constraint;

        return $constraint ??= new self(flex: 1);
    }
}
