<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Elmphant\Tui\Layout\Axis\Allocator;
use Elmphant\Tui\Layout\Axis\ItemPolicy;
use InvalidArgumentException;

final readonly class Constraint
{
    /**
     * Larger weights overflow an integer once the allocator multiplies them by the spare cells.
     */
    private const MAX_WEIGHT = 1_000_000;

    public function __construct(
        public int $min = 0,
        public int $max = PHP_INT_MAX,
        public int $flex = 0,
        public ?int $basis = null,
        public int $shrink = 1,
        /**
         * A null basis measures content only for Constraint::content(); otherwise it starts at the minimum.
         */
        public bool $measuresContent = false,
    ) {
        if (null !== $basis && $basis < 0) {
            throw new InvalidArgumentException(sprintf('Constraint basis must be non-negative, got %d', $basis));
        }
        if ($shrink < 0) {
            throw new InvalidArgumentException(sprintf('Constraint shrink weight must be non-negative, got %d', $shrink));
        }
        if ($min < 0 || $max < 0) {
            throw new InvalidArgumentException(sprintf('Constraint bounds must be non-negative, got min %d and max %d', $min, $max));
        }
        if ($max < $min) {
            throw new InvalidArgumentException(sprintf('Constraint max must be greater than or equal to min, got max %d and min %d', $max, $min));
        }
        if ($flex < 0) {
            throw new InvalidArgumentException(sprintf('Constraint flex weight must be non-negative, got %d', $flex));
        }
        if ($flex > self::MAX_WEIGHT || $shrink > self::MAX_WEIGHT) {
            throw new InvalidArgumentException(sprintf('Constraint weights go up to %d, got flex %d and shrink %d', self::MAX_WEIGHT, $flex, $shrink));
        }
    }

    public static function exact(int $size): self
    {
        return match ($size) {
            0 => self::exactZero(),
            1 => self::exactOne(),
            default => new self(min: $size, max: $size, shrink: 0),
        };
    }

    public static function min(int $size): self
    {
        return new self(min: $size);
    }

    /**
     * Grow up to `$size`; without growth, a maximum alone would leave the size at zero.
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
        return new self(min: $this->min, max: $this->max, flex: $weight, basis: $this->basis, shrink: $this->shrink, measuresContent: $this->measuresContent);
    }

    /**
     * A null basis starts at the minimum, except content(), which measures child height or declared width.
     */
    public function withBasis(int $basis): self
    {
        return new self(min: $this->min, max: $this->max, flex: $this->flex, basis: $basis, shrink: $this->shrink, measuresContent: $this->measuresContent);
    }

    /**
     * Use measured height or declared width; children that provide neither have zero content size.
     */
    public static function content(): self
    {
        return new self(measuresContent: true);
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

    public function intrinsic(): int
    {
        return $this->basis ?? $this->min;
    }

    /**
     * Without a declared basis a constraint starts at its floor.
     *
     * @internal
     */
    public function policy(): ItemPolicy
    {
        return new ItemPolicy(
            basis: $this->intrinsic(),
            min: $this->min,
            max: $this->max,
            grow: $this->flex,
            shrink: $this->shrink,
        );
    }

    /**
     * Reserve minimums, share the remainder by weight up to each maximum, then give leftover cells to the last eligible entries.
     * Minimums may exceed $available; callers must clip the result.
     *
     * @param list<self> $constraints
     *
     * @return list<int>
     */
    public static function allocate(array $constraints, int $available): array
    {
        $policies = [];
        foreach ($constraints as $constraint) {
            $policies[] = $constraint->policy();
        }

        return Allocator::resolve($policies, $available)->sizes;
    }

    private static function exactZero(): self
    {
        static $constraint;

        return $constraint ??= new self(min: 0, max: 0, shrink: 0);
    }

    private static function exactOne(): self
    {
        static $constraint;

        return $constraint ??= new self(min: 1, max: 1, shrink: 0);
    }

    private static function flexOne(): self
    {
        static $constraint;

        return $constraint ??= new self(flex: 1);
    }
}
