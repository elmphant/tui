<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Geometry;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Layout\Breakpoint;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Text;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BreakpointTest extends TestCase
{
    #[Test]
    public function aWideAreaGetsTheWideArrangementAndANarrowOneTheNarrow(): void
    {
        $breakpoint = Breakpoint::at(20, narrow: Text::new('NARROW'), wide: Text::new('WIDE'));

        self::assertStringContainsString('WIDE', self::drawn($breakpoint, 20, 2));
        self::assertStringContainsString('NARROW', self::drawn($breakpoint, 19, 2));
    }

    #[Test]
    public function aComponentInBothArrangementsIsFoundWhereverTheWidthPutIt(): void
    {
        $list = Component::named('list', Text::new('list'));
        $details = Component::named('details', Text::new('details'));
        $breakpoint = Breakpoint::at(
            40,
            narrow: Layout::column()->add($list, Constraint::flex())->add($details, Constraint::flex()),
            wide: Layout::row()->add($list, Constraint::flex())->add($details, Constraint::flex()),
        );

        self::assertEquals(new Rect(20, 0, 20, 10), Geometry::of($breakpoint, Rect::fromSize(40, 10))->rectOf('details'));
        self::assertEquals(new Rect(0, 5, 30, 5), Geometry::of($breakpoint, Rect::fromSize(30, 10))->rectOf('details'));
    }

    #[Test]
    public function itMeasuresTheArrangementTheWidthWouldDraw(): void
    {
        $breakpoint = Breakpoint::at(20, narrow: Paragraph::fromText("one\ntwo\nthree"), wide: Text::new('wide'));

        self::assertSame(3, $breakpoint->measureHeight(10));
        self::assertSame(1, $breakpoint->measureHeight(30));
    }

    private static function drawn(Breakpoint $breakpoint, int $width, int $height): string
    {
        $frame = new Frame($width, $height);
        $breakpoint->render($frame, Rect::fromSize($width, $height), RenderContext::unfocused(Theme::dark()));

        return ScreenBuffer::fromFrame($frame)->plainText();
    }
}
