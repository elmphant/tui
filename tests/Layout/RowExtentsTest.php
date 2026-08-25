<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\RowExtents;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RowExtentsTest extends TestCase
{
    #[Test]
    public function totalHeightSumsEveryRow(): void
    {
        self::assertSame(7, self::sample()->totalHeight());
    }

    #[Test]
    public function reportsWhereEachRowStartsAndHowTallItIs(): void
    {
        $extents = self::sample();

        self::assertSame([0, 1, 3, 6], [
            $extents->startOf(0),
            $extents->startOf(1),
            $extents->startOf(2),
            $extents->startOf(3),
        ]);
        self::assertSame([1, 2, 3, 1], [
            $extents->heightOf(0),
            $extents->heightOf(1),
            $extents->heightOf(2),
            $extents->heightOf(3),
        ]);
    }

    #[Test]
    public function findsTheRowCoveringEachLine(): void
    {
        $extents = self::sample();

        self::assertSame(
            [0, 1, 1, 2, 2, 2, 3],
            array_map($extents->rowAtLine(...), range(0, 6)),
        );
    }

    #[Test]
    public function hasNoRowBeyondTheLastLine(): void
    {
        $extents = self::sample();

        self::assertNull($extents->rowAtLine(7));
        self::assertNull($extents->rowAtLine(99));
        self::assertNull($extents->rowAtLine(-1));
    }

    #[Test]
    public function anEmptyCollectionHasNoHeightAndNoRows(): void
    {
        $extents = RowExtents::measure(0, static fn (int $index): int => 1);

        self::assertSame(0, $extents->totalHeight());
        self::assertNull($extents->rowAtLine(0));
        self::assertSame(0, $extents->maxOffset(10));
    }

    #[Test]
    public function findsTheFirstRowStartingAtOrAfterALine(): void
    {
        $extents = self::sample();

        self::assertSame(
            [0, 1, 2, 2, 3, 3, 3, 4],
            array_map($extents->firstRowAtOrAfter(...), range(0, 7)),
        );
    }

    #[Test]
    public function everyRowStartsAtOrAfterTheFirstLine(): void
    {
        self::assertSame(0, RowExtents::measure(0, static fn (int $index): int => 1)->firstRowAtOrAfter(0));
    }

    #[Test]
    public function maxOffsetLeavesABlankLineUnderTheLastRow(): void
    {
        $extents = self::sample();

        self::assertSame(5, $extents->maxOffset(3));
        self::assertSame(0, $extents->maxOffset(7));
        self::assertSame(0, $extents->maxOffset(99));
    }

    #[Test]
    public function keepsAnAlreadyVisibleRowWhereItIs(): void
    {
        $extents = self::sample();

        self::assertSame(0, $extents->offsetShowing(1, 4, 0));
    }

    #[Test]
    public function scrollsUpToRevealARowAboveTheViewport(): void
    {
        $extents = self::sample();

        self::assertSame(1, $extents->offsetShowing(1, 3, 5));
    }

    #[Test]
    public function scrollsDownJustFarEnoughToRevealARowBelowTheViewport(): void
    {
        $extents = self::sample();

        // row 3 spans line 6; a 3-line viewport must start at 4 to include it
        self::assertSame(4, $extents->offsetShowing(3, 3, 0));
    }

    #[Test]
    public function clampsAnOverscrolledOffsetBeforeConsideringTheRow(): void
    {
        $extents = self::sample();

        self::assertSame(5, $extents->offsetShowing(3, 3, 999));
    }

    #[Test]
    public function aRowTallerThanTheViewportAlignsToItsTop(): void
    {
        $extents = RowExtents::measure(2, static fn (int $index): int => $index === 0 ? 1 : 10);

        self::assertSame(1, $extents->offsetShowing(1, 4, 0));
    }

    #[Test]
    public function zeroHeightRowsDoNotSwallowTheirNeighbours(): void
    {
        $extents = RowExtents::measure(3, static fn (int $index): int => $index === 1 ? 0 : 1);

        self::assertSame(2, $extents->totalHeight());
        self::assertSame(0, $extents->rowAtLine(0));
        self::assertSame(2, $extents->rowAtLine(1));
    }

    /**
     * Heights 1,2,3,1 → starts 0,1,3,6 and a total of 7.
     */
    private static function sample(): RowExtents
    {
        return RowExtents::measure(4, static fn (int $index): int => [1, 2, 3, 1][$index]);
    }
}
