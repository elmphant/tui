<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Invariant;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Flex;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\VerticalStack;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Support\KeyedCache;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Table;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AllocatorDivergenceTest extends TestCase
{
    use RectInvariants;

    #[Test]
    public function aStackFillsItsAxisWhenItsLastGrowingChildIsCapped(): void
    {
        $constraints = [Constraint::min(5), Constraint::min(0), Constraint::flex(2), new Constraint(2, 6, 1)];

        $heights = VerticalStack::layoutUsing(new KeyedCache(), self::childrenFor($constraints), 40, 80)->heights;

        self::assertSame(80, array_sum($heights), 'The share the capped child could not take is offered to the others');
        self::assertSame(80, array_sum(Layout::vertical(...self::childrenFor($constraints))->sizesFor(80)), 'A layout column fills it the same way');
    }

    #[Test]
    public function aStackLeavesAChildWithNeitherWeightNorFloorEmptyJustAsALayoutColumnDoes(): void
    {
        $children = [
            new ConstrainedWidget(Constraint::min(0)),
            new ConstrainedWidget(Constraint::exact(2)),
        ];

        self::assertSame([0, 2], VerticalStack::layoutUsing(new KeyedCache(), $children, 40, 10)->heights);
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
        $table = Table\Table::fromCells(
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
    public function aColumnGivesItsChildrenTheHeightItSaidTheyNeeded(): void
    {
        $children = [self::paragraphOf(3), self::paragraphOf(7)];
        $column = Layout::vertical(...$children);

        $heights = self::extentsAlong(
            self::rectsOf($column->computeChildRects(new Rect(0, 0, 40, 10))),
            Direction::Vertical,
        );

        self::assertSame(10, $column->measureHeight(40), 'Three lines and seven need ten rows');
        self::assertSame([3, 7], $heights, 'and in ten rows that is what each one gets');
    }

    #[Test]
    public function aContentSizedChildTakesItsLinesAndLeavesTheRestToItsSibling(): void
    {
        $column = Layout::vertical(
            self::paragraphOf(3),
            self::paragraphOf(7)->withSizing(Sizing::of(Constraint::flex(), Constraint::flex())),
        );

        $heights = self::extentsAlong(
            self::rectsOf($column->computeChildRects(new Rect(0, 0, 40, 20))),
            Direction::Vertical,
        );

        self::assertSame([3, 17], $heights);
    }

    #[Test]
    public function aColumnShortOfRowsSharesThemOutWhileAScrollingStackKeepsEveryLine(): void
    {
        $children = [self::paragraphOf(3), self::paragraphOf(3)];

        $heights = self::extentsAlong(
            self::rectsOf(Layout::vertical(...$children)->computeChildRects(new Rect(0, 0, 40, 4))),
            Direction::Vertical,
        );

        self::assertSame([2, 2], $heights, 'A column has to fit the area it was given');
        self::assertSame([3, 3], VerticalStack::layoutUsing(new KeyedCache(), $children, 40, 4)->heights, 'A stack scrolls instead');
    }

    #[Test]
    public function aChildTallerThanTheColumnStillLeavesAFixedSiblingItsRows(): void
    {
        $column = Layout::vertical(self::paragraphOf(30), Text::new('footer'));

        $heights = self::extentsAlong(
            self::rectsOf($column->computeChildRects(new Rect(0, 0, 40, 10))),
            Direction::Vertical,
        );

        self::assertSame([9, 1], $heights);
    }

    #[Test]
    public function aFlexColumnGivesTheSameTwoParagraphsTheHeightTheirLinesNeed(): void
    {
        $column = Flex::column()
            ->add(self::paragraphOf(3))
            ->add(self::paragraphOf(7));

        $heights = self::extentsAlong(
            self::rectsOf($column->computeChildRects(new Rect(0, 0, 40, 10))),
            Direction::Vertical,
        );

        self::assertSame([3, 7], $heights);
    }

    #[Test]
    public function aStackGivesTheSameTwoParagraphsTheHeightTheirLinesNeed(): void
    {
        $children = [self::paragraphOf(3), self::paragraphOf(7)];

        self::assertSame([3, 7], VerticalStack::layoutUsing(new KeyedCache(), $children, 40, 10)->heights);
    }

    /**
     * @param list<Constraint> $constraints
     *
     * @return list<Widget>
     */
    private static function childrenFor(array $constraints): array
    {
        return array_map(static fn (Constraint $constraint): Widget => new ConstrainedWidget($constraint), $constraints);
    }

    private static function paragraphOf(int $lines): Paragraph
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
