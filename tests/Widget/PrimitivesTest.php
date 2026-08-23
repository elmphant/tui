<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Bar\StatusBar;
use Elmphant\Tui\Widget\Separator;
use Elmphant\Tui\Widget\Spacer;
use Elmphant\Tui\Widget\Text;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PrimitivesTest extends TestCase
{
    #[Test]
    public function textConstraintIsExactOne(): void
    {
        $text = Text::new('Hello');
        $constraint = $text->sizing()->rows;
        self::assertSame(1, $constraint->min);
        self::assertSame(1, $constraint->max);
    }

    #[Test]
    public function textRendersContent(): void
    {
        $frame = new Frame(20, 5);
        $context = new RenderContext(Theme::dark());
        $text = Text::new('Hello');
        $text->render($frame, new Rect(2, 1, 18, 1), $context);
        self::assertSame('H', $frame->characters[1 * 20 + 2]);
        self::assertSame('o', $frame->characters[1 * 20 + 6]);
    }

    #[Test]
    public function textStyledAppliesColorAndAttr(): void
    {
        $frame = new Frame(20, 5);
        $context = new RenderContext(Theme::dark());
        $style = new Style(foreground: Color::ansi(1), attributes: Attr::BOLD);
        $text = Text::styled('Hi', $style);
        $text->render($frame, new Rect(0, 0, 20, 1), $context);
        self::assertSame($style->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame(Attr::BOLD, $frame->attributes[0]);
    }

    #[Test]
    public function spacerConstraintIsExactOne(): void
    {
        $spacer = Spacer::new();
        self::assertSame(1, $spacer->sizing()->rows->min);
    }

    #[Test]
    public function spacerCustomSize(): void
    {
        $spacer = Spacer::of(3);
        self::assertSame(3, $spacer->sizing()->rows->min);
    }

    #[Test]
    public function separatorConstraintIsExactOne(): void
    {
        $separator = Separator::new();
        self::assertSame(1, $separator->sizing()->rows->min);
    }

    #[Test]
    public function separatorRendersLine(): void
    {
        $frame = new Frame(10, 3);
        $context = new RenderContext(Theme::dark());
        $separator = Separator::new();
        $separator->render($frame, new Rect(1, 1, 8, 1), $context);
        self::assertSame('─', $frame->characters[1 * 10 + 1]);
        self::assertSame('─', $frame->characters[1 * 10 + 8]);
    }

    #[Test]
    public function statusBarConstraintIsExactOne(): void
    {
        $statusBar = StatusBar::new('help text');
        self::assertSame(1, $statusBar->sizing()->rows->min);
    }

    #[Test]
    public function statusBarRendersFullWidth(): void
    {
        $frame = new Frame(20, 3);
        $context = new RenderContext(Theme::dark());
        $statusBar = StatusBar::new('q:quit');
        $statusBar->render($frame, new Rect(0, 2, 20, 1), $context);
        self::assertSame('q', $frame->characters[2 * 20 + 0]);
    }

    #[Test]
    public function lineRendersMultipleSpans(): void
    {
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());
        $redStyle = new Style(foreground: Color::ansi(1));

        $text = Text::line(Span::styled('AB', $redStyle), 'CD');
        $text->render($frame, new Rect(0, 0, 20, 1), $context);

        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame('C', $frame->characters[2]);
        self::assertSame('D', $frame->characters[3]);
        self::assertSame($redStyle->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame($redStyle->foregroundValue(), $frame->foregrounds[1]);
    }

    #[Test]
    public function lineConstraintIsExactOne(): void
    {
        $text = Text::line('hello', Span::styled('world', new Style()));
        self::assertSame(1, $text->sizing()->rows->min);
        self::assertSame(1, $text->sizing()->rows->max);
    }

    #[Test]
    public function lineWithOnlyStringsUsesBaseStyle(): void
    {
        $frame = new Frame(20, 1);
        $theme = Theme::dark();
        $context = new RenderContext($theme);

        $text = Text::line('AB');
        $text->render($frame, new Rect(0, 0, 20, 1), $context);

        self::assertSame('A', $frame->characters[0]);
        self::assertSame($theme->base->foregroundValue(), $frame->foregrounds[0]);
    }
}
