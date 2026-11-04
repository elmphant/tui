<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Invariant;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Table;
use Elmphant\Tui\Widget\Table\Column;
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
    public function aTableDrawsTheColumnsARowOfTheSameConstraintsAllocatesAsFarAsTheAreaReaches(array $constraints, int $extent): void
    {
        $row = Layout::row(...array_map(
            static fn (Constraint $constraint): ConstrainedWidget => new ConstrainedWidget($constraint),
            $constraints,
        ))->withGap(1);
        $remaining = $extent;
        $expected = [];
        $gap = self::gapIn($constraints, $extent);

        foreach ($row->sizesFor($extent) as $width) {
            $fits = min($width, $remaining);
            $expected[] = $fits;
            $remaining -= $fits + min($gap, $remaining - $fits);
        }

        self::assertSame($expected, $this->drawnWidths($constraints, $extent));
    }

    #[Test]
    #[DataProviderExternal(ConstraintSweep::class, 'cases')]
    public function theSameTableDrawsTheSameColumnsTwice(array $constraints, int $extent): void
    {
        self::assertSame($this->drawnWidths($constraints, $extent), $this->drawnWidths($constraints, $extent));
    }

    #[Test]
    public function aTableMeasuresItsHeightAgainstColumnsWiderThanTheWidthItWasGiven(): void
    {
        $table = Table\Table::fromCells(
            [
                Column::new('left', Constraint::min(10)),
                Column::new('right', Constraint::min(10)),
            ],
            [['0123456789', '0123456789']],
        );

        self::assertSame(2, $table->measureHeight(20));
        self::assertSame(2, $table->measureHeight(15), 'The columns keep their floors, so no cell wraps into the width that is missing');
    }

    /**
     * The one cell between columns gives way only where the gaps alone would not fit.
     *
     * @param list<Constraint> $constraints
     */
    private static function gapIn(array $constraints, int $extent): int
    {
        return count($constraints) < 2 ? 0 : min(1, intdiv($extent, count($constraints) - 1));
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
    private function table(array $constraints): Table\Table
    {
        $columns = [];
        $cells = [];

        foreach ($constraints as $index => $constraint) {
            $columns[] = Column::new(str_repeat(self::GLYPHS[$index], self::FRAME_WIDTH), $constraint);
            $cells[] = '';
        }

        return Table\Table::fromCells($columns, [$cells]);
    }
}
