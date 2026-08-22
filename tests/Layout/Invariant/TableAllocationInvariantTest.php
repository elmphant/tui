<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Invariant;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Column;
use Elmphant\Tui\Widget\Table;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TableAllocationInvariantTest extends TestCase
{
    private const GLYPHS = ['A', 'B', 'C', 'D', 'E'];

    private const FRAME_WIDTH = 120;

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function noColumnIsDrawnOutsideTheArea(array $constraints, int $extent): void
    {
        $area = self::areaOf($extent);
        $frame = $this->drawn($constraints, $area);

        for ($x = 0; $x < self::FRAME_WIDTH; ++$x) {
            if ($x >= $area->x && $x < $area->right()) {
                continue;
            }

            self::assertSame(' ', $frame->characters[$area->y * self::FRAME_WIDTH + $x], sprintf('The table drew at column %d, outside its area', $x));
        }
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function aTableAllocatesWhatARowOfTheSameConstraintsAllocates(array $constraints, int $extent): void
    {
        $row = Layout::horizontal(...array_map(
            static fn (Constraint $constraint): ConstrainedWidget => new ConstrainedWidget($constraint),
            $constraints,
        ));

        self::assertSame($row->sizesFor($extent), $this->table($constraints)->columnWidths($extent));
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function theDrawnColumnsMatchTheWidthsItAllocatedAsFarAsTheAreaReaches(array $constraints, int $extent): void
    {
        $remaining = $extent;
        $expected = [];

        foreach ($this->table($constraints)->columnWidths($extent) as $width) {
            $fits = min($width, $remaining);
            $expected[] = $fits;
            $remaining -= $fits;
        }

        self::assertSame($expected, $this->drawnWidths($constraints, $extent));
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'casesWhereEveryFloorFits')]
    public function eachColumnLandsBetweenItsFloorAndItsCeiling(array $constraints, int $extent): void
    {
        $widths = $this->table($constraints)->columnWidths($extent);

        foreach ($constraints as $index => $constraint) {
            self::assertGreaterThanOrEqual($constraint->min, $widths[$index]);
            self::assertLessThanOrEqual($constraint->max, $widths[$index]);
        }
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'casesWithAnUncappedWeightedChild')]
    public function aWeightedColumnWithoutACeilingLeavesNoCellUnused(array $constraints, int $extent): void
    {
        self::assertSame($extent, array_sum($this->table($constraints)->columnWidths($extent)));
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function theSameTableAllocatesTheSameColumnsTwice(array $constraints, int $extent): void
    {
        $table = $this->table($constraints);

        self::assertSame($table->columnWidths($extent), $table->columnWidths($extent));
        self::assertSame($this->drawnWidths($constraints, $extent), $this->drawnWidths($constraints, $extent));
    }

    #[Test]
    public function aTableMeasuresItsHeightAgainstColumnsWiderThanTheWidthItWasGiven(): void
    {
        $table = Table::fromCells(
            [
                Column::new('left', Constraint::min(10)),
                Column::new('right', Constraint::min(10)),
            ],
            [['0123456789', '0123456789']],
        );

        self::assertSame(2, $table->measureHeight(20));
        self::assertSame(2, $table->measureHeight(15), 'The columns keep their floors, so no cell wraps into the width that is missing');
    }

    private static function areaOf(int $extent): Rect
    {
        return new Rect(3, 1, $extent, 4);
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return list<int>
     */
    private function drawnWidths(array $constraints, int $extent): array
    {
        $area = self::areaOf($extent);
        $frame = $this->drawn($constraints, $area);
        $widths = [];

        foreach (array_keys($constraints) as $index) {
            $glyph = self::GLYPHS[$index];
            $drawn = 0;

            for ($x = 0; $x < self::FRAME_WIDTH; ++$x) {
                if ($frame->characters[$area->y * self::FRAME_WIDTH + $x] === $glyph) {
                    ++$drawn;
                }
            }

            $widths[] = $drawn;
        }

        return $widths;
    }

    /**
     * @param list<Constraint> $constraints
     */
    private function drawn(array $constraints, Rect $area): Frame
    {
        $frame = new Frame(self::FRAME_WIDTH, 8);
        $this->table($constraints)->render($frame, $area, new RenderContext(Theme::dark()));

        return $frame;
    }

    /**
     * @param list<Constraint> $constraints
     */
    private function table(array $constraints): Table
    {
        $columns = [];
        $cells = [];

        foreach ($constraints as $index => $constraint) {
            $columns[] = Column::new(str_repeat(self::GLYPHS[$index], self::FRAME_WIDTH), $constraint);
            $cells[] = '';
        }

        return Table::fromCells($columns, [$cells]);
    }
}
