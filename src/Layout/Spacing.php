<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use InvalidArgumentException;

final readonly class Spacing
{
    private function __construct(
        public int $top,
        public int $right,
        public int $bottom,
        public int $left,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0, 0, 0);
    }

    public static function all(int $value): self
    {
        $validated = self::validate($value);

        return new self($validated, $validated, $validated, $validated);
    }

    public static function vertical(int $value): self
    {
        $validated = self::validate($value);

        return new self($validated, 0, $validated, 0);
    }

    public static function horizontal(int $value): self
    {
        $validated = self::validate($value);

        return new self(0, $validated, 0, $validated);
    }

    public static function symmetric(int $horizontal, int $vertical): self
    {
        $x = self::validate($horizontal);
        $y = self::validate($vertical);

        return new self($y, $x, $y, $x);
    }

    public static function only(
        int $top = 0,
        int $right = 0,
        int $bottom = 0,
        int $left = 0,
    ): self {
        return new self(
            self::validate($top),
            self::validate($right),
            self::validate($bottom),
            self::validate($left),
        );
    }

    private static function validate(int $value): int
    {
        if ($value < 0 || $value > 255) {
            throw new InvalidArgumentException(sprintf('Spacing must be between 0 and 255, got %d', $value));
        }

        return $value;
    }
}
