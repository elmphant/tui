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
        if ($flex < 0) {
            throw new InvalidArgumentException(sprintf('Constraint flex weight must be non-negative, got %d', $flex));
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
        if ($weight < 1) {
            throw new InvalidArgumentException(sprintf('Constraint::flex() needs a weight above zero, got %d; a size that does not grow is exact() or min()', $weight));
        }

        return $weight === 1
            ? self::flexOne()
            : new self(flex: $weight);
    }

    /**
     * Every floor is reserved first, what is left is shared by weight and
     * clamped to each ceiling, and the cells integer division leaves over go to
     * the rearmost weighted entries that still have room below their ceiling.
     *
     * Declared floors can ask for more than there is, so the sizes can sum to
     * more than $available; keeping the result inside an area is the caller's.
     *
     * @param list<self> $constraints
     *
     * @return list<int>
     */
    public static function allocate(array $constraints, int $available): array
    {
        $reserved = 0;
        $totalWeight = 0;

        foreach ($constraints as $constraint) {
            $reserved += $constraint->min;
            $totalWeight += $constraint->flex;
        }

        $remaining = max(0, $available - $reserved);
        $sizes = [];
        $granted = 0;

        foreach ($constraints as $index => $constraint) {
            $size = $constraint->min;
            if ($constraint->flex > 0 && $totalWeight > 0) {
                $size = min($constraint->min + intdiv($remaining * $constraint->flex, $totalWeight), $constraint->max);
                $granted += $size - $constraint->min;
            }
            $sizes[$index] = $size;
        }

        $leftover = $remaining - $granted;
        foreach (array_reverse(array_keys($sizes)) as $index) {
            if ($constraints[$index]->flex > 0) {
                $grant = min($leftover, $constraints[$index]->max - $sizes[$index]);
                $sizes[$index] += $grant;
                $leftover -= $grant;
            }
        }

        return array_values($sizes);
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
