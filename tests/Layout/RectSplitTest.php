<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Text;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RectSplitTest extends TestCase
{
    #[Test]
    public function itSplitsIntoEqualColumnsWithTheLeftoverCellsAtTheRear(): void
    {
        self::assertEquals(
            [new Rect(2, 1, 3, 4), new Rect(5, 1, 3, 4), new Rect(8, 1, 4, 4)],
            (new Rect(2, 1, 10, 4))->split(Direction::Horizontal, 3),
        );
    }

    #[Test]
    public function aGapIsLeftBetweenNeighbours(): void
    {
        self::assertEquals(
            [new Rect(0, 0, 2, 4), new Rect(3, 0, 3, 4), new Rect(7, 0, 3, 4)],
            Rect::fromSize(10, 4)->split(Direction::Horizontal, 3, gap: 1),
        );
    }

    #[Test]
    public function aNegativeGapIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A gap must be non-negative, got -1');

        Rect::fromSize(20, 1)->split(Direction::Horizontal, 3, gap: -1);
    }

    #[Test]
    public function itSplitsIntoEqualRowsDownwards(): void
    {
        self::assertEquals(
            [new Rect(0, 0, 6, 3), new Rect(0, 3, 6, 4)],
            Rect::fromSize(6, 7)->split(Direction::Vertical, 2),
        );
    }

    #[Test]
    public function itSharesTheRoomTheWayARowOfGrowingChildrenDoes(): void
    {
        $area = Rect::fromSize(23, 2);
        $children = [Text::new('a'), Text::new('b'), Text::new('c'), Text::new('d')];
        $row = Layout::row()->withGap(2);
        foreach ($children as $child) {
            $row = $row->add($child, Constraint::flex());
        }

        $placed = array_map(static fn (array $placement): Rect => $placement[1], [...$row->placedChildren($area)]);

        self::assertEquals($placed, $area->split(Direction::Horizontal, 4, gap: 2));
    }

    #[Test]
    public function splittingIntoNothingGivesNothing(): void
    {
        self::assertSame([], Rect::fromSize(10, 4)->split(Direction::Horizontal, 0));
    }
}
