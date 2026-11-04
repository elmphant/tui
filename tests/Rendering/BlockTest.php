<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\BorderType;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlockTest extends TestCase
{
    #[Test]
    public function innerWithNoBorderNoPadding(): void
    {
        $block = Block::new();
        $area = new Rect(2, 3, 20, 10);

        $inner = $block->inner($area);

        self::assertSame(2, $inner->x);
        self::assertSame(3, $inner->y);
        self::assertSame(20, $inner->width);
        self::assertSame(10, $inner->height);
    }

    #[Test]
    public function innerWithBorderShrinksByOne(): void
    {
        $block = Block::new()->withBorderType(BorderType::Solid);
        $area = new Rect(0, 0, 20, 10);

        $inner = $block->inner($area);

        self::assertSame(1, $inner->x);
        self::assertSame(1, $inner->y);
        self::assertSame(18, $inner->width);
        self::assertSame(8, $inner->height);
    }

    #[Test]
    public function innerWithBorderAndPadding(): void
    {
        $block = Block::new()
            ->withBorderType(BorderType::Solid)
            ->withPadding(Spacing::all(1));
        $area = new Rect(0, 0, 20, 10);

        $inner = $block->inner($area);

        self::assertSame(2, $inner->x);
        self::assertSame(2, $inner->y);
        self::assertSame(16, $inner->width);
        self::assertSame(6, $inner->height);
    }

    #[Test]
    public function innerWithMargin(): void
    {
        $block = Block::new()
            ->withMargin(Spacing::all(1))
            ->withBorderType(BorderType::Solid);
        $area = new Rect(0, 0, 20, 10);

        $inner = $block->inner($area);

        self::assertSame(2, $inner->x);
        self::assertSame(2, $inner->y);
        self::assertSame(16, $inner->width);
        self::assertSame(6, $inner->height);
    }

    #[Test]
    public function renderDrawsBorderCharacters(): void
    {
        $block = Block::new()->withBorderType(BorderType::Solid);
        $frame = new Frame(10, 5);
        $area = new Rect(0, 0, 10, 5);

        $block->render($frame, $area);

        self::assertSame('┌', $frame->characters[0 * 10 + 0]);
        self::assertSame('┐', $frame->characters[0 * 10 + 9]);
        self::assertSame('└', $frame->characters[4 * 10 + 0]);
        self::assertSame('┘', $frame->characters[4 * 10 + 9]);

        for ($x = 1; $x <= 8; ++$x) {
            self::assertSame('─', $frame->characters[0 * 10 + $x], "Top edge at x=$x");
            self::assertSame('─', $frame->characters[4 * 10 + $x], "Bottom edge at x=$x");
        }

        for ($y = 1; $y <= 3; ++$y) {
            self::assertSame('│', $frame->characters[$y * 10 + 0], "Left edge at y=$y");
            self::assertSame('│', $frame->characters[$y * 10 + 9], "Right edge at y=$y");
        }
    }

    #[Test]
    public function renderDrawsBorderWithStyle(): void
    {
        $style = new Style(
            foreground: Color::ansi(AnsiColor::Red),
            attributes: Attr::BOLD,
        );
        $block = Block::new()
            ->withBorderType(BorderType::Solid)
            ->withBorderStyle($style);
        $frame = new Frame(10, 5);
        $area = new Rect(0, 0, 10, 5);

        $block->render($frame, $area);

        $cornerIndex = 0;
        self::assertSame(Color::ansi(AnsiColor::Red)->value, $frame->foregrounds[$cornerIndex]);
        self::assertSame(Attr::BOLD, $frame->attributes[$cornerIndex]);
    }

    #[Test]
    public function renderDrawsTitle(): void
    {
        $titleStyle = new Style(
            foreground: Color::ansi(AnsiColor::Yellow),
            attributes: Attr::BOLD,
        );
        $block = Block::new()
            ->withBorderType(BorderType::Solid)
            ->withTitle('Hello')
            ->withTitleStyle($titleStyle);
        $frame = new Frame(20, 5);
        $area = new Rect(0, 0, 20, 5);

        $block->render($frame, $area);

        self::assertSame(' ', $frame->characters[0 * 20 + 1]);
        self::assertSame('H', $frame->characters[0 * 20 + 2]);
        self::assertSame('e', $frame->characters[0 * 20 + 3]);
        self::assertSame('l', $frame->characters[0 * 20 + 4]);
        self::assertSame('l', $frame->characters[0 * 20 + 5]);
        self::assertSame('o', $frame->characters[0 * 20 + 6]);
        self::assertSame(' ', $frame->characters[0 * 20 + 7]);

        self::assertSame(Color::ansi(AnsiColor::Yellow)->value, $frame->foregrounds[0 * 20 + 2]);
        self::assertSame(Attr::BOLD, $frame->attributes[0 * 20 + 2]);
    }

    #[Test]
    public function renderTitleTruncatesWhenTooLong(): void
    {
        $block = Block::new()
            ->withBorderType(BorderType::Solid)
            ->withTitle('VeryLongTitle');
        $frame = new Frame(10, 5);
        $area = new Rect(0, 0, 10, 5);

        $block->render($frame, $area);

        self::assertSame(' ', $frame->characters[0 * 10 + 1]);
        self::assertSame('V', $frame->characters[0 * 10 + 2]);
        self::assertSame('e', $frame->characters[0 * 10 + 3]);
        self::assertSame('r', $frame->characters[0 * 10 + 4]);
        self::assertSame('y', $frame->characters[0 * 10 + 5]);
        self::assertSame('L', $frame->characters[0 * 10 + 6]);
        self::assertSame('o', $frame->characters[0 * 10 + 7]);
        self::assertSame(' ', $frame->characters[0 * 10 + 8]);
    }

    #[Test]
    public function renderTitleRequiresBorder(): void
    {
        $block = Block::new()->withTitle('Hello');
        $frame = new Frame(20, 5);
        $area = new Rect(0, 0, 20, 5);

        $block->render($frame, $area);

        self::assertSame(' ', $frame->characters[0 * 20 + 2]);
    }

    #[Test]
    public function renderWithMarginOffsetsBorder(): void
    {
        $block = Block::new()
            ->withMargin(Spacing::all(1))
            ->withBorderType(BorderType::Solid);
        $frame = new Frame(12, 7);
        $area = new Rect(0, 0, 12, 7);

        $block->render($frame, $area);

        self::assertSame('┌', $frame->characters[1 * 12 + 1]);
        self::assertSame('┐', $frame->characters[1 * 12 + 10]);
        self::assertSame('└', $frame->characters[5 * 12 + 1]);
        self::assertSame('┘', $frame->characters[5 * 12 + 10]);
    }

    #[Test]
    public function titleNotRenderedWhenMaxWidthIsZero(): void
    {
        $block = Block::new()
            ->withBorderType(BorderType::Solid)
            ->withTitle('Hello');
        // width=4 means maxWidth = 4-4 = 0, title must not render
        $frame = new Frame(4, 3);
        $area = new Rect(0, 0, 4, 3);

        $block->render($frame, $area);

        // Position x=1 should still be border horizontal, not a space from title padding
        $glyphs = BorderType::Solid->glyphs();
        self::assertSame($glyphs->horizontal, $frame->characters[0 * 4 + 1]);
        self::assertSame($glyphs->horizontal, $frame->characters[0 * 4 + 2]);
    }

    #[Test]
    public function titleRenderedWhenMaxWidthIsOne(): void
    {
        $block = Block::new()
            ->withBorderType(BorderType::Solid)
            ->withTitle('Hello');
        // width=5 means maxWidth = 5-4 = 1, title should render (truncated to 1 char)
        $frame = new Frame(5, 3);
        $area = new Rect(0, 0, 5, 3);

        $block->render($frame, $area);

        self::assertSame(' ', $frame->characters[0 * 5 + 1]);
        self::assertSame('H', $frame->characters[0 * 5 + 2]);
        self::assertSame(' ', $frame->characters[0 * 5 + 3]);
    }

    #[Test]
    public function titleWithMultibyteCharactersTruncatesCorrectly(): void
    {
        $block = Block::new()
            ->withBorderType(BorderType::Solid)
            ->withTitle("\u{00FC}\u{00E4}\u{00F6}"); // uao (3 multibyte chars, 6 bytes)
        // width=8 means maxWidth = 8-4 = 4, all 3 chars fit
        $frame = new Frame(8, 3);
        $area = new Rect(0, 0, 8, 3);

        $block->render($frame, $area);

        self::assertSame(' ', $frame->characters[0 * 8 + 1]);
        self::assertSame("\u{00FC}", $frame->characters[0 * 8 + 2]);
        self::assertSame("\u{00E4}", $frame->characters[0 * 8 + 3]);
        self::assertSame("\u{00F6}", $frame->characters[0 * 8 + 4]);
        self::assertSame(' ', $frame->characters[0 * 8 + 5]);
    }

    #[Test]
    public function titleTrailingSpacePlacedAtCorrectPositionForMultibyteChars(): void
    {
        $block = Block::new()
            ->withBorderType(BorderType::Solid)
            ->withTitle("\u{00FC}\u{00E4}"); // ua (2 multibyte chars, 4 bytes)
        // width=10 means maxWidth = 10-4 = 6, both chars fit
        $frame = new Frame(10, 3);
        $area = new Rect(0, 0, 10, 3);

        $block->render($frame, $area);

        // Title rendering: space at x+1, title at x+2, trailing space at x+2+mb_strlen(title)
        // mb_strlen("ua") = 2, so trailing space at x+2+2 = 4
        // If strlen were used instead: strlen("ua") = 4, trailing space at x+2+4 = 6 (wrong)
        self::assertSame(' ', $frame->characters[0 * 10 + 1]);
        self::assertSame("\u{00FC}", $frame->characters[0 * 10 + 2]);
        self::assertSame("\u{00E4}", $frame->characters[0 * 10 + 3]);
        self::assertSame(' ', $frame->characters[0 * 10 + 4]);
        // Position 5 should still be the border horizontal, not a space
        $glyphs = BorderType::Solid->glyphs();
        self::assertSame($glyphs->horizontal, $frame->characters[0 * 10 + 5]);
    }

    #[Test]
    public function titleWithMultibyteCharactersTruncatesToMaxWidth(): void
    {
        $block = Block::new()
            ->withBorderType(BorderType::Solid)
            ->withTitle("\u{00FC}\u{00E4}\u{00F6}"); // 3 multibyte chars
        // width=6 means maxWidth = 6-4 = 2, only first 2 chars should render
        $frame = new Frame(6, 3);
        $area = new Rect(0, 0, 6, 3);

        $block->render($frame, $area);

        self::assertSame(' ', $frame->characters[0 * 6 + 1]);
        self::assertSame("\u{00FC}", $frame->characters[0 * 6 + 2]);
        self::assertSame("\u{00E4}", $frame->characters[0 * 6 + 3]);
        self::assertSame(' ', $frame->characters[0 * 6 + 4]);
    }

    #[Test]
    public function titleWithCjkCharactersTruncatesByDisplayWidth(): void
    {
        $block = Block::new()
            ->withBorderType(BorderType::Solid)
            ->withTitle("\u{4F60}\u{597D}\u{4E16}\u{754C}"); // 4 CJK chars = 8 display columns
        // width=10 means maxWidth = 10-4 = 6, only 3 CJK chars fit (3*2=6 columns)
        $frame = new Frame(10, 3);
        $area = new Rect(0, 0, 10, 3);

        $block->render($frame, $area);

        self::assertSame(' ', $frame->characters[0 * 10 + 1]);
        self::assertSame("\u{4F60}", $frame->characters[0 * 10 + 2]);
        self::assertSame('', $frame->characters[0 * 10 + 3]);
        self::assertSame("\u{597D}", $frame->characters[0 * 10 + 4]);
        self::assertSame('', $frame->characters[0 * 10 + 5]);
        self::assertSame("\u{4E16}", $frame->characters[0 * 10 + 6]);
        self::assertSame('', $frame->characters[0 * 10 + 7]);
        self::assertSame(' ', $frame->characters[0 * 10 + 8]);
    }

    #[Test]
    public function cjkTitleTrailingSpaceUsesDisplayWidth(): void
    {
        $block = Block::new()
            ->withBorderType(BorderType::Solid)
            ->withTitle("\u{4F60}\u{597D}"); // 2 CJK chars = 4 display columns
        // width=12 means maxWidth = 12-4 = 8, both chars fit
        $frame = new Frame(12, 3);
        $area = new Rect(0, 0, 12, 3);

        $block->render($frame, $area);

        self::assertSame(' ', $frame->characters[0 * 12 + 1]);
        self::assertSame("\u{4F60}", $frame->characters[0 * 12 + 2]);
        self::assertSame('', $frame->characters[0 * 12 + 3]);
        self::assertSame("\u{597D}", $frame->characters[0 * 12 + 4]);
        self::assertSame('', $frame->characters[0 * 12 + 5]);
        // Trailing space at x+2+displayWidth = 0+2+4 = 6
        self::assertSame(' ', $frame->characters[0 * 12 + 6]);
        // Position 7 should be border horizontal, not a misplaced trailing space
        $glyphs = BorderType::Solid->glyphs();
        self::assertSame($glyphs->horizontal, $frame->characters[0 * 12 + 7]);
    }

    #[Test]
    public function fluentChaining(): void
    {
        $block = Block::new()
            ->withBorderType(BorderType::Solid)
            ->withPadding(Spacing::all(1))
            ->withMargin(Spacing::all(1))
            ->withTitle('Test')
            ->withBorderStyle(new Style())
            ->withTitleStyle(new Style());

        self::assertInstanceOf(Block::class, $block);
    }

    #[Test]
    public function staticNewFactory(): void
    {
        $block = Block::new();

        self::assertInstanceOf(Block::class, $block);
    }

    #[Test]
    public function immutability(): void
    {
        $original = Block::new();
        $withBorder = $original->withBorderType(BorderType::Solid);

        self::assertNotSame($original, $withBorder);

        $areaOriginal = $original->inner(new Rect(0, 0, 20, 10));
        self::assertSame(20, $areaOriginal->width);

        $areaWithBorder = $withBorder->inner(new Rect(0, 0, 20, 10));
        self::assertSame(18, $areaWithBorder->width);
    }

    #[Test]
    public function renderWithAreaEntirelyAboveBufferWritesNothing(): void
    {
        // L125 LessThan / L156 LessThan / L125 LogicalAnd: bounds checks for top/bottom rows
        $frame = new Frame(10, 5);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // Area is at y = -10..-6 — entirely above the buffer
        $block->render($frame, new Rect(0, -10, 10, 5));

        for ($i = 0; $i < 50; ++$i) {
            self::assertSame('.', $frame->characters[$i], "Cell {$i} must remain untouched");
        }
        self::assertCount(50, $frame->characters, 'No out-of-range writes allowed when area is above buffer');
    }

    #[Test]
    public function renderWithAreaEntirelyBelowBufferWritesNothing(): void
    {
        // L125 LessThan: top < bufferHeight — top = bufferHeight must be rejected
        $frame = new Frame(10, 5);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(0, 10, 10, 5));

        for ($i = 0; $i < 50; ++$i) {
            self::assertSame('.', $frame->characters[$i], "Cell {$i} must remain untouched");
        }
        self::assertCount(50, $frame->characters, 'No out-of-range writes allowed when area is below buffer');
    }

    #[Test]
    public function renderWithLeftNegativeSkipsCornerGlyphs(): void
    {
        // L127 LogicalAnd: left >= 0 — when left < 0, corner glyphs must be skipped
        $frame = new Frame(10, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // Area spans x = -2..7 — top-left corner at x = -2 (out of bounds)
        $block->render($frame, new Rect(-2, 0, 10, 3));

        // Top horizontal segment must still render in valid range
        self::assertSame('─', $frame->characters[0]);
        // Top-right corner is in-bounds at x=7
        self::assertSame('┐', $frame->characters[7]);
        self::assertCount(30, $frame->characters, 'No out-of-range writes allowed when left is negative');
    }

    #[Test]
    public function renderWithRightPastBufferSkipsRightCornerGlyphs(): void
    {
        // L135 LogicalAnd: right < bufferWidth — when right >= bufferWidth, right corners skipped
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // Area spans x = 0..9 — right edge at x = 9, past buffer width 5
        $block->render($frame, new Rect(0, 0, 10, 3));

        // Top-left in-bounds at x=0
        self::assertSame('┌', $frame->characters[0]);
        // No top-right glyph anywhere in row 0
        $row0 = '';
        for ($x = 0; $x < 5; ++$x) {
            $row0 .= $frame->characters[$x];
        }
        self::assertStringNotContainsString('┐', $row0, 'Top-right corner glyph must not appear when its column >= bufferWidth');
        self::assertCount(15, $frame->characters, 'No out-of-range writes allowed when right is past buffer width');
    }

    #[Test]
    public function renderSingleRowBlockDoesNotRenderBottomSeparately(): void
    {
        // L156 LogicalAnd / bottom !== top: a 1-row-high block should render only the top edge,
        // not duplicate the bottom corners over the top.
        $frame = new Frame(5, 2);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // Area is 1 row high: top = bottom = 0
        $block->render($frame, new Rect(0, 0, 5, 1));

        // Top row contains the TOP corners and horizontal — not the bottom corners
        self::assertSame('┌', $frame->characters[0], 'Top-left must be ┌, not └');
        self::assertSame('┐', $frame->characters[4], 'Top-right must be ┐, not ┘');
        // Row 1 must be untouched (no bottom border duplicated to row 0 etc.)
        for ($x = 5; $x < 10; ++$x) {
            self::assertSame('.', $frame->characters[$x], "Row 1 cell {$x} must remain untouched");
        }
    }

    #[Test]
    public function renderMarksTopRowDirty(): void
    {
        // L153 TrueValue: $frame->dirtyRows[$top] = true must actually be set
        $frame = new Frame(5, 3);
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(0, 0, 5, 3));

        self::assertArrayHasKey(0, $frame->dirtyRows, 'Top row must be marked dirty');
        self::assertTrue($frame->dirtyRows[0]);
    }

    #[Test]
    public function renderDoesNotWritePastBufferWhenHorizontalSegmentExtendsBeyondRight(): void
    {
        // L143 DecrementInteger / L156 LessThan etc. — when the area's right is beyond bufferWidth,
        // the horizontal segment must clamp at bufferWidth, not write past it.
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(0, 0, 20, 3));

        self::assertCount(15, $frame->characters, 'No writes allowed past bufferWidth');
    }

    #[Test]
    public function renderAtTopEqualsBufferHeightWritesNothing(): void
    {
        // L125 LessThan: top < bufferHeight — boundary case top == bufferHeight must skip
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(0, 3, 5, 3));

        self::assertCount(15, $frame->characters, 'top == bufferHeight must not write');
        for ($i = 0; $i < 15; ++$i) {
            self::assertSame('.', $frame->characters[$i], "Cell {$i} must remain untouched");
        }
    }

    #[Test]
    public function renderAtLeftEqualsBufferWidthWritesNoLeftCorners(): void
    {
        // L127 / L158 LessThan: left < bufferWidth — boundary case left == bufferWidth
        // With `<=` mutation, left=5 writes to topBase+5 which spills into the NEXT row's column 0.
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(5, 0, 5, 3));

        self::assertCount(15, $frame->characters, 'left == bufferWidth must not write left-corner cells');
        // The buffer must contain NO corner/border glyphs anywhere — the area is fully outside.
        for ($i = 0; $i < 15; ++$i) {
            self::assertSame('.', $frame->characters[$i], "Cell {$i} must remain untouched (no corner spill)");
        }
    }

    #[Test]
    public function renderAtRightNegativeWritesNoRightCorners(): void
    {
        // L135 / L166 GreaterThanOrEqualTo: right >= 0 — boundary case right == -1 must skip right corners
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // Area at x=-5, width=5 → right = -1. Right corners at x=-1 should NOT write.
        $block->render($frame, new Rect(-5, 0, 5, 3));

        self::assertCount(15, $frame->characters, 'right == -1 must not produce out-of-range writes');
    }

    #[Test]
    public function renderAtBottomEqualsBufferHeightWritesNoBottomRow(): void
    {
        // L156 LessThan / GreaterThanOrEqualTo: bottom boundary
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // Area at y=-2, height=5 → top=-2, bottom=2. Top is out-of-bounds (skipped), bottom is in-bounds.
        // Both bounds-check branches matter here.
        $block->render($frame, new Rect(0, -2, 5, 5));

        self::assertCount(15, $frame->characters, 'No spurious writes when top is negative but bottom is in-bounds');
        self::assertSame('└', $frame->characters[10], 'Bottom-left must still render');
        self::assertSame('┘', $frame->characters[14], 'Bottom-right must still render');
    }

    #[Test]
    public function renderAtBottomNegativeWritesNoBottomRow(): void
    {
        // L156 GreaterThanOrEqualTo: bottom >= 0 — boundary case bottom == -1 must skip bottom row entirely
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // Area at y=-5, height=4 → top=-5, bottom=-2. Both above buffer.
        $block->render($frame, new Rect(0, -5, 5, 4));

        self::assertCount(15, $frame->characters, 'bottom == -2 (out of range) must not produce writes');
        for ($i = 0; $i < 15; ++$i) {
            self::assertSame('.', $frame->characters[$i], "Cell {$i} must remain untouched");
        }
    }

    #[Test]
    public function renderAtLeftNegativeDoesNotWriteLeftCorner(): void
    {
        // L127 / L158 GreaterThanOrEqualTo: left >= 0 — boundary case left == -1
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // Area at x=-1, width=3 → left=-1, right=1.
        $block->render($frame, new Rect(-1, 0, 3, 3));

        self::assertCount(15, $frame->characters, 'left == -1 must not write a corner glyph at out-of-range index');
        // The right corners are still in-bounds — they should render.
        self::assertSame('┐', $frame->characters[1], 'Right-top corner must render at x=1');
    }

    #[Test]
    public function renderAtRightEqualsBufferWidthDoesNotWriteRightCorner(): void
    {
        // L135 / L166 LessThan: right < bufferWidth — boundary case right == bufferWidth
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // Area at x=1, width=5 → right = 5 == bufferWidth. Right corners out of range.
        $block->render($frame, new Rect(1, 0, 5, 3));

        self::assertCount(15, $frame->characters, 'right == bufferWidth must not write out-of-range');
        // The left corner is in-bounds.
        self::assertSame('┌', $frame->characters[1], 'Left-top corner must render at x=1');
    }

    #[Test]
    public function renderMarksBottomRowDirty(): void
    {
        // L184 TrueValue: $frame->dirtyRows[$bottom] = true
        $frame = new Frame(5, 3);
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(0, 0, 5, 3));

        self::assertArrayHasKey(2, $frame->dirtyRows, 'Bottom row must be marked dirty');
        self::assertTrue($frame->dirtyRows[2]);
    }

    #[Test]
    public function renderMarksMiddleRowDirty(): void
    {
        // L207 TrueValue: middle rows for the vertical sides
        $frame = new Frame(5, 5);
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(0, 0, 5, 5));

        self::assertArrayHasKey(1, $frame->dirtyRows, 'Middle row 1 must be marked dirty by vertical sides');
        self::assertArrayHasKey(2, $frame->dirtyRows, 'Middle row 2 must be marked dirty by vertical sides');
        self::assertTrue($frame->dirtyRows[1]);
        self::assertTrue($frame->dirtyRows[2]);
    }

    #[Test]
    public function renderAtXGreaterThanZeroWritesVerticalSidesAtCorrectColumn(): void
    {
        // L191/L192 Plus: $rowBase + $left must use addition. With subtraction, left=2 would write to column -2.
        $frame = new Frame(10, 4);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(2, 0, 5, 4));

        // Vertical-left at row 1, column 2
        self::assertSame('│', $frame->characters[1 * 10 + 2], 'Vertical-left side must appear at column 2 (rowBase + left = 12)');
        self::assertSame('│', $frame->characters[1 * 10 + 6], 'Vertical-right side must appear at column 6 (rowBase + right = 16)');
        // Column 0,1 of middle rows must remain '.'
        self::assertSame('.', $frame->characters[1 * 10 + 0]);
        self::assertSame('.', $frame->characters[1 * 10 + 1]);
    }

    #[Test]
    public function renderAtBottomExactlyAtBufferTopEdgeStillRendersBottomRow(): void
    {
        // L156 GreaterThanOrEqualTo: bottom >= 0 — boundary case bottom == 0 must still render
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // Area at y=-2, height=3 → top=-2, bottom=0. Only bottom is in-bounds.
        $block->render($frame, new Rect(0, -2, 5, 3));

        self::assertSame('└', $frame->characters[0], 'Bottom-left at row 0 must render when bottom == 0');
        self::assertSame('┘', $frame->characters[4], 'Bottom-right at row 0 must render when bottom == 0');
    }

    #[Test]
    public function renderSingleColumnWritesBothCornersWithRightOverwritingLeft(): void
    {
        // L135 / L166 GreaterThanOrEqualTo: right >= 0 — when right == 0 (width == 1),
        // top-right and bottom-right writes overwrite top-left/bottom-left at the same column.
        $frame = new Frame(3, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(0, 0, 1, 3));

        self::assertSame('┐', $frame->characters[0], 'When width=1, top-right overwrites top-left at column 0');
        self::assertSame('┘', $frame->characters[6], 'When width=1, bottom-right overwrites bottom-left at column 0');
        // Cells in cols 1 and 2 must remain untouched
        self::assertSame('.', $frame->characters[1]);
        self::assertSame('.', $frame->characters[2]);
    }

    #[Test]
    public function renderAtRightEqualsBufferWidthDoesNotSpillIntoNextRow(): void
    {
        // L135 LessThan: right < bufferWidth — when right == bufferWidth, mutation `<=` would
        // write `┐` at topBase + bufferWidth which falls into the next row's column 0.
        // Use a 1-row block (top == bottom) so the bottom section is skipped and no overwrite hides the bug.
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(0, 0, 6, 1));

        self::assertNotSame('┐', $frame->characters[5], 'right == bufferWidth must not spill ┐ into row 1 col 0');
        self::assertSame('.', $frame->characters[5], 'row 1 col 0 must remain untouched');
    }

    #[Test]
    public function renderAtRightEqualsBufferWidthDoesNotWriteVerticalRightPastBuffer(): void
    {
        // L199 LessThan: right < bufferWidth (vertical sides) — same boundary case
        $frame = new Frame(5, 5);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // width=6 → right=5 == bufferWidth; height=5 → there are middle rows for vertical sides
        $block->render($frame, new Rect(0, 0, 6, 5));

        // Vertical sides cover rows 1..3. At col 5 (= bufferWidth), no vertical-right may appear.
        // With mutation `<=`, writes happen at rowBase + 5 which spills into next row col 0.
        // Specifically, row 1's spill would land at row 2 col 0 → would be '│'.
        // We verify that row 2 col 0 is the left-side vertical at row 2, not a spill.
        for ($y = 1; $y < 4; ++$y) {
            self::assertSame('│', $frame->characters[$y * 5 + 0], "Row {$y} col 0 must be the vertical-left side");
        }
    }

    #[Test]
    public function renderVerticalRightAtColumnZeroWhenLeftIsNegative(): void
    {
        // L199 GreaterThanOrEqualTo: right >= 0 → > 0 — when right == 0 and left < 0,
        // the vertical-LEFT is skipped (left < 0), so only vertical-RIGHT writes the side at col 0.
        // With mutation, vertical-right is skipped → col 0 stays untouched.
        $frame = new Frame(3, 5);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(-2, 0, 3, 5));

        // Row 1 col 0 must show vertical glyph — written by vertical-RIGHT only.
        self::assertSame('│', $frame->characters[1 * 3 + 0], 'Vertical-right must render at col 0 when right == 0 and left == -2');
        self::assertSame('│', $frame->characters[2 * 3 + 0]);
    }

    #[Test]
    public function renderVerticalRightDoesNotSpillPastBufferWidth(): void
    {
        // L199 LessThan: right < bufferWidth → <=. When right == bufferWidth, mutation writes past.
        // Use a block whose bottom is out of buffer so no bottom-section write overwrites the spill.
        $frame = new Frame(5, 5);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // width=6 → right=5 (== bufferWidth); height=10 → bottom=9 (out of buffer, skipped)
        $block->render($frame, new Rect(0, 0, 6, 10));

        self::assertCount(25, $frame->characters, 'right == bufferWidth must not produce out-of-range vertical-right writes');
    }

    #[Test]
    public function renderAtBottomEqualsBufferHeightDoesNotWriteBelowBuffer(): void
    {
        // L156 LessThan: bottom < bufferHeight — when bottom == bufferHeight, mutation writes past buffer
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // height=4 → bottom = 3 == bufferHeight
        $block->render($frame, new Rect(0, 0, 5, 4));

        self::assertCount(15, $frame->characters, 'bottom == bufferHeight must not write past buffer');
    }

    #[Test]
    public function renderAtLeftBelowNegativeOneDoesNotWriteNegativeIndices(): void
    {
        // L143 / L174 DecrementInteger: max(0, left+1) → max(-1, left+1). Only differs when left+1 < -1.
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // left = -3, left+1 = -2. Current startX = max(0, -2) = 0. Mutation = max(-1, -2) = -1.
        // With startX=-1, the loop writes to bottomBase + (-1) which spills into row above the bottom.
        $block->render($frame, new Rect(-3, 0, 5, 3));

        self::assertCount(15, $frame->characters, 'startX must clamp to 0 even when left+1 < -1');
        // Specifically: with mutation, characters[9] (row 1, col 4) would be overwritten with '─'
        // by the bottom-horizontal loop starting at x=-1. Currently it stays '.'.
        self::assertSame('.', $frame->characters[9], 'Cells in rows other than bottom must not be overwritten by bottom horizontal');
    }

    #[Test]
    public function renderAtTopNegativeStartsVerticalSidesAtRowZero(): void
    {
        // L187 IncrementInteger: max(0, top+1) → max(1, ...). With top=-1, top+1=0;
        // current startY=0, mutation startY=1. The vertical sides must start at row 0.
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // top=-1 (out of bounds), bottom=2. Vertical sides cover rows 0 and 1.
        $block->render($frame, new Rect(0, -1, 5, 4));

        // Row 0 vertical sides must render (mutation would skip row 0).
        self::assertSame('│', $frame->characters[0 * 5 + 0], 'Vertical-left at row 0 must render when top=-1');
        self::assertSame('│', $frame->characters[0 * 5 + 4], 'Vertical-right at row 0 must render when top=-1');
    }

    #[Test]
    public function renderAtLeftBelowNegativeOneDoesNotSkipBottomHorizontal(): void
    {
        // L174 IncrementInteger: max(0, left+1) → max(1, ...). With left=-2, left+1=-1;
        // current startX=0, mutation startX=1. Bottom horizontal must start at col 0.
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(-2, 0, 6, 3));

        // Bottom horizontal at row 2 col 0 must render — mutation would skip col 0.
        self::assertSame('─', $frame->characters[2 * 5 + 0], 'Bottom horizontal at col 0 must render when left = -2');
    }

    #[Test]
    public function renderWritesWidthOneToBorderCells(): void
    {
        // L133, L141, L151, L164, L197, L205 IncrementInteger: widths[i] = 1 — must remain 1, not 2
        $frame = new Frame(5, 5);
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(0, 0, 5, 5));

        // Corners
        self::assertSame(1, $frame->widths[0], 'top-left width');
        self::assertSame(1, $frame->widths[4], 'top-right width');
        self::assertSame(1, $frame->widths[20], 'bottom-left width');
        self::assertSame(1, $frame->widths[24], 'bottom-right width');
        // Horizontal segments
        self::assertSame(1, $frame->widths[1], 'top horizontal width');
        self::assertSame(1, $frame->widths[21], 'bottom horizontal width');
        // Vertical segments (middle rows)
        self::assertSame(1, $frame->widths[1 * 5 + 0], 'vertical-left side width at row 1');
        self::assertSame(1, $frame->widths[1 * 5 + 4], 'vertical-right side width at row 1');
    }

    #[Test]
    public function renderHorizontalSegmentStartsAfterLeftCorner(): void
    {
        // L143 IncrementInteger: max(0, $left + 1) → max(1, ...) — would skip column 0
        // when left == -1 (left + 1 = 0). Test with left = -1 so left+1 = 0 hits the max boundary.
        $frame = new Frame(5, 3);
        $this->fillBuffer($frame, '.');
        $block = Block::new()->withBorderType(BorderType::Solid);

        // Area at (-1, 0, 5, 3) — top-left corner is at x=-1 (out of bounds),
        // but the horizontal segment must still draw at x = 0 (which is left+1).
        $block->render($frame, new Rect(-1, 0, 5, 3));

        self::assertSame('─', $frame->characters[0], 'Horizontal border must start at column 0 when left = -1 (max(0, left+1) = 0)');
    }

    #[Test]
    public function borderCellsOutsideAnActiveClipStayUntouched(): void
    {
        $frame = new Frame(10, 6);
        $this->fillBuffer($frame, '.');
        $frame->pushClip(new Rect(0, 2, 5, 2));
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(0, 0, 10, 6));

        self::assertSame('│', $frame->characters[2 * 10 + 0], 'Left side inside the clip must render');
        self::assertSame('│', $frame->characters[3 * 10 + 0], 'Left side inside the clip must render');

        for ($y = 0; $y < 6; ++$y) {
            for ($x = 0; $x < 10; ++$x) {
                if ($y >= 2 && $y < 4 && $x < 5) {
                    continue;
                }
                self::assertSame('.', $frame->characters[$y * 10 + $x], "Cell ({$x}, {$y}) is outside the clip");
            }
        }
    }

    #[Test]
    public function aBorderDrawnOverWideCharactersLeavesNoHalfGlyph(): void
    {
        $frame = new Frame(10, 5);
        $frame->putString(0, 2, "\u{4F60}");
        $frame->putString(4, 2, "\u{597D}");
        $block = Block::new()->withBorderType(BorderType::Solid);

        $block->render($frame, new Rect(0, 0, 6, 5));

        self::assertSame('│', $frame->characters[2 * 10 + 0]);
        self::assertSame(' ', $frame->characters[2 * 10 + 1], 'Orphaned continuation must become a blank');
        self::assertSame(1, $frame->widths[2 * 10 + 1]);
        self::assertSame(' ', $frame->characters[2 * 10 + 4], 'Orphaned lead must become a blank');
        self::assertSame(1, $frame->widths[2 * 10 + 4]);
        self::assertSame('│', $frame->characters[2 * 10 + 5]);
    }

    #[Test]
    public function aLinkOnTheBorderAndTitleStylesReachesTheirCells(): void
    {
        $border = (new Style())->withLink('https://example.com/border');
        $title = (new Style(attributes: Attr::BOLD))->withLink('https://example.com/title');
        $frame = new Frame(12, 3);

        Block::new()->withBorderType(BorderType::Solid)->withBorderStyle($border)->withTitle('Hi')->withTitleStyle($title)
            ->render($frame, new Rect(0, 0, 12, 3));

        self::assertSame($border->cellAttributes(), $frame->attributes[0]);
        self::assertSame($border->cellAttributes(), $frame->attributes[12]);
        self::assertSame($title->cellAttributes(), $frame->attributes[3]);
    }

    private function fillBuffer(Frame $frame, string $char): void
    {
        for ($y = 0; $y < $frame->height; ++$y) {
            for ($x = 0; $x < $frame->width; ++$x) {
                $frame->putChar($x, $y, $char);
            }
        }
    }
}
