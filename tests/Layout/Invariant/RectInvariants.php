<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Invariant;

use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Rect;
use PHPUnit\Framework\Assert;

trait RectInvariants
{
    /**
     * @param list<Rect> $rects
     */
    private static function assertAllWithin(array $rects, Rect $area): void
    {
        foreach ($rects as $index => $rect) {
            Assert::assertTrue(
                $rect->x >= $area->x
                    && $rect->y >= $area->y
                    && $rect->right() <= $area->right()
                    && $rect->bottom() <= $area->bottom(),
                sprintf('Child %d %s escapes %s', $index, self::describe($rect), self::describe($area)),
            );
        }
    }

    /**
     * @param list<Rect> $rects
     */
    private static function assertNoneOverlap(array $rects): void
    {
        foreach ($rects as $index => $rect) {
            foreach (array_slice($rects, $index + 1) as $other) {
                $shared = $rect->intersect($other);
                Assert::assertTrue(
                    $shared->width === 0 || $shared->height === 0,
                    sprintf('%s and %s share %s', self::describe($rect), self::describe($other), self::describe($shared)),
                );
            }
        }
    }

    /**
     * @param list<Rect> $rects
     */
    private static function assertAdjacentAlong(array $rects, Rect $area, Direction $direction, int $gap = 0): void
    {
        Assert::assertNotEmpty($rects);
        $first = $rects[0];

        Assert::assertSame(
            $direction === Direction::Vertical ? $area->y : $area->x,
            $direction === Direction::Vertical ? $first->y : $first->x,
            'The run does not start at the area origin',
        );

        foreach (array_slice($rects, 1) as $offset => $rect) {
            $previous = $rects[$offset];
            $expected = $direction === Direction::Vertical
                ? $previous->bottom() + $gap
                : $previous->right() + $gap;
            $actual = $direction === Direction::Vertical ? $rect->y : $rect->x;

            Assert::assertSame(
                $expected,
                $actual,
                sprintf('%s does not follow %s', self::describe($rect), self::describe($previous)),
            );
        }
    }

    /**
     * @param list<array{mixed, Rect}> $pairs
     *
     * @return list<Rect>
     */
    private static function rectsOf(iterable $pairs): array
    {
        $rects = [];
        foreach ($pairs as [, $rect]) {
            $rects[] = $rect;
        }

        return $rects;
    }

    /**
     * @param list<Rect> $rects
     *
     * @return list<int>
     */
    private static function extentsAlong(array $rects, Direction $direction): array
    {
        return array_map(
            static fn (Rect $rect): int => $direction === Direction::Vertical ? $rect->height : $rect->width,
            $rects,
        );
    }

    private static function describe(Rect $rect): string
    {
        return sprintf('[%d,%d %dx%d]', $rect->x, $rect->y, $rect->width, $rect->height);
    }
}
