<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\WidgetHarness;
use Elmphant\Tui\Widget\Field\TextArea;
use Elmphant\Tui\Widget\ValueItCannotHold;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextAreaTest extends TestCase
{
    #[Test]
    public function enterStartsANewLine(): void
    {
        $area = WidgetHarness::for(TextArea::new(), width: 20, height: 4)->type('ab')->press('enter')->type('cd');

        self::assertSame("ab\ncd", $area->widget()->value());
        self::assertSame(1, $area->screen()->rowOf('cd'));
    }

    #[Test]
    public function typingInTheMiddleOfALineInsertsThere(): void
    {
        $area = WidgetHarness::for(TextArea::new('heo'), width: 20, height: 4)->press('home', 'right', 'right')->type('ll');

        self::assertSame('hello', $area->widget()->value());
    }

    #[Test]
    public function enterInTheMiddleOfALineSplitsIt(): void
    {
        $area = WidgetHarness::for(TextArea::new('onetwo'), width: 20, height: 4)->press('left', 'left', 'left', 'enter');

        self::assertSame("one\ntwo", $area->widget()->value());
    }

    #[Test]
    public function backspaceAtTheStartOfALineJoinsItToTheOneAbove(): void
    {
        $area = WidgetHarness::for(TextArea::new("one\ntwo"), width: 20, height: 4)->press('home', 'backspace');

        self::assertSame('onetwo', $area->widget()->value());
        self::assertSame([0, 3], $area->widget()->cursor());
    }

    #[Test]
    public function deleteAtTheEndOfALineJoinsTheNextOne(): void
    {
        $area = WidgetHarness::for(TextArea::new("one\ntwo"), width: 20, height: 4)->press('up', 'end', 'delete');

        self::assertSame('onetwo', $area->widget()->value());
    }

    #[Test]
    public function upAndDownKeepTheColumnTheCursorWasIn(): void
    {
        $area = WidgetHarness::for(TextArea::new("hello\nhi\nworld"), width: 20, height: 4)->press('ctrl+home', 'end', 'left');

        $area->press('down');
        self::assertSame([1, 2], $area->widget()->cursor());

        $area->press('down');
        self::assertSame([2, 4], $area->widget()->cursor());
    }

    #[Test]
    public function aWideCharacterMovesTheCaretTwoColumns(): void
    {
        $frame = new Frame(10, 2);

        TextArea::new('日本')->withCursor(0, 1)->render($frame, new Rect(0, 0, 10, 2), RenderContext::focused(Theme::dark()));

        self::assertSame(2, $frame->cursorPosition()?->x);
    }

    #[Test]
    public function theViewFollowsTheCursorDownALongText(): void
    {
        $lines = implode("\n", array_map(static fn (int $line): string => "line {$line}", range(0, 9)));

        $area = WidgetHarness::for(TextArea::new($lines), width: 20, height: 3)->press('ctrl+end');

        self::assertNotNull($area->screen()->find('line 9'));
        self::assertNull($area->screen()->find('line 0'));
    }

    #[Test]
    public function aSmallerAreaStillShowsTheCursor(): void
    {
        $lines = implode("\n", array_map(static fn (int $line): string => "line {$line}", range(0, 9)));
        $text = TextArea::new($lines)->withCursor(9, 0);
        $frame = new Frame(20, 2);

        $text->render($frame, new Rect(0, 0, 20, 2), RenderContext::focused(Theme::dark()));

        self::assertSame(1, $frame->cursorPosition()?->y);
    }

    #[Test]
    public function aLongLineScrollsSidewaysWithTheCursor(): void
    {
        $area = WidgetHarness::for(TextArea::new(str_repeat('a', 30) . 'END'), width: 10, height: 2);

        self::assertNotNull($area->screen()->find('END'));
    }

    #[Test]
    public function pastedLinesAreInsertedAsLines(): void
    {
        $area = WidgetHarness::for(TextArea::new('[]'), width: 20, height: 4)->press('left')->paste("one\r\n\ttwo");

        self::assertSame("[one\n    two]", $area->widget()->value());
        self::assertSame([1, 7], $area->widget()->cursor());
    }

    #[Test]
    public function aClickPutsTheCursorWhereItLands(): void
    {
        $area = WidgetHarness::for(TextArea::new("first\nsecond"), width: 20, height: 4)->click('second', offset: 3);

        self::assertSame([1, 3], $area->widget()->cursor());
    }

    #[Test]
    public function anUnfocusedTextReadsAsTextNotAsAPlaceholder(): void
    {
        $frame = new Frame(10, 2);
        $theme = Theme::dark();

        TextArea::new('ab')->render($frame, new Rect(0, 0, 10, 2), new RenderContext($theme));

        self::assertSame($theme->base->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function anEmptyAreaShowsItsPlaceholder(): void
    {
        $area = WidgetHarness::for(TextArea::new()->withPlaceholder('request body'), width: 20, height: 4);

        self::assertSame(0, $area->screen()->rowOf('request body'));
    }

    #[Test]
    public function itHoldsOnlyText(): void
    {
        self::assertSame("a\nb", TextArea::new()->withValue("a\nb")->value());

        $this->expectException(ValueItCannotHold::class);
        TextArea::new()->withValue(true);
    }
}
