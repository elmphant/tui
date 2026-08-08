<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use InvalidArgumentException;

final readonly class Rect
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {
        if ($width < 0 || $height < 0) {
            throw new InvalidArgumentException(sprintf('Rect extent must be non-negative, got width %d and height %d', $width, $height));
        }
    }

    public static function fromSize(int $width, int $height): self
    {
        return new self(0, 0, $width, $height);
    }

    public function right(): int
    {
        return $this->x + $this->width;
    }

    public function bottom(): int
    {
        return $this->y + $this->height;
    }

    public function contains(int $x, int $y): bool
    {
        return $x >= $this->x && $x < $this->right()
            && $y >= $this->y && $y < $this->bottom();
    }

    public function equals(self $other): bool
    {
        return $this->x === $other->x
            && $this->y === $other->y
            && $this->width === $other->width
            && $this->height === $other->height;
    }

    public function shrink(int $top, int $right, int $bottom, int $left): self
    {
        return new self(
            $this->x + $left,
            $this->y + $top,
            max(0, $this->width - $left - $right),
            max(0, $this->height - $top - $bottom),
        );
    }

    /**
     * The part of this rect that also lies inside $other.
     *
     * Zero width or height where they do not overlap, so callers can treat an
     * empty result as "nothing to draw" without a separate emptiness test.
     */
    public function intersect(self $other): self
    {
        $x = max($this->x, $other->x);
        $y = max($this->y, $other->y);

        return new self(
            $x,
            $y,
            max(0, min($this->right(), $other->right()) - $x),
            max(0, min($this->bottom(), $other->bottom()) - $y),
        );
    }

    public function inner(int $margin = 1): self
    {
        return $this->shrink($margin, $margin, $margin, $margin);
    }

    /**
     * Find the index of the first rect that contains the given point.
     *
     * @param list<self> $rects
     */
    public static function findContaining(array $rects, int $x, int $y): ?int
    {
        foreach ($rects as $index => $rect) {
            if ($rect->contains($x, $y)) {
                return $index;
            }
        }

        return null;
    }
}
