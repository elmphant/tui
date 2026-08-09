<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Invariant;

use Elmphant\Tui\Layout\Axis\Justification;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Flex;
use Elmphant\Tui\Layout\Rect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FlexAllocationInvariantTest extends TestCase
{
    use RectInvariants;

    private const EXTENTS = [0, 1, 3, 7, 20, 80];

    #[Test]
    #[DataProvider('rows')]
    public function everyItemStaysInsideTheArea(array $items, bool $wrap, int $gap, Justification $justify, int $extent): void
    {
        $area = new Rect(3, 1, $extent, 6);

        self::assertAllWithin($this->rectsFor($items, $wrap, $gap, $justify, $area), $area);
    }

    #[Test]
    #[DataProvider('rows')]
    public function itemsNeverOverlap(array $items, bool $wrap, int $gap, Justification $justify, int $extent): void
    {
        $area = new Rect(3, 1, $extent, 6);

        self::assertNoneOverlap($this->rectsFor($items, $wrap, $gap, $justify, $area));
    }

    #[Test]
    #[DataProvider('rows')]
    public function theSameRowResolvesTheSameWayTwice(array $items, bool $wrap, int $gap, Justification $justify, int $extent): void
    {
        $area = new Rect(3, 1, $extent, 6);

        self::assertEquals(
            $this->rectsFor($items, $wrap, $gap, $justify, $area),
            $this->rectsFor($items, $wrap, $gap, $justify, $area),
        );
    }

    #[Test]
    #[DataProvider('rowsThatFillExactly')]
    public function itemsPackedFromTheStartTileTheRow(array $items, int $extent): void
    {
        $area = new Rect(3, 1, $extent, 6);
        $rects = $this->rectsFor($items, false, 0, Justification::Start, $area);

        self::assertAdjacentAlong($rects, $area, Direction::Horizontal);
        self::assertSame($extent, array_sum(self::extentsAlong($rects, Direction::Horizontal)));
    }

    #[Test]
    #[DataProvider('rowsThatHoldEveryMinimum')]
    public function everyItemLandsBetweenItsMinimumAndItsMaximum(array $items, bool $wrap, int $gap, Justification $justify, int $extent): void
    {
        $area = new Rect(3, 1, $extent, 6);
        $widths = self::extentsAlong($this->rectsFor($items, $wrap, $gap, $justify, $area), Direction::Horizontal);

        foreach ($items as $index => $item) {
            self::assertGreaterThanOrEqual($item['min'], $widths[$index]);
            self::assertLessThanOrEqual($item['max'], $widths[$index]);
        }
    }

    #[Test]
    public function everySweptRowPutsEachItemWhereRecordedForIt(): void
    {
        $resolved = [];

        foreach (self::rows() as $name => [$items, $wrap, $gap, $justify, $extent]) {
            $rects = $this->rectsFor($items, $wrap, $gap, $justify, new Rect(3, 1, $extent, 6));
            $resolved[$name] = self::coordinatesOf($rects);
        }

        self::assertSame(AllocationBaseline::flexRows(), $resolved);
    }

    /**
     * @return iterable<string, array{list<array{basis: int, min: int, max: int, grow: int, shrink: int}>, bool, int, Justification, int}>
     */
    public static function rows(): iterable
    {
        $options = [
            'packed' => [false, 0, Justification::Start],
            'spaced centre' => [false, 1, Justification::Center],
            'wrapped apart' => [true, 0, Justification::SpaceBetween],
            'wrapped end' => [true, 2, Justification::End],
        ];

        foreach (self::itemLists() as $listName => $items) {
            foreach ($options as $optionName => [$wrap, $gap, $justify]) {
                foreach (self::EXTENTS as $extent) {
                    yield $listName . ', ' . $optionName . ' in ' . $extent => [$items, $wrap, $gap, $justify, $extent];
                }
            }
        }
    }

    /**
     * @return iterable<string, array{list<array{basis: int, min: int, max: int, grow: int, shrink: int}>, bool, int, Justification, int}>
     */
    public static function rowsThatHoldEveryMinimum(): iterable
    {
        foreach (self::rows() as $name => [$items, $wrap, $gap, $justify, $extent]) {
            $floors = $gap * (count($items) - 1);
            foreach ($items as $item) {
                $floors += $item['min'];
            }

            if ($floors <= $extent) {
                yield $name => [$items, $wrap, $gap, $justify, $extent];
            }
        }
    }

    /**
     * @return iterable<string, array{list<array{basis: int, min: int, max: int, grow: int, shrink: int}>, int}>
     */
    public static function rowsThatFillExactly(): iterable
    {
        foreach (self::itemLists() as $listName => $items) {
            $basis = 0;
            $absorbs = false;

            foreach ($items as $item) {
                $basis += $item['basis'];
                $absorbs = $absorbs || ($item['grow'] > 0 && $item['max'] === PHP_INT_MAX);
            }

            foreach (self::EXTENTS as $extent) {
                if ($absorbs || $basis > $extent) {
                    yield $listName . ' in ' . $extent => [$items, $extent];
                }
            }
        }
    }

    /**
     * @return array<string, list<array{basis: int, min: int, max: int, grow: int, shrink: int}>>
     */
    private static function itemLists(): array
    {
        $recipes = [
            'grow' => ['basis' => 0, 'min' => 0, 'max' => PHP_INT_MAX, 'grow' => 1, 'shrink' => 1],
            'fixed4' => ['basis' => 4, 'min' => 4, 'max' => 4, 'grow' => 0, 'shrink' => 0],
            'capped3' => ['basis' => 0, 'min' => 0, 'max' => 3, 'grow' => 2, 'shrink' => 1],
            'wide10' => ['basis' => 10, 'min' => 1, 'max' => PHP_INT_MAX, 'grow' => 0, 'shrink' => 1],
            'still6' => ['basis' => 6, 'min' => 0, 'max' => PHP_INT_MAX, 'grow' => 0, 'shrink' => 1],
            'floor3' => ['basis' => 3, 'min' => 3, 'max' => PHP_INT_MAX, 'grow' => 1, 'shrink' => 0],
        ];

        $lists = [
            ['grow', 'grow'],
            ['fixed4', 'grow'],
            ['capped3', 'grow', 'fixed4'],
            ['wide10', 'wide10', 'wide10'],
            ['still6', 'still6'],
            ['floor3', 'capped3', 'grow'],
        ];

        $named = [];
        foreach ($lists as $names) {
            $named[implode('+', $names)] = array_map(
                static fn (string $name): array => $recipes[$name],
                $names,
            );
        }

        return $named;
    }

    /**
     * @param list<array{basis: int, min: int, max: int, grow: int, shrink: int}> $items
     *
     * @return list<Rect>
     */
    private function rectsFor(array $items, bool $wrap, int $gap, Justification $justify, Rect $area): array
    {
        $flex = Flex::row()->withWrap($wrap)->withGap($gap)->withJustify($justify);

        foreach ($items as $item) {
            $flex = $flex->add(
                new ConstrainedWidget(Constraint::exact(1)),
                basis: $item['basis'],
                min: $item['min'],
                max: $item['max'],
                grow: $item['grow'],
                shrink: $item['shrink'],
            );
        }

        return self::rectsOf($flex->computeChildRects($area));
    }
}
