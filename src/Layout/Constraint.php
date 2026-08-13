<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Elmphant\Tui\Layout\Axis\Allocator;
use Elmphant\Tui\Layout\Axis\ItemPolicy;
use InvalidArgumentException;

final readonly class Constraint
{
    public function __construct(
        public int $min = 0,
        public int $max = PHP_INT_MAX,
        public int $flex = 0,
        public ?int $basis = null,
        public int $shrink = 1,
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
        return new self(min: $this->min, max: $this->max, flex: $weight, basis: $this->basis, shrink: $this->shrink);
    }

    /**
     * The size the content wants, before any sharing out. A null basis means
     * "measure me", which only the vertical axis has a device for.
     */
    public function withBasis(int $basis): self
    {
        return new self(min: $this->min, max: $this->max, flex: $this->flex, basis: $basis, shrink: $this->shrink);
    }

    /**
     * As tall or wide as what the child holds, and no further. A child that
     * cannot measure itself has no content to go by and stays at nothing.
     */
    public static function content(): self
    {
        return new self();
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
     * Without a declared basis a constraint starts at its floor.
     *
     * @internal
     */
    public function policy(): ItemPolicy
    {
        return new ItemPolicy(
            basis: $this->basis ?? $this->min,
            min: $this->min,
            max: $this->max,
            grow: $this->flex,
            shrink: $this->shrink,
        );
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
        $policies = [];
        foreach ($constraints as $constraint) {
            $policies[] = $constraint->policy();
        }

        return Allocator::resolve($policies, $available)->sizes;
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
