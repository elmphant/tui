<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\Rect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RectIntersectTest extends TestCase
{
    #[Test]
    public function overlappingRectsGiveTheSharedRegion(): void
    {
        $overlap = (new Rect(0, 0, 10, 10))->intersect(new Rect(5, 5, 10, 10));

        self::assertTrue($overlap->equals(new Rect(5, 5, 5, 5)));
    }

    #[Test]
    public function aContainedRectIsItsOwnIntersection(): void
    {
        $inner = new Rect(2, 3, 4, 5);

        self::assertTrue($inner->intersect(new Rect(0, 0, 20, 20))->equals($inner));
    }

    #[Test]
    public function aTallerRectIsClippedToTheViewport(): void
    {
        $child = new Rect(0, -50, 120, 20000);
        $viewport = new Rect(0, 0, 120, 40);

        self::assertTrue($child->intersect($viewport)->equals(new Rect(0, 0, 120, 40)));
    }

    #[Test]
    public function disjointRectsIntersectToNothing(): void
    {
        $apart = (new Rect(0, 0, 5, 5))->intersect(new Rect(90, 90, 5, 5));

        self::assertSame(0, $apart->width);
        self::assertSame(0, $apart->height);
    }

    #[Test]
    public function touchingEdgesShareNoArea(): void
    {
        $touching = (new Rect(0, 0, 5, 5))->intersect(new Rect(5, 0, 5, 5));

        self::assertSame(0, $touching->width);
    }

    #[Test]
    public function intersectionIsSymmetric(): void
    {
        $first = new Rect(3, 4, 10, 10);
        $second = new Rect(0, 8, 20, 3);

        self::assertTrue($first->intersect($second)->equals($second->intersect($first)));
    }
}
