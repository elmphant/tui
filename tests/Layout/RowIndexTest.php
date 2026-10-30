<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\RowIndex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RowIndexTest extends TestCase
{
    #[Test]
    public function measuresEachRowOnceForAGivenWidth(): void
    {
        $calls = 0;
        $index = RowIndex::of(50);
        $measure = static function (int $row) use (&$calls): int {
            ++$calls;

            return 1;
        };

        $index->at(80, $measure);
        $index->at(80, $measure);
        $index->at(80, $measure);

        self::assertSame(50, $calls);
    }

    #[Test]
    public function measuresAgainWhenTheWidthChanges(): void
    {
        $widths = [];
        $index = RowIndex::of(3);

        $index->at(80, static function (int $row) use (&$widths): int {
            $widths[] = 80;

            return 1;
        });
        $index->at(40, static function (int $row) use (&$widths): int {
            $widths[] = 40;

            return 1;
        });

        self::assertSame([80, 80, 80, 40, 40, 40], $widths);
    }

    /**
     * Retain both full-width and scrollbar-width measurements across frames.
     */
    #[Test]
    public function retainsTheTwoWidthsOneFrameUses(): void
    {
        $calls = 0;
        $index = RowIndex::of(10);
        $measure = static function (int $row) use (&$calls): int {
            ++$calls;

            return 1;
        };

        for ($frame = 0; $frame < 5; ++$frame) {
            $index->at(80, $measure);
            $index->at(78, $measure);
        }

        self::assertSame(20, $calls);
    }

    #[Test]
    public function forgetsTheLeastRecentlyAddedWidth(): void
    {
        $calls = 0;
        $index = RowIndex::of(4);
        $measure = static function (int $row) use (&$calls): int {
            ++$calls;

            return 1;
        };

        $index->at(10, $measure);
        $index->at(20, $measure);
        $index->at(30, $measure);
        $index->at(10, $measure);

        self::assertSame(16, $calls);
    }

    #[Test]
    public function reportsHowManyRowsItCovers(): void
    {
        $index = RowIndex::of(7);

        self::assertSame(7, $index->rowCount());
        self::assertSame(7, $index->at(80, static fn (int $row): int => 1)->totalHeight());
    }

    #[Test]
    public function keysCanDescribeSomethingRicherThanAWidth(): void
    {
        $calls = 0;
        $index = RowIndex::of(3);
        $measure = static function (int $row) use (&$calls): int {
            ++$calls;

            return 1;
        };

        $index->at('20,10,5', $measure);
        $index->at('20,10,5', $measure);
        $index->at('19,10,5', $measure);

        self::assertSame(6, $calls);
    }

    #[Test]
    public function rowsExactlyAsTallAsTheRoomDoNotOverflowIt(): void
    {
        $index = RowIndex::of(3);

        self::assertFalse($index->exceeds(80, 3, static fn (int $row): int => 1));
        self::assertTrue($index->exceeds(80, 2, static fn (int $row): int => 1));
    }

    #[Test]
    public function emptyAndNegativeRowsTakeNoRoom(): void
    {
        self::assertFalse(RowIndex::of(4)->exceeds(80, 2, static fn (int $row): int => [1, 0, -3, 1][$row]));
        self::assertTrue(RowIndex::of(4)->exceeds(80, 1, static fn (int $row): int => [1, 0, -3, 1][$row]));
    }

    #[Test]
    public function answersTheSameOverflowQuestionWithoutMeasuringAgain(): void
    {
        $calls = 0;
        $index = RowIndex::of(3);
        $measure = static function (int $row) use (&$calls): int {
            ++$calls;

            return 1;
        };

        $index->exceeds(80, 5, $measure);
        $index->exceeds(80, 5, $measure);

        self::assertSame(3, $calls);
    }

    #[Test]
    public function aDifferentHeightIsADifferentOverflowQuestion(): void
    {
        $index = RowIndex::of(2);
        $fiveLinesEach = static fn (int $row): int => 5;

        self::assertFalse($index->exceeds(1, 23, $fiveLinesEach));
        self::assertTrue($index->exceeds(12, 3, $fiveLinesEach));
        self::assertFalse($index->exceeds(12, 10, $fiveLinesEach));
    }

    #[Test]
    public function aDifferentWidthIsADifferentOverflowQuestion(): void
    {
        $index = RowIndex::of(2);

        self::assertTrue($index->exceeds(10, 3, static fn (int $row): int => 5));
        self::assertFalse($index->exceeds(80, 3, static fn (int $row): int => 1));
    }

    #[Test]
    public function measuresNothingUntilAskedForAWidth(): void
    {
        $calls = 0;
        RowIndex::of(100);

        self::assertSame(0, $calls);
    }
}
