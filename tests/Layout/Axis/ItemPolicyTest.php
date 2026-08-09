<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Axis;

use Elmphant\Tui\Layout\Constraint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ItemPolicyTest extends TestCase
{
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
