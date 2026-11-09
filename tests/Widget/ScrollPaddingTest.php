<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Testing\UserInput;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\Table;
use Elmphant\Tui\Widget\Tree\SimpleTreeNode;
use Elmphant\Tui\Widget\Tree\TreeView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScrollPaddingTest extends TestCase
{
    #[Test]
    public function aListScrollsBeforeTheCursorReachesTheLastRowInView(): void
    {
        $list = ListView::fromStrings(self::labels(20))->withScrollPadding(1)->withCursor(4);

        self::assertSame(1, $list->scrollOffset(Rect::fromSize(20, 5)));
    }

    #[Test]
    public function aListTooShortForThePaddingStillShowsTheCursor(): void
    {
        $list = ListView::fromStrings(self::labels(20))->withScrollPadding(1)->withCursor(4);

        self::assertSame(4, $list->scrollOffset(Rect::fromSize(20, 1)));
    }

    #[Test]
    public function aListScrollsBackBeforeTheCursorReachesTheFirstRowInView(): void
    {
        $list = ListView::fromStrings(self::labels(20))->withScrollPadding(2)->withScrollOffset(10)->withCursor(11);

        self::assertSame(9, $list->scrollOffset(Rect::fromSize(20, 5)));
    }

    #[Test]
    public function aTreeScrollsBeforeTheCursorReachesTheLastRowInView(): void
    {
        $area = Rect::fromSize(20, 5);
        $tree = TreeView::new(array_map(static fn (string $label): SimpleTreeNode => new SimpleTreeNode($label, $label), self::labels(20)))
            ->withScrollPadding(1);

        for ($press = 0; $press < 4; ++$press) {
            $tree = $tree->update(UserInput::key('down'), $area)->model;
        }

        $frame = new Frame(20, 5);
        $tree->render($frame, $area, RenderContext::unfocused(Theme::dark()));
        self::assertStringContainsString('item 1', ScreenBuffer::fromFrame($frame)->lineAt(0));
    }

    #[Test]
    public function aTableScrollsBeforeTheCursorReachesTheLastRowInView(): void
    {
        $table = self::table(20)->withScrollPadding(1)->withCursor(4);

        self::assertSame(1, $table->scrollOffset(Rect::fromSize(20, 6)));
    }

    #[Test]
    public function aTableTooShortForThePaddingStillShowsTheCursor(): void
    {
        $table = self::table(20)->withScrollPadding(1)->withCursor(4);

        self::assertSame(4, $table->scrollOffset(Rect::fromSize(20, 2)));
    }

    #[Test]
    public function aTableScrollsBackBeforeTheCursorReachesTheFirstRowInView(): void
    {
        $table = self::table(20)->withScrollPadding(2)->withScrollOffset(10)->withCursor(11);

        self::assertSame(9, $table->scrollOffset(Rect::fromSize(20, 6)));
    }

    private static function table(int $rows): Table
    {
        return Table::new([Column::new('Name', Constraint::flex())], array_map(static fn (string $label): Row => Row::new($label), self::labels($rows)));
    }

    /**
     * @return list<string>
     */
    private static function labels(int $count): array
    {
        return array_map(static fn (int $index): string => "item {$index}", range(0, $count - 1));
    }
}
