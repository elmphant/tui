<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Text\WrapCache;
use Elmphant\Tui\Widget\Alignment;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Wrap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParagraphTest extends TestCase
{
    #[Test]
    public function reportsTheWidthItsLongestLineNeeds(): void
    {
        $paragraph = Paragraph::fromText("short\na much longer line\nmid");

        self::assertSame(18, $paragraph->sizing()->columns->basis);
    }

    #[Test]
    public function plainTextBreaksWhereTextWithAnyOtherCharacterWouldBreak(): void
    {
        $texts = ['ab cd ef', 'abcdefghijkl', '  lead and  double  ', 'word ' . str_repeat('x', 13) . ' y', 'abcd efgh ijkl ', ' ', 'ab   cd'];

        foreach ($texts as $text) {
            foreach (range(1, 9) as $width) {
                WrapCache::clear();
                $plain = Paragraph::fromText($text);
                $mixed = Paragraph::fromText($text . "\u{200B}");
                self::assertSame($mixed->measureHeight($width), $plain->measureHeight($width), "'{$text}' at {$width}");
                self::assertSame($this->rowsOf($mixed, $width, 30), $this->rowsOf($plain, $width, 30), "'{$text}' at {$width}");
            }
        }
    }

    #[Test]
    public function theLinesCanBeReplacedAfterConstruction(): void
    {
        $frame = new Frame(10, 2);
        $paragraph = Paragraph::fromLines([new Line([Span::raw('old')])])
            ->withLines([new Line([Span::raw('new')])]);

        $paragraph->render($frame, new Rect(0, 0, 10, 2), new RenderContext(Theme::dark()));

        self::assertSame('n', $frame->characters[0]);
        self::assertSame('e', $frame->characters[1]);
        self::assertSame('w', $frame->characters[2]);
    }

    #[Test]
    public function fromTextRendersSingleLine(): void
    {
        $frame = new Frame(10, 3);
        $paragraph = Paragraph::fromText('Hello');

        $paragraph->render($frame, new Rect(0, 0, 10, 3), $this->context());

        self::assertSame('H', $frame->characters[0]);
        self::assertSame('e', $frame->characters[1]);
        self::assertSame('l', $frame->characters[2]);
        self::assertSame('l', $frame->characters[3]);
        self::assertSame('o', $frame->characters[4]);
    }

    #[Test]
    public function fromTextRendersMultipleLines(): void
    {
        $frame = new Frame(10, 3);
        $paragraph = Paragraph::fromText("Line1\nLine2\nLine3");

        $paragraph->render($frame, new Rect(0, 0, 10, 3), $this->context());

        self::assertSame('L', $frame->characters[0]);
        self::assertSame('1', $frame->characters[4]);
        self::assertSame('L', $frame->characters[10]);
        self::assertSame('2', $frame->characters[14]);
        self::assertSame('L', $frame->characters[20]);
        self::assertSame('3', $frame->characters[24]);
    }

    #[Test]
    public function rendersWithBaseStyle(): void
    {
        $frame = new Frame(10, 1);
        $theme = Theme::dark();
        $context = new RenderContext($theme);
        $paragraph = Paragraph::fromText('A');

        $paragraph->render($frame, new Rect(0, 0, 10, 1), $context);

        self::assertSame($theme->base->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function clipsLinesToAreaHeight(): void
    {
        $frame = new Frame(10, 2);
        $paragraph = Paragraph::fromText("Line1\nLine2\nLine3");

        $paragraph->render($frame, new Rect(0, 0, 10, 2), $this->context());

        self::assertSame('L', $frame->characters[0]);
        self::assertSame('L', $frame->characters[10]);
    }

    #[Test]
    public function aParagraphInARoomyStackTakesTheRowsItsTextNeeds(): void
    {
        $placed = Layout::column(Paragraph::fromText("a\nb\nc"), Paragraph::fromText("d\ne"))
            ->computeChildRects(new Rect(0, 0, 80, 20));

        self::assertSame([3, 2], array_map(static fn (array $pair): int => $pair[1]->height, $placed));
    }

    #[Test]
    public function skipsRenderWhenAreaIsEmpty(): void
    {
        $frame = new Frame(10, 1);
        $paragraph = Paragraph::fromText('Hello');

        $paragraph->render($frame, new Rect(0, 0, 0, 0), $this->context());

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function wordWrapBreaksAtWordBoundary(): void
    {
        $frame = new Frame(10, 3);
        $paragraph = Paragraph::fromText('Hello world');

        $paragraph->render($frame, new Rect(0, 0, 10, 3), $this->context());

        self::assertSame('H', $frame->characters[0]);
        self::assertSame('o', $frame->characters[4]);
        self::assertSame('w', $frame->characters[10]);
        self::assertSame('d', $frame->characters[14]);
    }

    #[Test]
    public function charFallbackBreaksLongWordAtLineWidth(): void
    {
        $frame = new Frame(5, 3);
        $paragraph = Paragraph::fromText('ABCDEFGHIJ');

        $paragraph->render($frame, new Rect(0, 0, 5, 3), $this->context());

        self::assertSame('A', $frame->characters[0]);
        self::assertSame('E', $frame->characters[4]);
        self::assertSame('F', $frame->characters[5]);
        self::assertSame('J', $frame->characters[9]);
    }

    #[Test]
    public function noWrapTruncatesAtAreaWidth(): void
    {
        $frame = new Frame(10, 1);
        $paragraph = Paragraph::fromText('Hello world, this is long')
            ->withWrap(Wrap::NoWrap);

        $paragraph->render($frame, new Rect(0, 0, 10, 1), $this->context());

        self::assertSame('H', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[5]);
        self::assertSame('w', $frame->characters[6]);
    }

    #[Test]
    public function anUnwrappedRowEndsAtTheFirstSpanCutShortSoALaterSpanNeverFillsTheGap(): void
    {
        $paragraph = Paragraph::fromLines([new Line([Span::raw('a'), Span::raw('世'), Span::raw('b')])])
            ->withWrap(Wrap::NoWrap);

        self::assertSame(['a'], $this->rowsOf($paragraph, 2, 1));
    }

    #[Test]
    public function wrapTrimsLeadingSpaceOnContinuationLine(): void
    {
        $frame = new Frame(6, 2);
        $paragraph = Paragraph::fromText('Hello world');

        $paragraph->render($frame, new Rect(0, 0, 6, 2), $this->context());

        self::assertSame('H', $frame->characters[0]);
        self::assertSame('w', $frame->characters[6]);
    }

    #[Test]
    public function aBreakingSpaceThatOverflowsIsDroppedRatherThanACarriedCharacter(): void
    {
        $frame = new Frame(3, 3);
        $paragraph = Paragraph::fromText('abc defgh');

        $paragraph->render($frame, new Rect(0, 0, 3, 3), $this->context());
        $screen = ScreenBuffer::fromFrame($frame);

        self::assertSame('abc', trim($screen->lineAt(0)));
        self::assertSame('def', trim($screen->lineAt(1)), 'the overflowed space is dropped, not the f');
        self::assertSame('gh', trim($screen->lineAt(2)));
    }

    #[Test]
    public function wrapPreservesHardLineBreaks(): void
    {
        $frame = new Frame(20, 3);
        $paragraph = Paragraph::fromText("Line one\nLine two\nLine three");

        $paragraph->render($frame, new Rect(0, 0, 20, 3), $this->context());

        self::assertSame('L', $frame->characters[0]);
        self::assertSame('o', $frame->characters[5]);
        self::assertSame('L', $frame->characters[20]);
        self::assertSame('t', $frame->characters[25]);
        self::assertSame('L', $frame->characters[40]);
    }

    #[Test]
    public function centerAlignmentCentersText(): void
    {
        $frame = new Frame(10, 1);
        $paragraph = Paragraph::fromText('Hi')
            ->withAlignment(Alignment::Center);

        $paragraph->render($frame, new Rect(0, 0, 10, 1), $this->context());

        self::assertSame(' ', $frame->characters[3]);
        self::assertSame('H', $frame->characters[4]);
        self::assertSame('i', $frame->characters[5]);
        self::assertSame(' ', $frame->characters[6]);
    }

    #[Test]
    public function rightAlignmentRightAligns(): void
    {
        $frame = new Frame(10, 1);
        $paragraph = Paragraph::fromText('Hi')
            ->withAlignment(Alignment::Right);

        $paragraph->render($frame, new Rect(0, 0, 10, 1), $this->context());

        self::assertSame(' ', $frame->characters[7]);
        self::assertSame('H', $frame->characters[8]);
        self::assertSame('i', $frame->characters[9]);
    }

    #[Test]
    public function leftAlignmentIsDefault(): void
    {
        $frame = new Frame(10, 1);
        $paragraph = Paragraph::fromText('Hi');

        $paragraph->render($frame, new Rect(0, 0, 10, 1), $this->context());

        self::assertSame('H', $frame->characters[0]);
        self::assertSame('i', $frame->characters[1]);
    }

    #[Test]
    public function fromLinesRendersStyledSpans(): void
    {
        $frame = new Frame(10, 1);
        $theme = Theme::dark();
        $context = new RenderContext($theme);
        $redStyle = new Style(foreground: Color::ansi(AnsiColor::Red));

        $paragraph = Paragraph::fromLines([
            new Line([Span::styled('AB', $redStyle), Span::raw('CD')]),
        ]);
        $paragraph->render($frame, new Rect(0, 0, 10, 1), $context);

        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame($redStyle->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame('C', $frame->characters[2]);
        self::assertSame($theme->base->foregroundValue(), $frame->foregrounds[2]);
    }

    #[Test]
    public function aClusterSplitAcrossTwoSpansIsDrawnWholeInTheStyleItStartsIn(): void
    {
        $frame = new Frame(10, 1);
        $red = new Style(foreground: Color::ansi(AnsiColor::Red));
        $blue = new Style(foreground: Color::ansi(AnsiColor::Blue));

        Paragraph::fromLines([new Line([Span::styled('e', $red), Span::styled("\u{0301}x", $blue)])])
            ->render($frame, new Rect(0, 0, 10, 1), new RenderContext(Theme::dark()));

        self::assertSame("e\u{0301}", $frame->characters[0]);
        self::assertSame($red->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame('x', $frame->characters[1]);
        self::assertSame($blue->foregroundValue(), $frame->foregrounds[1]);
    }

    #[Test]
    public function paragraphStyleCascadesToSpans(): void
    {
        $frame = new Frame(10, 1);
        $context = new RenderContext(Theme::dark());
        $greenStyle = new Style(foreground: Color::ansi(AnsiColor::Green));

        $paragraph = Paragraph::fromText('AB')
            ->withStyle($greenStyle);
        $paragraph->render($frame, new Rect(0, 0, 10, 1), $context);

        self::assertSame($greenStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function lineStyleOverridesParagraphStyle(): void
    {
        $frame = new Frame(10, 1);
        $context = new RenderContext(Theme::dark());
        $paragraphStyle = new Style(foreground: Color::ansi(AnsiColor::Red));
        $lineStyle = new Style(foreground: Color::ansi(AnsiColor::Green));

        $paragraph = Paragraph::fromLines([
            new Line([Span::raw('A')], $lineStyle),
        ])->withStyle($paragraphStyle);
        $paragraph->render($frame, new Rect(0, 0, 10, 1), $context);

        self::assertSame($lineStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function spanStyleOverridesLineStyle(): void
    {
        $frame = new Frame(10, 1);
        $context = new RenderContext(Theme::dark());
        $lineStyle = new Style(foreground: Color::ansi(AnsiColor::Red));
        $spanStyle = new Style(foreground: Color::ansi(AnsiColor::Yellow));

        $paragraph = Paragraph::fromLines([
            new Line([Span::styled('A', $spanStyle)], $lineStyle),
        ]);
        $paragraph->render($frame, new Rect(0, 0, 10, 1), $context);

        self::assertSame($spanStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function customSizingOverridesDefault(): void
    {
        $paragraph = Paragraph::fromText('Hello')
            ->withSizing(Sizing::of(Constraint::flex(), Constraint::exact(5)));

        $constraint = $paragraph->sizing()->rows;

        self::assertSame(5, $constraint->min);
        self::assertSame(5, $constraint->max);
    }

    #[Test]
    public function fromMarkdownRendersBoldText(): void
    {
        $frame = new Frame(20, 1);
        $context = $this->context();

        $paragraph = Paragraph::fromMarkdown('Hello **world**');
        $paragraph->render($frame, new Rect(0, 0, 20, 1), $context);

        self::assertSame('H', $frame->characters[0]);
        self::assertSame('w', $frame->characters[6]);
        self::assertSame(Attr::BOLD, $frame->attributes[6] & Attr::BOLD);
    }

    #[Test]
    public function aMarkdownLinkIsALinkTheReaderCanOpen(): void
    {
        $frame = new Frame(20, 1);

        Paragraph::fromMarkdown('Read [the docs](https://example.com/docs)')->render($frame, new Rect(0, 0, 20, 1), $this->context());

        $screen = ScreenBuffer::fromFrame($frame);
        self::assertSame('https://example.com/docs', $screen->styleAt(5, 0)->link);
        self::assertNull($screen->styleAt(0, 0)->link);
    }

    #[Test]
    public function aMarkdownLinkWrappedOntoTheNextLineIsStillALinkThere(): void
    {
        $frame = new Frame(12, 2);

        Paragraph::fromMarkdown('Read [the full docs](https://example.com/docs)')->render($frame, new Rect(0, 0, 12, 2), $this->context());

        $screen = ScreenBuffer::fromFrame($frame);
        self::assertSame('full docs', trim($screen->lineAt(1)));
        self::assertSame('https://example.com/docs', $screen->styleAt(0, 1)->link);
    }

    #[Test]
    public function fromMarkdownMultiParagraph(): void
    {
        $frame = new Frame(20, 3);
        $context = $this->context();

        $paragraph = Paragraph::fromMarkdown("First paragraph\n\nSecond paragraph");
        $paragraph->render($frame, new Rect(0, 0, 20, 3), $context);

        self::assertSame('F', $frame->characters[0]);
        self::assertSame('S', $frame->characters[20]);
    }

    #[Test]
    public function fromAnsiParsesColoredText(): void
    {
        $frame = new Frame(10, 1);
        $context = $this->context();

        $ansi = "\x1b[31mRed\x1b[0m Normal";
        $paragraph = Paragraph::fromAnsi($ansi);
        $paragraph->render($frame, new Rect(0, 0, 10, 1), $context);

        self::assertSame('R', $frame->characters[0]);
        self::assertSame(Color::ansi(AnsiColor::Red)->value, $frame->foregrounds[0]);
        self::assertSame('N', $frame->characters[4]);
    }

    #[Test]
    public function fromAnsiPassesOverASequenceThatIsNotAColour(): void
    {
        $frame = new Frame(12, 1);

        Paragraph::fromAnsi("\x1b[5Chi")->render($frame, new Rect(0, 0, 12, 1), $this->context());

        self::assertSame(
            'hi',
            trim(implode('', array_slice($frame->characters, 0, 12))),
            'cursor-forward is not a colour, so its bytes are not text to draw',
        );
        self::assertSame(0, $frame->attributes[0], 'and its 5 is not read as blink');
    }

    #[Test]
    public function fromAnsiDrawsNeitherACharacterSetResetNorALinkTarget(): void
    {
        $frame = new Frame(20, 1);

        Paragraph::fromAnsi("\x1b(B\x1b]8;;http://x\x1b\\link\x1b]8;;\x1b\\ ok")->render($frame, new Rect(0, 0, 20, 1), $this->context());

        self::assertSame('link ok', trim(implode('', array_slice($frame->characters, 0, 20))));
    }

    #[Test]
    public function aTabInTextMovesToTheNextTabStop(): void
    {
        $frame = new Frame(20, 1);

        Paragraph::fromText("ab\tc\td")->render($frame, new Rect(0, 0, 20, 1), $this->context());

        self::assertSame('ab      c       d', rtrim(implode('', array_slice($frame->characters, 0, 20))));
    }

    #[Test]
    public function fromAnsiCountsTabStopsAcrossStyledRunsAndEndsALineAtCrlf(): void
    {
        $frame = new Frame(20, 2);

        Paragraph::fromAnsi("\tx\r\na\x1b[1mb\tc")->render($frame, new Rect(0, 0, 20, 2), $this->context());

        self::assertSame(['        x', 'ab      c'], [
            rtrim(implode('', array_slice($frame->characters, 0, 20))),
            rtrim(implode('', array_slice($frame->characters, 20, 20))),
        ]);
    }

    #[Test]
    public function aCarriageReturnStartsTheLineOver(): void
    {
        $frame = new Frame(10, 2);

        Paragraph::fromText("10%\r20%\r30%\r\nnext")->render($frame, new Rect(0, 0, 10, 2), $this->context());

        self::assertSame('30%', rtrim(implode('', array_slice($frame->characters, 0, 10))));
        self::assertSame('next', rtrim(implode('', array_slice($frame->characters, 10, 10))));
    }

    #[Test]
    public function aCrlfLineKeepsEveryCharacterBeforeIt(): void
    {
        $frame = new Frame(10, 1);

        Paragraph::fromText("line\r\nnext")->render($frame, new Rect(0, 0, 10, 1), $this->context());

        self::assertSame('line', rtrim(implode('', array_slice($frame->characters, 0, 10))));
    }

    #[Test]
    public function fromAnsiCountsATabStopFromEveryStyledRunBeforeIt(): void
    {
        $frame = new Frame(20, 1);

        Paragraph::fromAnsi("ab\x1b[1mcd\x1b[0me\tx")->render($frame, new Rect(0, 0, 20, 1), $this->context());

        self::assertSame('abcde   x', rtrim(implode('', array_slice($frame->characters, 0, 20))));
    }

    #[Test]
    public function twoTextsOfTheSameWidthWrapEachByItsOwnWords(): void
    {
        $first = Paragraph::fromText('aaaa bbbb');
        $second = Paragraph::fromText('cc dd ee ff');

        self::assertSame([2, 2], [$first->measureHeight(5), $second->measureHeight(5)]);
        self::assertSame(['cc dd', 'ee ff'], $this->rowsOf($second, 5, 2));
    }

    #[Test]
    public function aLongLineWrapsAtEverySpaceItReaches(): void
    {
        self::assertSame(['aa bb', 'cc dd', 'ee ff', 'gg'], $this->rowsOf(Paragraph::fromText('aa bb cc dd ee ff gg'), 5, 4));
    }

    #[Test]
    public function aWideCharacterThatNoLongerFitsAfterTheBreakingSpaceStartsARowOfItsOwn(): void
    {
        self::assertSame(['', 'ab', '東'], $this->rowsOf(Paragraph::fromText(' ab東'), 3, 3));
    }

    #[Test]
    public function wrappedMultibyteTextIsCutBetweenCharactersNotBytes(): void
    {
        self::assertSame(['äö üß', 'éè'], $this->rowsOf(Paragraph::fromText('äö üß éè'), 5, 2));
    }

    #[Test]
    public function styledSpansDrawnAwayFromTheOriginKeepTheirPlaceAndStyle(): void
    {
        $frame = new Frame(12, 1);
        $line = new Line([
            Span::styled('ab', new Style(foreground: Color::ansi(AnsiColor::Red))),
            Span::styled('cd', new Style(foreground: Color::ansi(AnsiColor::Green))),
            Span::styled('ef', new Style(foreground: Color::ansi(AnsiColor::Yellow))),
        ]);

        Paragraph::fromLines([$line])->render($frame, new Rect(3, 0, 9, 1), $this->context());

        self::assertSame('   abcdef', rtrim(implode('', $frame->characters)));
        self::assertSame(
            [Color::ansi(AnsiColor::Red)->value, Color::ansi(AnsiColor::Green)->value, Color::ansi(AnsiColor::Yellow)->value],
            [$frame->foregrounds[3], $frame->foregrounds[5], $frame->foregrounds[7]],
        );
    }

    #[Test]
    public function fromAnsiStartsTheLineOverAtACarriageReturnAndKeepsItsColour(): void
    {
        $frame = new Frame(20, 1);

        Paragraph::fromAnsi("\x1b[31mold\rnew\x1b[0m\tx")->render($frame, new Rect(0, 0, 20, 1), $this->context());

        self::assertSame('new     x', rtrim(implode('', array_slice($frame->characters, 0, 20))));
        self::assertSame(Color::ansi(AnsiColor::Red)->value, $frame->foregrounds[0]);
    }

    #[Test]
    public function fromAnsiSplitsOnNewlines(): void
    {
        $frame = new Frame(10, 2);
        $context = $this->context();

        $ansi = "Line1\nLine2";
        $paragraph = Paragraph::fromAnsi($ansi);
        $paragraph->render($frame, new Rect(0, 0, 10, 2), $context);

        self::assertSame('L', $frame->characters[0]);
        self::assertSame('1', $frame->characters[4]);
        self::assertSame('L', $frame->characters[10]);
        self::assertSame('2', $frame->characters[14]);
    }

    #[Test]
    public function fromAnsiAccumulatesSgrStateWithinLine(): void
    {
        $frame = new Frame(15, 1);
        $context = $this->context();

        $ansi = "\x1b[1mBold \x1b[31mBoldRed";
        $paragraph = Paragraph::fromAnsi($ansi);
        $paragraph->render($frame, new Rect(0, 0, 15, 1), $context);

        self::assertSame('B', $frame->characters[0]);
        self::assertSame(Attr::BOLD, $frame->attributes[0] & Attr::BOLD);
        self::assertSame('B', $frame->characters[5]);
        self::assertSame(Attr::BOLD, $frame->attributes[5] & Attr::BOLD);
        self::assertSame(Color::ansi(AnsiColor::Red)->value, $frame->foregrounds[5]);
    }

    #[Test]
    public function fromAnsiColorCarriesAcrossNewlines(): void
    {
        $ansi = "\x1b[31mred text\nstill red";
        $paragraph = Paragraph::fromAnsi($ansi);

        $line1 = $paragraph->lines()[0];
        self::assertCount(1, $line1->spans);
        self::assertSame('red text', $line1->spans[0]->content);
        self::assertNotNull($line1->spans[0]->style);

        $line2 = $paragraph->lines()[1];
        self::assertCount(1, $line2->spans);
        self::assertSame('still red', $line2->spans[0]->content);
        self::assertNotNull($line2->spans[0]->style, 'Line 2 should inherit SGR state from line 1');
        self::assertSame(
            $line1->spans[0]->style->foregroundValue(),
            $line2->spans[0]->style->foregroundValue(),
            'Line 2 foreground should match line 1 (red)',
        );
    }

    #[Test]
    public function measureHeightReturnsZeroForZeroWidthAndNonzeroForOneColumn(): void
    {
        $paragraph = Paragraph::fromText('Hello');

        self::assertSame(0, $paragraph->measureHeight(0), 'Width 0 must short-circuit to 0');
        self::assertGreaterThan(0, $paragraph->measureHeight(1), 'Width 1 must still produce visual line(s)');
    }

    #[Test]
    public function measureHeightUsesWordWrapWhenWrapIsWordWrap(): void
    {
        $paragraph = Paragraph::fromText('alpha bravo charlie delta echo foxtrot');

        self::assertGreaterThanOrEqual(3, $paragraph->measureHeight(10), 'A 38-char sentence at width 10 should wrap to at least 3 visual lines');
    }

    #[Test]
    public function wrappingIsReusedAcrossInstancesWithTheSameContent(): void
    {
        // An app rebuilds its widgets every frame, so the wrap of a long line has
        // to survive as content, not as one instance's private memo.
        $text = str_repeat('alpha bravo charlie delta ', 200);

        $first = Paragraph::fromText($text)->measureHeight(40);
        $second = Paragraph::fromText($text)->measureHeight(40);

        self::assertSame($first, $second);
        self::assertSame(1, WrapCache::wraps(), 'The second instance found the wrap the first one made');
    }

    #[Test]
    public function renderingAfterMeasuringDoesNotWrapAgain(): void
    {
        $paragraph = Paragraph::fromText('alpha bravo charlie delta echo foxtrot golf hotel');
        $paragraph->measureHeight(12);
        $afterMeasuring = WrapCache::wraps();

        $paragraph->render(new Frame(12, 20), new Rect(0, 0, 12, 20), $this->context());

        self::assertSame($afterMeasuring, WrapCache::wraps(), 'Measuring left the render nothing to wrap');
    }

    #[Test]
    public function reusedWrappingStillRespectsWidth(): void
    {
        $paragraph = Paragraph::fromText('alpha bravo charlie delta echo foxtrot golf hotel');

        $narrow = $paragraph->measureHeight(12);
        $wide = $paragraph->measureHeight(48);

        self::assertGreaterThan($wide, $narrow, 'Each width gets its own answer');
        self::assertSame($narrow, $paragraph->measureHeight(12), 'And the first answer is still right');
    }

    #[Test]
    public function reusedWrappingStillRespectsStyle(): void
    {
        $plain = Paragraph::fromText('alpha bravo');
        $styled = $plain->withStyle(new Style(attributes: Attr::BOLD));
        $area = new Rect(0, 0, 20, 2);
        $context = new RenderContext(Theme::dark());

        $plainFrame = new Frame(20, 2);
        $styledFrame = new Frame(20, 2);
        $plain->render($plainFrame, $area, $context);
        $styled->render($styledFrame, $area, $context);

        self::assertNotSame($plainFrame->attributes[0], $styledFrame->attributes[0], 'Style is part of what is cached');
    }

    #[Test]
    public function measureHeightNoWrapReturnsInputLineCount(): void
    {
        $paragraph = Paragraph::fromLines([Line::raw('this is a long single line that exceeds width')])
            ->withWrap(Wrap::NoWrap);

        self::assertSame(1, $paragraph->measureHeight(10), 'NoWrap must return exactly one visual line per input line, regardless of width');
    }

    #[Test]
    public function fromAnsiDoesNotMisinterpretOpenBracketAfterRegularCharAsSgr(): void
    {
        $paragraph = Paragraph::fromAnsi('A[5mB');

        // No ESC byte — literal "[5m" must be preserved as text, not consumed
        // as an SGR introducer. There's exactly one span: the whole string.
        self::assertCount(1, $paragraph->lines());
        $line = $paragraph->lines()[0];
        $combined = '';
        foreach ($line->spans as $span) {
            $combined .= $span->content;
        }
        self::assertSame('A[5mB', $combined, 'Literal [...m sequences without ESC must be preserved verbatim');
    }

    #[Test]
    public function fromAnsiHandlesEmptySgrResetImmediatelyFollowedByText(): void
    {
        $paragraph = Paragraph::fromAnsi("\x1b[mhello");

        // The 'm' comes right after '[', so strpos must look from position+2 to find it
        $line = $paragraph->lines()[0];
        $combined = '';
        foreach ($line->spans as $span) {
            $combined .= $span->content;
        }
        self::assertSame('hello', $combined, 'Empty SGR \x1b[m must be consumed entirely and not appear in output');
    }

    #[Test]
    public function fromAnsiCorrectlyParsesColorAfterAPrecedingTextSegment(): void
    {
        // Two SGRs in one line. The second SGR is at position > 0.
        $paragraph = Paragraph::fromAnsi("ab\x1b[31mcd\x1b[32mef");

        $line = $paragraph->lines()[0];
        // Expect ['ab' default, 'cd' red(31), 'ef' green(32)]
        self::assertCount(3, $line->spans);
        self::assertSame('ab', $line->spans[0]->content);
        self::assertSame('cd', $line->spans[1]->content);
        self::assertSame('ef', $line->spans[2]->content);
        self::assertSame(Color::ansi(AnsiColor::Red)->value, $line->spans[1]->style->foregroundValue(), 'cd must be ANSI red (31)');
        self::assertSame(Color::ansi(AnsiColor::Green)->value, $line->spans[2]->style->foregroundValue(), 'ef must be ANSI green (32) — substring length math must produce the correct SGR code');
    }

    #[Test]
    public function fromAnsiParsesMultiDigitSgrParameters(): void
    {
        // 256-color code 196 (bright red); needs 3+ chars of SGR params to be parsed correctly
        $paragraph = Paragraph::fromAnsi("\x1b[38;5;196mtext");

        $line = $paragraph->lines()[0];
        self::assertCount(1, $line->spans);
        self::assertSame('text', $line->spans[0]->content);
        $expectedValue = Color::color256(196)->value;
        self::assertSame($expectedValue, $line->spans[0]->style->foregroundValue(), 'Multi-digit SGR params must parse fully — substring length must include every digit');
    }

    #[Test]
    public function fromAnsiHandlesTwoByteUtf8Characters(): void
    {
        $paragraph = Paragraph::fromAnsi('café');

        $line = $paragraph->lines()[0];
        $combined = '';
        foreach ($line->spans as $span) {
            $combined .= $span->content;
        }
        self::assertSame('café', $combined, '2-byte UTF-8 sequences (é) must be consumed as a single character');
    }

    #[Test]
    public function fromAnsiHandlesThreeByteUtf8Characters(): void
    {
        // € is 0xe2 0x82 0xac (3 bytes)
        $paragraph = Paragraph::fromAnsi('a€b');

        $line = $paragraph->lines()[0];
        $combined = '';
        foreach ($line->spans as $span) {
            $combined .= $span->content;
        }
        self::assertSame('a€b', $combined, '3-byte UTF-8 sequences (€) must be consumed as a single character');
    }

    #[Test]
    public function fromAnsiHandlesFourByteUtf8Characters(): void
    {
        // 🎉 is 0xf0 0x9f 0x8e 0x89 (4 bytes)
        $paragraph = Paragraph::fromAnsi('x🎉y');

        $line = $paragraph->lines()[0];
        $combined = '';
        foreach ($line->spans as $span) {
            $combined .= $span->content;
        }
        self::assertSame('x🎉y', $combined, '4-byte UTF-8 sequences (emoji) must be consumed as a single character');
    }

    #[Test]
    public function renderAtWidthOneStillRendersFirstCharacter(): void
    {
        $frame = new Frame(3, 1);
        $paragraph = Paragraph::fromText('XY');

        $paragraph->render($frame, new Rect(0, 0, 1, 1), $this->context());

        self::assertSame('X', $frame->characters[0], 'Width 1 must still produce output');
    }

    #[Test]
    public function keepsAJoinedEmojiClusterInOneCell(): void
    {
        $cluster = "\u{1F469}\u{1F3FD}\u{200D}\u{1F4BB}";
        $frame = new Frame(6, 1);
        $paragraph = Paragraph::fromText($cluster);

        $paragraph->render($frame, new Rect(0, 0, 6, 1), $this->context());

        self::assertSame($cluster, $frame->characters[0]);
    }

    #[Test]
    public function renderWithZeroHeightWritesNothing(): void
    {
        $frame = new Frame(5, 2);
        for ($i = 0; $i < 10; ++$i) {
            $frame->putChar($i % 5, intdiv($i, 5), '.');
        }
        $paragraph = Paragraph::fromText('HelloWorld');

        $paragraph->render($frame, new Rect(0, 0, 5, 0), $this->context());

        // No cell should have been overwritten
        for ($i = 0; $i < 10; ++$i) {
            self::assertSame('.', $frame->characters[$i], "Zero-height render must not write to ({$i})");
        }
    }

    #[Test]
    public function renderWithZeroWidthWritesNothing(): void
    {
        $frame = new Frame(5, 2);
        for ($i = 0; $i < 10; ++$i) {
            $frame->putChar($i % 5, intdiv($i, 5), '.');
        }
        $paragraph = Paragraph::fromText('HelloWorld');

        $paragraph->render($frame, new Rect(0, 0, 0, 2), $this->context());

        for ($i = 0; $i < 10; ++$i) {
            self::assertSame('.', $frame->characters[$i], "Zero-width render must not write to ({$i})");
        }
    }

    #[Test]
    public function renderStopsAtAreaHeightWithoutWritingBelow(): void
    {
        $frame = new Frame(8, 4);
        for ($i = 0; $i < 32; ++$i) {
            $frame->putChar($i % 8, intdiv($i, 8), '.');
        }
        $paragraph = Paragraph::fromText("AAA\nBBB\nCCC");

        $paragraph->render($frame, new Rect(0, 0, 8, 2), $this->context());

        $row0 = '';
        $row1 = '';
        $row2 = '';
        for ($x = 0; $x < 8; ++$x) {
            $row0 .= $frame->characters[$x];
            $row1 .= $frame->characters[8 + $x];
            $row2 .= $frame->characters[16 + $x];
        }
        self::assertStringContainsString('AAA', $row0);
        self::assertStringContainsString('BBB', $row1);
        self::assertStringNotContainsString('CCC', $row2, 'Third line must not be drawn beyond area height');
    }

    #[Test]
    public function renderStopsAtAreaWidthWithoutWritingToTheRight(): void
    {
        $frame = new Frame(10, 1);
        for ($i = 0; $i < 10; ++$i) {
            $frame->putChar($i, 0, '.');
        }
        $paragraph = Paragraph::fromText('ABCDEFGHIJ')->withWrap(Wrap::NoWrap);

        $paragraph->render($frame, new Rect(0, 0, 4, 1), $this->context());

        // First 4 cells should have content from the paragraph.
        self::assertSame('A', $frame->characters[0]);
        self::assertSame('D', $frame->characters[3]);
        // Cells 4-9 must remain '.' — paragraph cannot write past area->width.
        for ($x = 4; $x < 10; ++$x) {
            self::assertSame('.', $frame->characters[$x], "Cell {$x} beyond area width must not be touched");
        }
    }

    #[Test]
    public function noWrapRendersEveryInputLine(): void
    {
        $frame = new Frame(8, 3);
        $paragraph = Paragraph::fromText("AAA\nBBB\nCCC")->withWrap(Wrap::NoWrap);

        $paragraph->render($frame, new Rect(0, 0, 8, 3), $this->context());

        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[8]);
        self::assertSame('C', $frame->characters[16]);
    }

    #[Test]
    public function multiByteCharactersAreGroupedPerGrapheme(): void
    {
        $frame = new Frame(4, 1);
        $paragraph = Paragraph::fromText('café');

        $paragraph->render($frame, new Rect(0, 0, 4, 1), $this->context());

        self::assertSame('c', $frame->characters[0]);
        self::assertSame('a', $frame->characters[1]);
        self::assertSame('f', $frame->characters[2]);
        self::assertSame('é', $frame->characters[3], 'Multi-byte character must occupy exactly one cell');
    }

    #[Test]
    public function wrapBreaksAtSpaceWhenSpaceExistsInLine(): void
    {
        $frame = new Frame(5, 2);
        $paragraph = Paragraph::fromText('hi world');

        $paragraph->render($frame, new Rect(0, 0, 5, 2), $this->context());

        // "hi" fits at row 0; "world" starts at row 1.
        self::assertSame('h', $frame->characters[0]);
        self::assertSame('i', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2], 'No character at column 2 (space wasn\'t the wrap point — yet was preserved on row 0)');
        self::assertSame('w', $frame->characters[5], 'Wrap must break at space, putting "world" at column 0 of row 1');
        self::assertSame('d', $frame->characters[9]);
    }

    #[Test]
    public function wrapPreservesWordAfterSpaceAtCorrectWidth(): void
    {
        // "abc defg" at width 5 → "abc" wraps, "defg" goes to next line.
        $frame = new Frame(5, 3);
        for ($i = 0; $i < 15; ++$i) {
            $frame->putChar($i % 5, intdiv($i, 5), '.');
        }
        $paragraph = Paragraph::fromText('abc defghij');

        $paragraph->render($frame, new Rect(0, 0, 5, 3), $this->context());

        // Row 0: "abc" only — cells 3 and 4 must remain '.'
        self::assertSame('a', $frame->characters[0]);
        self::assertSame('b', $frame->characters[1]);
        self::assertSame('c', $frame->characters[2]);
        self::assertSame('.', $frame->characters[3], 'Wrap break at space must NOT include the trailing space and "d" on row 0');
        self::assertSame('.', $frame->characters[4]);
        // Row 1: "defgh" (5 chars fit)
        self::assertSame('d', $frame->characters[5]);
        self::assertSame('h', $frame->characters[9]);
        // Row 2: "ij" (the remaining 2 chars)
        self::assertSame('i', $frame->characters[10]);
        self::assertSame('j', $frame->characters[11]);
    }

    #[Test]
    public function centerAlignmentCentersShortLine(): void
    {
        $frame = new Frame(11, 1);
        for ($i = 0; $i < 11; ++$i) {
            $frame->putChar($i, 0, '.');
        }
        $paragraph = Paragraph::fromText('abc')->withAlignment(Alignment::Center);

        $paragraph->render($frame, new Rect(0, 0, 11, 1), $this->context());

        // (11-3)/2 = 4. 'a' at col 4.
        self::assertSame('.', $frame->characters[3]);
        self::assertSame('a', $frame->characters[4]);
        self::assertSame('b', $frame->characters[5]);
        self::assertSame('c', $frame->characters[6]);
        self::assertSame('.', $frame->characters[7]);
    }

    #[Test]
    public function centerAlignmentDoesNotUnderflowWhenLineWiderThanArea(): void
    {
        $frame = new Frame(3, 1);
        for ($i = 0; $i < 3; ++$i) {
            $frame->putChar($i, 0, '.');
        }
        $paragraph = Paragraph::fromText('abcdef')
            ->withAlignment(Alignment::Center)
            ->withWrap(Wrap::NoWrap);

        $paragraph->render($frame, new Rect(0, 0, 3, 1), $this->context());

        // Offset must be 0; 'a' lands at col 0 (not col -1 which would skip it).
        self::assertSame('a', $frame->characters[0], 'Center alignment offset must not go negative when line is wider than area');
    }

    #[Test]
    public function rightAlignmentRightAlignsShortLine(): void
    {
        $frame = new Frame(8, 1);
        for ($i = 0; $i < 8; ++$i) {
            $frame->putChar($i, 0, '.');
        }
        $paragraph = Paragraph::fromText('abc')->withAlignment(Alignment::Right);

        $paragraph->render($frame, new Rect(0, 0, 8, 1), $this->context());

        // areaWidth - lineWidth = 5. 'a' at col 5.
        self::assertSame('.', $frame->characters[4]);
        self::assertSame('a', $frame->characters[5]);
        self::assertSame('b', $frame->characters[6]);
        self::assertSame('c', $frame->characters[7]);
    }

    #[Test]
    public function rightAlignmentDoesNotUnderflowWhenLineWiderThanArea(): void
    {
        $frame = new Frame(3, 1);
        for ($i = 0; $i < 3; ++$i) {
            $frame->putChar($i, 0, '.');
        }
        $paragraph = Paragraph::fromText('abcdef')
            ->withAlignment(Alignment::Right)
            ->withWrap(Wrap::NoWrap);

        $paragraph->render($frame, new Rect(0, 0, 3, 1), $this->context());

        self::assertSame('a', $frame->characters[0], 'Right alignment offset must not go negative when line is wider than area');
    }

    #[Test]
    public function fromAnsiProducesSpanWithNullStyleForPlainText(): void
    {
        $paragraph = Paragraph::fromAnsi('hello');

        $span = $paragraph->lines()[0]->spans[0];
        self::assertSame('hello', $span->content);
        self::assertNull($span->style, 'Plain text via fromAnsi must produce Span with no Style — not Style(0,0,0)');
    }

    #[Test]
    public function fromAnsiProducesSpanWithNullStyleWhenOnlyForegroundWasReset(): void
    {
        // Trigger spanFromSgrState with attributes=0, background=0, foreground=0.
        $paragraph = Paragraph::fromAnsi("\x1b[31mred\x1b[0m plain");

        // First span "red" has foreground=red; second span " plain" should have style=null (all defaults).
        self::assertCount(2, $paragraph->lines()[0]->spans);
        self::assertNull($paragraph->lines()[0]->spans[1]->style, 'After SGR 0 reset, subsequent text must produce Span with no Style');
    }

    #[Test]
    public function aCombiningMarkStaysWithItsBaseInOneCell(): void
    {
        $frame = new Frame(3, 1);
        for ($i = 0; $i < 3; ++$i) {
            $frame->putChar($i, 0, '.');
        }
        $paragraph = Paragraph::fromText("a\u{0301}");

        $paragraph->render($frame, new Rect(0, 0, 3, 1), $this->context());

        self::assertSame("a\u{0301}", $frame->characters[0]);
        self::assertSame('.', $frame->characters[1], 'A combining mark claims no cell of its own');
    }

    #[Test]
    public function fromAnsiWithOnlyForegroundSetLeavesBackgroundNull(): void
    {
        $paragraph = Paragraph::fromAnsi("\x1b[31mred");

        $span = $paragraph->lines()[0]->spans[0];
        self::assertNotNull($span->style);
        self::assertNotNull($span->style->foreground, 'foreground must be set after SGR 31');
        self::assertNull($span->style->background, 'background must remain null when SGR did not set it');
    }

    #[Test]
    public function fromAnsiWithOnlyBackgroundSetLeavesForegroundNull(): void
    {
        $paragraph = Paragraph::fromAnsi("\x1b[41mbg");

        $span = $paragraph->lines()[0]->spans[0];
        self::assertNotNull($span->style);
        self::assertNull($span->style->foreground, 'foreground must remain null when SGR did not set it');
        self::assertNotNull($span->style->background, 'background must be set when SGR 41 was applied');
    }

    #[Test]
    public function wrapHandlesLeadingSpaceAtBreakBoundary(): void
    {
        $frame = new Frame(3, 3);
        for ($i = 0; $i < 9; ++$i) {
            $frame->putChar($i % 3, intdiv($i, 3), '.');
        }
        $paragraph = Paragraph::fromText(' ab');

        $paragraph->render($frame, new Rect(0, 0, 1, 3), $this->context());

        // " " is wrap point 0, so the visual lines are ["", "a", "b"].
        self::assertSame('.', $frame->characters[0], 'Leading space at the wrap boundary must be consumed, not rendered into cell 0');
        self::assertSame('a', $frame->characters[3], '"a" must appear on the second visual line');
    }

    #[Test]
    public function anOverflowingBreakingSpaceIsDroppedSoNoCharacterIsLost(): void
    {
        $frame = new Frame(5, 2);
        for ($index = 0; $index < 10; ++$index) {
            $frame->putChar($index % 5, intdiv($index, 5), '.');
        }

        Paragraph::fromText('abcde fg')->render($frame, new Rect(0, 0, 5, 2), $this->context());

        self::assertSame('f', $frame->characters[5], 'the continuation line starts at its own first character');
        self::assertSame('g', $frame->characters[6]);
    }

    #[Test]
    public function aTokenSpanInALineAdoptsTheThemesStyle(): void
    {
        $frame = new Frame(6, 1);
        $theme = Theme::dark();

        Paragraph::fromLines([new Line([Span::token('AB', StyleToken::Error)])])
            ->render($frame, new Rect(0, 0, 6, 1), new RenderContext($theme));

        self::assertSame('A', $frame->characters[0]);
        self::assertSame($theme->error->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function theSameParagraphRepaintsTokenSpansWhenTheThemeChanges(): void
    {
        $paragraph = Paragraph::fromLines([new Line([Span::token('AB', StyleToken::Accent)])]);

        $dark = new Frame(6, 1);
        $paragraph->render($dark, new Rect(0, 0, 6, 1), new RenderContext(Theme::dark()));

        $light = new Frame(6, 1);
        $paragraph->render($light, new Rect(0, 0, 6, 1), new RenderContext(Theme::light()));

        self::assertSame(Theme::dark()->accent->foregroundValue(), $dark->foregrounds[0]);
        self::assertSame(Theme::light()->accent->foregroundValue(), $light->foregrounds[0]);
    }

    #[Test]
    public function aTokenSpanKeepsItsOwnAttributesOverTheThemeStyle(): void
    {
        $frame = new Frame(6, 1);
        $theme = Theme::dark();

        Paragraph::fromLines([new Line([Span::token('AB', StyleToken::Accent)->underline()])])
            ->render($frame, new Rect(0, 0, 6, 1), new RenderContext($theme));

        self::assertSame($theme->accent->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame(Attr::UNDERLINE, $frame->attributes[0]);
    }

    #[Test]
    public function tokenSpansDoNotAffectMeasuredHeight(): void
    {
        $spans = [Span::token('hello world', StyleToken::Accent)];

        $themed = Paragraph::fromLines([new Line($spans)])->withWrap(Wrap::WordWrap);
        $plain = Paragraph::fromLines([new Line([Span::raw('hello world')])])->withWrap(Wrap::WordWrap);

        self::assertSame($plain->measureHeight(5), $themed->measureHeight(5));
    }

    protected function setUp(): void
    {
        WrapCache::clear();
    }

    protected function tearDown(): void
    {
        WrapCache::clear();
    }

    private function context(): RenderContext
    {
        return new RenderContext(Theme::dark());
    }

    /**
     * @return list<string>
     */
    private function rowsOf(Paragraph $paragraph, int $width, int $height): array
    {
        $frame = new Frame($width, $height);
        $paragraph->render($frame, new Rect(0, 0, $width, $height), $this->context());
        $screen = ScreenBuffer::fromFrame($frame);

        return array_map(static fn (int $row): string => rtrim($screen->lineAt($row)), range(0, $height - 1));
    }
}
