<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Table;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class APaneInsideAPaneTest extends TestCase
{
    #[Test]
    public function aTallPaneInsideAPaneScrollsItselfAndFitsTheRoomLeft(): void
    {
        $details = Panel::new('Details')->withChildren(array_map(static fn (int $row): Text => Text::new("line $row"), range(1, 30)));
        $page = Panel::new('Page')->withChildren([Text::new('title'), $details]);
        $frame = new Frame(30, 12);

        $page->render($frame, Rect::fromSize(30, 12), RenderContext::unfocused(Theme::dark()));
        $screen = ScreenBuffer::fromFrame($frame);

        self::assertSame(1, $screen->rowOf('title'));
        self::assertStringStartsWith('│╰', $screen->lineAt(10), 'the details pane ends on screen, one row above the page');
    }

    #[Test]
    public function aTallTableInsideAPageScrollsItselfAndLeavesThePageStill(): void
    {
        $table = Table::fromCells([Column::new('Job', Constraint::flex())], array_map(static fn (int $row): array => ["job $row"], range(1, 30)));
        $page = Panel::new('Page')->withChildren([Text::new('title'), $table]);
        $area = Rect::fromSize(30, 12);

        $ended = $page->update(new KeyMsg(Key::End), $area)->model;

        self::assertInstanceOf(Panel::class, $ended);
        self::assertSame(0, $ended->scrollOffset($area), 'the page has nothing to scroll, the table holds its own rows');
    }
}
