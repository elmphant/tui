<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Invariant;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\StaticTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StaticTableAllocationInvariantTest extends TestCase
{
    private const FRAME_WIDTH = 200;

    private const WIDTHS = [0, 1, 4, 10, 20, 40, 80];

    #[Test]
    #[DataProvider('gridsWithAWidthToDrawIn')]
    public function everyLineIsAsWideAsTheTableSaysItDrew(array $headers, array $rows, int $width): void
    {
        $table = StaticTable::new($headers, $rows);
        $area = self::areaOf($width);
        $frame = $this->drawn($table, $area);
        $drawn = $table->drawnWidth($width);

        // A line cut short by the area can end on one of its padding spaces, so
        // the ink reaches at most as far as the table says it drew.
        for ($row = 0; $row < $table->measureHeight($width); ++$row) {
            self::assertLessThanOrEqual($area->x + $drawn - 1, $this->rightmostInk($frame, $area->y + $row));
        }
    }

    #[Test]
    #[DataProvider('gridsThatFitTheirWidth')]
    public function aTableThatFitsDrawsNothingOutsideTheArea(array $headers, array $rows, int $width): void
    {
        $table = StaticTable::new($headers, $rows);
        $area = self::areaOf($width);
        $frame = $this->drawn($table, $area);

        for ($row = 0; $row < $table->measureHeight($width); ++$row) {
            self::assertLessThan($area->right(), $this->rightmostInk($frame, $area->y + $row));
        }
    }

    #[Test]
    #[DataProvider('gridsWiderThanTheirWidth')]
    public function aTableTooWideForTheAreaIsCutOffAtItsRightEdge(array $headers, array $rows, int $width): void
    {
        $table = StaticTable::new($headers, $rows);
        $area = self::areaOf($width);
        $frame = $this->drawn($table, $area);

        self::assertSame($width, $table->drawnWidth($width), 'It reports only the width it was offered');
        self::assertLessThan($area->right(), $this->rightmostInk($frame, $area->y), 'and draws nothing beyond it');
    }

    #[Test]
    #[DataProvider('grids')]
    public function theSameContentDrawsTheSameGridTwice(array $headers, array $rows, int $width): void
    {
        self::assertSame(
            StaticTable::new($headers, $rows)->drawnWidth($width),
            StaticTable::new($headers, $rows)->drawnWidth($width),
        );
        self::assertSame(
            StaticTable::new($headers, $rows)->measureHeight($width),
            StaticTable::new($headers, $rows)->measureHeight($width),
        );
    }

    #[Test]
    public function aTableOfferedNoWidthDrawsNothingAndReportsNoWidth(): void
    {
        $table = StaticTable::new(['Name', 'Size'], [['alpha', '10']]);
        $area = self::areaOf(0);

        self::assertSame(0, $table->drawnWidth(0));
        self::assertSame(-1, $this->rightmostInk($this->drawn($table, $area), $area->y));
        self::assertSame(0, $table->measureHeight(0), 'and reserves no rows for a grid it cannot draw');
    }

    /**
     * @return iterable<string, array{list<string>, list<list<string>>, int}>
     */
    public static function grids(): iterable
    {
        $contents = [
            'one column' => [['Name'], [['alpha'], ['beta']]],
            'two columns' => [['Name', 'Size'], [['alpha', '10'], ['beta', '2048']]],
            'three columns' => [
                ['Id', 'Description', 'When'],
                [['1', 'a short note', '2026-08-08'], ['22', 'another note here', '2026-08-09']],
            ],
            'long words' => [
                ['Identifier', 'Configuration'],
                [['ExtremelyLongIdentifier', 'value'], ['short', 'AnotherVeryLongToken']],
            ],
            'four columns' => [
                ['A', 'B', 'C', 'D'],
                [['1', '2', '3', '4'], ['five', 'six', 'seven', 'eight']],
            ],
        ];

        foreach ($contents as $name => [$headers, $rows]) {
            foreach (self::WIDTHS as $width) {
                yield $name . ' in ' . $width => [$headers, $rows, $width];
            }
        }
    }

    /**
     * @return iterable<string, array{list<string>, list<list<string>>, int}>
     */
    public static function gridsWithAWidthToDrawIn(): iterable
    {
        foreach (self::grids() as $name => [$headers, $rows, $width]) {
            if ($width > 0) {
                yield $name => [$headers, $rows, $width];
            }
        }
    }

    /**
     * @return iterable<string, array{list<string>, list<list<string>>, int}>
     */
    public static function gridsThatFitTheirWidth(): iterable
    {
        foreach (self::gridsWithAWidthToDrawIn() as $name => [$headers, $rows, $width]) {
            if (StaticTable::new($headers, $rows)->drawnWidth($width) <= $width) {
                yield $name => [$headers, $rows, $width];
            }
        }
    }

    /**
     * @return iterable<string, array{list<string>, list<list<string>>, int}>
     */
    public static function gridsWiderThanTheirWidth(): iterable
    {
        foreach (self::gridsWithAWidthToDrawIn() as $name => [$headers, $rows, $width]) {
            $table = StaticTable::new($headers, $rows);

            // The grid it would draw unclipped, which is what makes the area cut
            // it off; drawnWidth() reports only what survives the cut.
            if ($table->drawnWidth(PHP_INT_MAX) > $width) {
                yield $name => [$headers, $rows, $width];
            }
        }
    }

    private static function areaOf(int $width): Rect
    {
        return new Rect(3, 1, $width, 20);
    }

    private function drawn(StaticTable $table, Rect $area): Frame
    {
        $frame = new Frame(self::FRAME_WIDTH, 24);
        $table->render($frame, $area, new RenderContext(Theme::dark()));

        return $frame;
    }

    private function rightmostInk(Frame $frame, int $row): int
    {
        for ($x = self::FRAME_WIDTH - 1; $x >= 0; --$x) {
            if ($frame->characters[$row * self::FRAME_WIDTH + $x] !== ' ') {
                return $x;
            }
        }

        return -1;
    }
}
