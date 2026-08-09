<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Invariant;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\KeyedCache;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\VerticalStack;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Column;
use Elmphant\Tui\Widget\Paragraph;
use Elmphant\Tui\Widget\Table;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AllocatorDivergenceTest extends TestCase
{
    use RectInvariants;

    #[Test]
    public function aStackGrowsAChildThatHasNeitherWeightNorFloor(): void
    {
        $children = [
            new ConstrainedWidget(Constraint::min(0)),
            new ConstrainedWidget(Constraint::exact(2)),
        ];

        self::assertSame([8, 2], VerticalStack::layoutUsing(new KeyedCache(), $children, 40, 10)->heights);
    }

    #[Test]
    public function aLayoutColumnLeavesAChildThatHasNeitherWeightNorFloorEmpty(): void
    {
        $column = Layout::vertical(
            new ConstrainedWidget(Constraint::min(0)),
            new ConstrainedWidget(Constraint::exact(2)),
        );

        $heights = self::extentsAlong(
            self::rectsOf($column->computeChildRects(new Rect(0, 0, 40, 10))),
            Direction::Vertical,
        );

        self::assertSame([0, 2], $heights);
    }

    #[Test]
    public function aTableLeavesAColumnThatHasNeitherWeightNorFloorEmpty(): void
    {
        $table = Table::new(
            [
                Column::new(str_repeat('A', 40), Constraint::min(0)),
                Column::new(str_repeat('B', 40), Constraint::exact(2)),
            ],
            [['', '']],
        );

        $frame = new Frame(40, 6);
        $table->render($frame, new Rect(0, 0, 10, 4), new RenderContext(Theme::dark()));

        self::assertSame(0, $this->countOnFirstRow($frame, 'A'));
        self::assertSame(2, $this->countOnFirstRow($frame, 'B'));
    }

    #[Test]
    public function aLayoutColumnSplitsTwoParagraphsByWeightAndIgnoresTheirLines(): void
    {
        $column = Layout::vertical(self::paragraphOf(3), self::paragraphOf(7));

        $heights = self::extentsAlong(
            self::rectsOf($column->computeChildRects(new Rect(0, 0, 40, 10))),
            Direction::Vertical,
        );

        self::assertSame([5, 5], $heights);
    }

    #[Test]
    public function aStackGivesTheSameTwoParagraphsTheHeightTheirLinesNeed(): void
    {
        $children = [self::paragraphOf(3), self::paragraphOf(7)];

        self::assertSame([3, 7], VerticalStack::layoutUsing(new KeyedCache(), $children, 40, 10)->heights);
    }

    private static function paragraphOf(int $lines): Widget
    {
        return Paragraph::fromText(implode("\n", array_fill(0, $lines, 'text')));
    }

    private function countOnFirstRow(Frame $frame, string $glyph): int
    {
        $found = 0;

        for ($x = 0; $x < $frame->width; ++$x) {
            if ($frame->characters[$x] === $glyph) {
                ++$found;
            }
        }

        return $found;
    }
}
