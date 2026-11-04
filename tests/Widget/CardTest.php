<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\BorderType;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Feedback\ProgressBar;
use Elmphant\Tui\Widget\Structure\Card;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CardTest extends TestCase
{
    #[Test]
    public function theTitleBodyAndBorderCanAllBeReplaced(): void
    {
        $frame = new Frame(20, 4);
        $card = Card::new('Old', 'stale')
            ->withTitle('New')
            ->withBody('fresh')
            ->withBorderType(BorderType::Double);

        $card->render($frame, new Rect(0, 0, 20, 4), new RenderContext(Theme::dark()));

        self::assertSame('╔', $frame->characters[0], 'A double border replaces the rounded default');
        self::assertSame('N', $frame->characters[20 + 2], 'The replacement title');
        self::assertSame('f', $frame->characters[40 + 2], 'The replacement body');
    }

    #[Test]
    public function aCardHoldsAWidgetBelowItsTitle(): void
    {
        $frame = new Frame(20, 4);
        $card = Card::new('CPU', ProgressBar::new(0.5));

        $card->render($frame, new Rect(0, 0, 20, 4), new RenderContext(Theme::dark()));

        $screen = ScreenBuffer::fromFrame($frame);
        self::assertStringContainsString('CPU', $screen->lineAt(1));
        self::assertStringContainsString('50%', $screen->lineAt(2));
        self::assertEquals([[ProgressBar::new(0.5), new Rect(2, 2, 16, 1)]], [...$card->placedChildren(new Rect(0, 0, 20, 4))]);
    }

    #[Test]
    public function dimmingACardChangesHowItIsDrawn(): void
    {
        $area = new Rect(0, 0, 20, 4);
        $card = Card::new('Hi', 'there');

        self::assertSame(0, $this->cornerAttributes($card, $area) & Attr::FAINT);
        self::assertSame(Attr::FAINT, $this->cornerAttributes($card->withDimmed(), $area) & Attr::FAINT);
        self::assertSame(
            0,
            $this->cornerAttributes($card->withDimmed()->withDimmed(false), $area) & Attr::FAINT,
            'And withDimmed(false) puts it back',
        );
    }

    #[Test]
    public function cardRendersBorderTitleAndBody(): void
    {
        $frame = new Frame(20, 4);
        $context = new RenderContext(Theme::dark());
        $card = Card::new('Hello', 'world');

        $card->render($frame, new Rect(0, 0, 20, 4), $context);

        self::assertSame('╭', $frame->characters[0], 'top-left corner');
        self::assertSame('H', $frame->characters[20 + 2], 'title H at row 1 col 2');
        self::assertSame('w', $frame->characters[40 + 2], 'body w at row 2 col 2');
    }

    #[Test]
    public function cardRendersAllFourCorners(): void
    {
        $frame = new Frame(20, 4);
        $context = new RenderContext(Theme::dark());
        $card = Card::new('A', 'B');

        $card->render($frame, new Rect(0, 0, 20, 4), $context);

        self::assertSame('╭', $frame->characters[0], 'top-left');
        self::assertSame('╮', $frame->characters[19], 'top-right');
        self::assertSame('╰', $frame->characters[60], 'bottom-left');
        self::assertSame('╯', $frame->characters[79], 'bottom-right');
    }

    #[Test]
    public function cardConstraintExposesHeight(): void
    {
        $card = Card::new('x', 'y');
        self::assertSame(4, $card->sizing()->rows->min);
        self::assertSame(4, $card->sizing()->rows->max);

        $taller = $card->withHeight(7);
        self::assertSame(7, $taller->sizing()->rows->min);
        self::assertSame(7, $taller->sizing()->rows->max);
    }

    #[Test]
    public function dimmedCardRendersTitleWithFaintAttribute(): void
    {
        $frame = new Frame(20, 4);
        $context = new RenderContext(Theme::dark());
        $card = Card::new('Title', 'body')->withDimmed();

        $card->render($frame, new Rect(0, 0, 20, 4), $context);

        $titleAttr = $frame->attributes[20 + 2]; // row 1, col 2 — 'T'
        self::assertSame(
            Attr::FAINT,
            $titleAttr & Attr::FAINT,
            'withDimmed() should set FAINT on title',
        );
    }

    #[Test]
    public function cardDefaultIsNotDimmed(): void
    {
        // L30 FalseValue: $dimmed default must be false, not true
        $frame = new Frame(20, 4);
        $context = new RenderContext(Theme::dark());
        $card = Card::new('Title', 'body');

        $card->render($frame, new Rect(0, 0, 20, 4), $context);

        $titleAttr = $frame->attributes[20 + 2];
        self::assertSame(
            0,
            $titleAttr & Attr::FAINT,
            'Card without explicit withDimmed() must NOT have FAINT on title (default $dimmed must be false)',
        );
    }

    #[Test]
    public function cardRendersAtMinimumWidthFour(): void
    {
        // L85 LessThan width: `< 4` → `<= 4`. width = 4 must render.
        $frame = new Frame(4, 3);
        $context = new RenderContext(Theme::dark());
        $card = Card::new('X', 'y');

        $card->render($frame, new Rect(0, 0, 4, 3), $context);

        self::assertSame('╭', $frame->characters[0], 'Card with width=4 must render the top-left corner');
    }

    #[Test]
    public function cardRendersAtMinimumHeightThree(): void
    {
        // L85 LessThan height: `< 3` → `<= 3`. height = 3 must render.
        $frame = new Frame(8, 3);
        $context = new RenderContext(Theme::dark());
        $card = Card::new('X', 'y');

        $card->render($frame, new Rect(0, 0, 8, 3), $context);

        self::assertSame('╭', $frame->characters[0], 'Card with height=3 must render');
    }

    #[Test]
    public function aCardTooShortForItsBorderShowsTitleAndBodyWithoutOne(): void
    {
        $frame = new Frame(10, 2);

        Card::new('X', 'y')->render($frame, new Rect(0, 0, 10, 2), new RenderContext(Theme::dark()));

        $screen = ScreenBuffer::fromFrame($frame);
        self::assertSame(['X', 'y'], [rtrim($screen->lineAt(0)), rtrim($screen->lineAt(1))]);
    }

    #[Test]
    public function cardUsesExplicitBorderStyle(): void
    {
        // L90 Coalesce: $this->borderStyle ?? $theme->borderStyle — explicit must win
        $explicit = new Style(
            foreground: Color::ansi(2),
        );
        $frame = new Frame(20, 4);
        $context = new RenderContext(Theme::dark());
        $card = Card::new('A', 'b')->withBorderStyle($explicit);

        $card->render($frame, new Rect(0, 0, 20, 4), $context);

        // Top-left corner at index 0
        self::assertSame(
            Color::ansi(2)->value,
            $frame->foregrounds[0],
            'Card must use explicit borderStyle foreground, not theme default',
        );
    }

    #[Test]
    public function cardUsesExplicitTitleStyle(): void
    {
        // L91 Coalesce: $this->titleStyle ?? $theme->base->bold()
        $explicit = new Style(
            foreground: Color::ansi(3),
        );
        $frame = new Frame(20, 4);
        $context = new RenderContext(Theme::dark());
        $card = Card::new('Title', 'body')->withTitleStyle($explicit);

        $card->render($frame, new Rect(0, 0, 20, 4), $context);

        $titleIdx = 20 + 2; // row 1 col 2 — 'T'
        self::assertSame(
            Color::ansi(3)->value,
            $frame->foregrounds[$titleIdx],
            'Card must use explicit titleStyle foreground',
        );
    }

    #[Test]
    public function cardUsesExplicitBodyStyle(): void
    {
        // L92 Coalesce: $this->bodyStyle ?? $theme->dim
        $explicit = new Style(
            foreground: Color::ansi(4),
        );
        $frame = new Frame(20, 4);
        $context = new RenderContext(Theme::dark());
        $card = Card::new('Title', 'body')->withBodyStyle($explicit);

        $card->render($frame, new Rect(0, 0, 20, 4), $context);

        $bodyIdx = 40 + 2; // row 2 col 2 — 'b'
        self::assertSame(
            Color::ansi(4)->value,
            $frame->foregrounds[$bodyIdx],
            'Card must use explicit bodyStyle foreground',
        );
    }

    #[Test]
    public function cardClearsInnerAreaForStaleContent(): void
    {
        // L108 MethodCallRemoval (fillRect) + L109-112 fillRect args:
        // The card must clear cells inside the border so stale content doesn't bleed through.
        // Pre-fill with '#' and ensure inner is overwritten with ' ' before title/body draw.
        $frame = new Frame(20, 4);
        for ($i = 0; $i < 80; ++$i) {
            $frame->putChar($i % 20, intdiv($i, 20), '#');
        }
        $context = new RenderContext(Theme::dark());
        $card = Card::new('', ''); // No title or body — inner cells must show clearing only

        $card->render($frame, new Rect(0, 0, 20, 4), $context);

        // Row 1 (inner content top) — cells inside the border (col 1..18) must be ' ' (not '#')
        for ($x = 1; $x < 19; ++$x) {
            self::assertSame(' ', $frame->characters[20 + $x], "Row 1 col {$x} must be cleared to ' '");
            self::assertSame(' ', $frame->characters[40 + $x], "Row 2 col {$x} must be cleared to ' '");
        }
        // Cells outside the card (rows beyond 0..3 — but height is 4 so all of buffer is card area; check the rows beyond)
        // Border cells at col 0 and col 19 must contain border glyphs, not '#'
        self::assertNotSame('#', $frame->characters[20 + 0]);
        self::assertNotSame('#', $frame->characters[20 + 19]);
    }

    #[Test]
    public function cardClearRectPositionAndSizeMatchInnerArea(): void
    {
        // L109-112: fillRect position (x+1, y+1) and size (width-2, height-2) must be exact.
        // The clear area must NOT overlap the border cells.
        $frame = new Frame(20, 6);
        for ($i = 0; $i < 120; ++$i) {
            $frame->putChar($i % 20, intdiv($i, 20), '#');
        }
        $context = new RenderContext(Theme::dark());
        $card = Card::new('', '')->withHeight(6);

        $card->render($frame, new Rect(0, 0, 20, 6), $context);

        // All four corners must remain glyphs.
        self::assertNotSame(' ', $frame->characters[0], 'Top-left corner must remain a glyph');
        self::assertNotSame(' ', $frame->characters[19], 'Top-right corner must remain a glyph');
        self::assertNotSame(' ', $frame->characters[5 * 20 + 0], 'Bottom-left corner must remain a glyph');
        self::assertNotSame(' ', $frame->characters[5 * 20 + 19], 'Bottom-right corner must remain a glyph');

        // All vertical border cells must remain glyphs (left and right sides).
        for ($row = 1; $row <= 4; ++$row) {
            self::assertNotSame(' ', $frame->characters[$row * 20 + 0], "Left vertical border at row {$row} must remain a glyph");
            self::assertNotSame(' ', $frame->characters[$row * 20 + 19], "Right vertical border at row {$row} must remain a glyph");
        }

        // All inner cells (rows 1..4, cols 1..18) must be cleared to ' '.
        for ($row = 1; $row <= 4; ++$row) {
            for ($col = 1; $col <= 18; ++$col) {
                self::assertSame(' ', $frame->characters[$row * 20 + $col], "Inner cell ({$col},{$row}) must be cleared to space");
            }
        }

        // Top and bottom horizontal border segments must remain horizontal glyphs '─' (not ' ').
        // This catches L112 mutants that would extend the clear height into the border rows.
        for ($col = 1; $col <= 18; ++$col) {
            self::assertSame('─', $frame->characters[0 * 20 + $col], "Top horizontal at col {$col} must be '─', not cleared");
            self::assertSame('─', $frame->characters[5 * 20 + $col], "Bottom horizontal at col {$col} must be '─', not cleared");
        }
    }

    #[Test]
    public function cardClearRectWritesNoTextAttributes(): void
    {
        // L115 DecrementInteger/IncrementInteger: attributes parameter for fillRect must be 0
        $frame = new Frame(20, 4);
        $context = new RenderContext(Theme::dark());
        $card = Card::new('', ''); // empty so no title/body attributes to interfere

        $card->render($frame, new Rect(0, 0, 20, 4), $context);

        // Inner cells (row 1 col 1..18 and row 2 col 1..18) must have attributes == 0
        for ($col = 1; $col <= 18; ++$col) {
            self::assertSame(0, $frame->attributes[20 + $col], "Inner row 1 col {$col} attributes must be 0");
            self::assertSame(0, $frame->attributes[40 + $col], "Inner row 2 col {$col} attributes must be 0");
        }
    }

    #[Test]
    public function cardWithOneRowOfInnerSpaceRendersTitleOnly(): void
    {
        // L119 GreaterThanOrEqualTo: $inner->height >= 1 — when inner height = 1, title must still render.
        // L130 GreaterThanOrEqualTo: $inner->height >= 2 — when inner height = 1, body must NOT render.
        // Inner height = area.height - 2 (top + bottom border). So area height = 3 gives inner height = 1.
        $frame = new Frame(20, 3);
        for ($i = 0; $i < 60; ++$i) {
            $frame->putChar($i % 20, intdiv($i, 20), '.');
        }
        $context = new RenderContext(Theme::dark());
        $card = Card::new('Hi', 'body');

        $card->render($frame, new Rect(0, 0, 20, 3), $context);

        // Title must render at row 1 (inner-y)
        self::assertSame('H', $frame->characters[20 + 2], 'Title H must render at inner row 1 (inner height >= 1)');
        // Body must NOT render — but there's only one inner row.
        // The body would render at row 2 (inner.y + 1 = 2) but row 2 is the bottom border.
        // Verify row 2 col 2 is the border horizontal, not 'b'.
        self::assertNotSame('b', $frame->characters[40 + 2], 'Body must not render when inner height < 2');
    }

    #[Test]
    public function aCardTooNarrowForItsPaddingKeepsItsBorder(): void
    {
        $frame = new Frame(4, 4);

        Card::new('Title', 'body')->render($frame, new Rect(0, 0, 4, 4), new RenderContext(Theme::dark()));

        self::assertSame(['╭──╮', '│Ti│', '│bo│', '╰──╯'], array_map(
            static fn (int $row): string => ScreenBuffer::fromFrame($frame)->lineAt($row),
            [0, 1, 2, 3],
        ));
    }

    private function cornerAttributes(Card $card, Rect $area): int
    {
        $frame = new Frame(20, 4);
        $card->render($frame, $area, new RenderContext(Theme::dark()));

        return $frame->attributes[0];
    }
}
