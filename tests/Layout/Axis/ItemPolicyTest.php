<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Axis;

use Elmphant\Tui\Layout\Axis\ItemPolicy;
use Elmphant\Tui\Layout\Constraint;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ItemPolicyTest extends TestCase
{
    #[Test]
    public function refusesASizeBelowZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ItemPolicy sizes must be non-negative, got basis -1, min 0 and max 4');

        new ItemPolicy(basis: -1, max: 4);
    }

    #[Test]
    public function refusesAWeightBelowZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ItemPolicy weights must be non-negative, got grow 1 and shrink -1');

        new ItemPolicy(grow: 1, shrink: -1);
    }

    #[Test]
    public function refusesACeilingBelowItsFloor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ItemPolicy max must be greater than or equal to min, got max 2 and min 5');

        new ItemPolicy(min: 5, max: 2);
    }

    #[Test]
    public function aConstraintStartsAtItsFloorAndGrowsByItsWeight(): void
    {
        $policy = Constraint::min(4)->withFlex(3)->policy();

        self::assertSame(4, $policy->basis);
        self::assertSame(4, $policy->min);
        self::assertSame(PHP_INT_MAX, $policy->max);
        self::assertSame(3, $policy->grow);
        self::assertSame(0, $policy->shrink);
    }
}
