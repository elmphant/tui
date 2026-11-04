<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Prose\Text;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    #[Test]
    public function newRendersContentWithBaseStyle(): void
    {
        $frame = new Frame(10, 1);
        $theme = Theme::dark();
        $context = new RenderContext($theme);

        Text::new('Hello')->render($frame, new Rect(0, 0, 10, 1), $context);

        self::assertSame('H', $frame->characters[0]);
        self::assertSame('e', $frame->characters[1]);
        self::assertSame($theme->base->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function aSpanCutShortEndsTheTextSoALaterSpanNeverFillsTheGap(): void
    {
        $frame = new Frame(10, 1);

        Text::line('a', '世', 'b')->render($frame, new Rect(0, 0, 2, 1), new RenderContext(Theme::dark()));

        self::assertSame('a', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[1]);
    }

    #[Test]
    public function styledAppliesExplicitStyle(): void
    {
        $frame = new Frame(10, 1);
        $context = new RenderContext(Theme::dark());
        $style = new Style(foreground: Color::ansi(AnsiColor::Yellow), attributes: Attr::ITALIC);

        Text::styled('AB', $style)->render($frame, new Rect(0, 0, 10, 1), $context);

        self::assertSame('A', $frame->characters[0]);
        self::assertSame($style->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame(Attr::ITALIC, $frame->attributes[0]);
    }

    #[Test]
    public function aTokenTextTakesItsColourFromTheThemeItIsDrawnIn(): void
    {
        foreach ([Theme::dark(), Theme::dracula()] as $theme) {
            $expected = [
                [StyleToken::Dim, $theme->dim],
                [StyleToken::Accent, $theme->accent],
                [StyleToken::Error, $theme->error],
                [StyleToken::Success, $theme->success],
            ];

            foreach ($expected as [$token, $style]) {
                $frame = new Frame(10, 1);

                Text::token('x', $token)->render($frame, new Rect(0, 0, 10, 1), new RenderContext($theme));

                self::assertSame('x', $frame->characters[0]);
                self::assertSame($style->foregroundValue(), $frame->foregrounds[0], $token->name);
            }
        }
    }

    #[Test]
    public function aBoldStyleDrawsTheTextBold(): void
    {
        $frame = new Frame(10, 1);

        Text::styled('x', (new Style())->bold())->render($frame, new Rect(0, 0, 10, 1), new RenderContext(Theme::dark()));

        self::assertSame('x', $frame->characters[0]);
        self::assertSame(Attr::BOLD, $frame->attributes[0]);
    }

    #[Test]
    public function renderSkipsWhenHeightIsZero(): void
    {
        $frame = new Frame(10, 1);
        $context = new RenderContext(Theme::dark());

        Text::new('Hello')->render($frame, new Rect(0, 0, 10, 0), $context);

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function renderSkipsWhenWidthIsZero(): void
    {
        $frame = new Frame(10, 1);
        $context = new RenderContext(Theme::dark());

        Text::new('Hello')->render($frame, new Rect(0, 0, 0, 1), $context);

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function lineRendersSpansWithoutFallingThroughToPlainContent(): void
    {
        $frame = new Frame(10, 1);
        $theme = Theme::dark();
        $context = new RenderContext($theme);
        $style = new Style(foreground: Color::ansi(AnsiColor::Red));

        Text::line(Span::styled('AB', $style))->render($frame, new Rect(0, 0, 10, 1), $context);

        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame($style->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame(' ', $frame->characters[2]);
    }

    #[Test]
    public function spansBeyondAreaWidthAreNotRendered(): void
    {
        $frame = new Frame(10, 1);
        $context = new RenderContext(Theme::dark());

        Text::line('ABC', 'DEF')->render($frame, new Rect(0, 0, 3, 1), $context);

        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame('C', $frame->characters[2]);
        self::assertSame(' ', $frame->characters[3]);
    }

    #[Test]
    public function styledSpanAdvancesXForNextSpan(): void
    {
        $frame = new Frame(10, 1);
        $theme = Theme::dark();
        $context = new RenderContext($theme);
        $redStyle = new Style(foreground: Color::ansi(AnsiColor::Red));
        $blueStyle = new Style(foreground: Color::ansi(AnsiColor::Blue));

        Text::line(
            Span::styled('AB', $redStyle),
            Span::styled('CD', $blueStyle),
        )->render($frame, new Rect(2, 0, 8, 1), $context);

        self::assertSame('A', $frame->characters[2]);
        self::assertSame('B', $frame->characters[3]);
        self::assertSame($redStyle->foregroundValue(), $frame->foregrounds[2]);
        self::assertSame('C', $frame->characters[4]);
        self::assertSame('D', $frame->characters[5]);
        self::assertSame($blueStyle->foregroundValue(), $frame->foregrounds[4]);
    }

    #[Test]
    public function stringSpanAdvancesXForNextSpan(): void
    {
        $frame = new Frame(10, 1);
        $theme = Theme::dark();
        $context = new RenderContext($theme);
        $accentStyle = new Style(foreground: Color::ansi(AnsiColor::Green));

        Text::line(
            'AB',
            Span::styled('CD', $accentStyle),
        )->render($frame, new Rect(2, 0, 8, 1), $context);

        self::assertSame('A', $frame->characters[2]);
        self::assertSame('B', $frame->characters[3]);
        self::assertSame($theme->base->foregroundValue(), $frame->foregrounds[2]);
        self::assertSame('C', $frame->characters[4]);
        self::assertSame('D', $frame->characters[5]);
        self::assertSame($accentStyle->foregroundValue(), $frame->foregrounds[4]);
    }

    #[Test]
    public function multipleStringSpansAdvanceXCorrectly(): void
    {
        $frame = new Frame(10, 1);
        $theme = Theme::dark();
        $context = new RenderContext($theme);

        Text::line('AB', 'CD')->render($frame, new Rect(2, 0, 8, 1), $context);

        self::assertSame('A', $frame->characters[2]);
        self::assertSame('B', $frame->characters[3]);
        self::assertSame('C', $frame->characters[4]);
        self::assertSame('D', $frame->characters[5]);
    }

    #[Test]
    public function aTitleTokenDrawsInTheThemesTitleStyle(): void
    {
        $frame = new Frame(10, 1);
        $theme = Theme::dark();

        Text::token('Ti', StyleToken::Title)->render($frame, new Rect(0, 0, 10, 1), new RenderContext($theme));

        self::assertSame('T', $frame->characters[0]);
        self::assertSame($theme->titleStyle->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame($theme->titleStyle->attributes, $frame->attributes[0]);
    }

    #[Test]
    public function aSpanIsClippedToTheAreaEvenWhenTheAreaStartsAwayFromTheOrigin(): void
    {
        $frame = new Frame(12, 1);
        $context = new RenderContext(Theme::dark());

        Text::line('ABCDEFGH')->render($frame, new Rect(3, 0, 4, 1), $context);

        self::assertSame('D', $frame->characters[6]);
        self::assertSame(' ', $frame->characters[7]);
    }

    #[Test]
    public function aSecondSpanIsClippedAtTheAreaEdge(): void
    {
        $frame = new Frame(12, 1);
        $context = new RenderContext(Theme::dark());

        Text::line('AB', 'CDEFGH')->render($frame, new Rect(2, 0, 5, 1), $context);

        self::assertSame('E', $frame->characters[6]);
        self::assertSame(' ', $frame->characters[7]);
    }

    #[Test]
    public function aBrandColouredSpanAndAThemedSpanShareOneLine(): void
    {
        $frame = new Frame(10, 1);
        $theme = Theme::dark();
        $context = new RenderContext($theme);
        $brand = new Style(foreground: Color::hex('FF8700'));

        Text::line(
            Span::styled('AB', $brand),
            Span::token('CD', StyleToken::Title)->bold(),
            Span::token('EF', StyleToken::Base),
        )->render($frame, new Rect(0, 0, 10, 1), $context);

        self::assertSame($brand->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame($theme->titleStyle->foregroundValue(), $frame->foregrounds[2]);
        self::assertSame(Attr::BOLD, $frame->attributes[2]);
        self::assertSame($theme->base->foregroundValue(), $frame->foregrounds[4]);
    }

    #[Test]
    public function aTokenSpanFollowsWhicheverThemeRendersIt(): void
    {
        $line = Text::line(Span::token('AB', StyleToken::Accent));

        $dark = new Frame(4, 1);
        $line->render($dark, new Rect(0, 0, 4, 1), new RenderContext(Theme::dark()));

        $light = new Frame(4, 1);
        $line->render($light, new Rect(0, 0, 4, 1), new RenderContext(Theme::light()));

        self::assertSame(Theme::dark()->accent->foregroundValue(), $dark->foregrounds[0]);
        self::assertSame(Theme::light()->accent->foregroundValue(), $light->foregrounds[0]);
    }

    #[Test]
    public function aLineAsksForTheWidthOfAllItsSpans(): void
    {
        self::assertSame(7, Text::line('ab', Span::token('cde', StyleToken::Accent), 'fg')->sizing()->columns->min);
    }

    #[Test]
    public function renderTruncatesContentToAreaWidth(): void
    {
        $frame = new Frame(15, 1);
        $context = new RenderContext(Theme::dark());

        Text::new('Hello World Long Text')->render($frame, new Rect(0, 0, 10, 1), $context);

        self::assertSame(' ', $frame->characters[10]);
    }
}
