<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\BarSlotRenderer;
use Elmphant\Tui\Widget\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BarSlotRendererTest extends TestCase
{
    private RenderContext $context;

    // --- renderLeft tests ---

    #[Test]
    public function renderLeftPlacesWidgetAtContentX(): void
    {
        $frame = new Frame(20, 1);
        BarSlotRenderer::renderLeft($frame, Text::new('Hi'), 0, 0, 20, 0, $this->context);

        self::assertSame('H', $frame->characters[0]);
        self::assertSame('i', $frame->characters[1]);
    }

    #[Test]
    public function renderLeftWithContentXOffset(): void
    {
        $frame = new Frame(20, 1);
        BarSlotRenderer::renderLeft($frame, Text::new('AB'), 5, 0, 15, 0, $this->context);

        self::assertSame(' ', $frame->characters[4]);
        self::assertSame('A', $frame->characters[5]);
        self::assertSame('B', $frame->characters[6]);
    }

    #[Test]
    public function renderLeftDoesNothingForEmptyWidget(): void
    {
        $frame = new Frame(10, 1);
        $emptyWidget = $this->createEmptyWidget();
        BarSlotRenderer::renderLeft($frame, $emptyWidget, 0, 0, 10, 0, $this->context);

        for ($x = 0; $x < 10; ++$x) {
            self::assertSame(' ', $frame->characters[$x]);
            self::assertSame(0, $frame->foregrounds[$x]);
        }
    }

    /**
     * Kills L25 IncrementInteger: Frame height must be exactly 1, not 2.
     * If height were 2, a widget rendering on row 1 would produce garbage.
     * We verify only row 0 of the destination is affected.
     */
    #[Test]
    public function renderLeftUsesHeightOneForTempBuffer(): void
    {
        $frame = new Frame(10, 3);
        BarSlotRenderer::renderLeft($frame, Text::new('X'), 0, 1, 10, 0, $this->context);

        // Content should appear on row 1 (y=1), not row 2
        self::assertSame('X', $frame->characters[10]); // row 1, col 0
        self::assertSame(' ', $frame->characters[20]); // row 2, col 0 should be untouched
    }

    /**
     * Kills L26 IncrementInteger: Rect height must be 1, not 2.
     * The widget receives a Rect of height 1. If it were 2, the widget might
     * render on row 1 of the temp buffer which doesn't exist (height=1).
     */
    #[Test]
    public function renderLeftPassesHeightOneRectToWidget(): void
    {
        $capturedRect = null;
        $widget = new class($capturedRect) implements Widget {
            public function __construct(private ?Rect &$capturedRect)
            {
            }

            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $this->capturedRect = $area;
                $frame->putChar(0, 0, 'Z');
            }
        };

        $frame = new Frame(10, 1);
        BarSlotRenderer::renderLeft($frame, $widget, 0, 0, 10, 0, $this->context);

        self::assertNotNull($capturedRect);
        self::assertSame(1, $capturedRect->height);
        self::assertSame(10, $capturedRect->width);
    }

    /**
     * Kills L29 DecrementInteger: early-return when renderedWidth === 0, not === -1.
     * An empty widget must not copy anything. If threshold were -1, it would copy width=0 incorrectly
     * (or skip the check and proceed).
     */
    #[Test]
    public function renderLeftEarlyReturnsWhenRenderedWidthIsZero(): void
    {
        $frame = new Frame(10, 1);
        $frame->dirtyRows = [];
        $emptyWidget = $this->createEmptyWidget();

        BarSlotRenderer::renderLeft($frame, $emptyWidget, 0, 0, 10, 99, $this->context);

        // Buffer should remain completely untouched - no dirty rows
        self::assertEmpty($frame->dirtyRows);
    }

    // --- renderRight tests ---

    #[Test]
    public function renderRightPlacesWidgetRightAligned(): void
    {
        $frame = new Frame(20, 1);
        BarSlotRenderer::renderRight($frame, Text::new('Hi'), 0, 0, 20, 0, $this->context);

        // "Hi" is 2 chars, right aligned in 20 => starts at 20-2 = 18
        self::assertSame('H', $frame->characters[18]);
        self::assertSame('i', $frame->characters[19]);
    }

    #[Test]
    public function renderRightWithContentXOffset(): void
    {
        $frame = new Frame(20, 1);
        BarSlotRenderer::renderRight($frame, Text::new('AB'), 2, 0, 18, 0, $this->context);

        // "AB" is 2 chars, contentX=2, contentWidth=18, right aligned => 2 + 18 - 2 = 18
        self::assertSame('A', $frame->characters[18]);
        self::assertSame('B', $frame->characters[19]);
    }

    /**
     * Kills L45 IncrementInteger: same as L25 for renderRight.
     */
    #[Test]
    public function renderRightUsesHeightOneForTempBuffer(): void
    {
        $frame = new Frame(10, 3);
        BarSlotRenderer::renderRight($frame, Text::new('X'), 0, 1, 10, 0, $this->context);

        // "X" right-aligned in width 10 => position 9, on row 1
        self::assertSame('X', $frame->characters[19]); // row 1, col 9
        self::assertSame(' ', $frame->characters[29]); // row 2, col 9
    }

    /**
     * Kills L46 IncrementInteger: Rect height must be 1, not 2.
     */
    #[Test]
    public function renderRightPassesHeightOneRectToWidget(): void
    {
        $capturedRect = null;
        $widget = new class($capturedRect) implements Widget {
            public function __construct(private ?Rect &$capturedRect)
            {
            }

            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $this->capturedRect = $area;
                $frame->putChar(0, 0, 'Z');
            }
        };

        $frame = new Frame(10, 1);
        BarSlotRenderer::renderRight($frame, $widget, 0, 0, 10, 0, $this->context);

        self::assertNotNull($capturedRect);
        self::assertSame(1, $capturedRect->height);
    }

    /**
     * Kills L49 DecrementInteger: same as L29 for renderRight.
     */
    #[Test]
    public function renderRightEarlyReturnsWhenRenderedWidthIsZero(): void
    {
        $frame = new Frame(10, 1);
        $frame->dirtyRows = [];
        $emptyWidget = $this->createEmptyWidget();

        BarSlotRenderer::renderRight($frame, $emptyWidget, 0, 0, 10, 99, $this->context);

        self::assertEmpty($frame->dirtyRows);
    }

    // --- renderCentered tests ---

    #[Test]
    public function renderCenteredPlacesWidgetInCenter(): void
    {
        $frame = new Frame(20, 1);
        BarSlotRenderer::renderCentered($frame, Text::new('Hi'), 0, 0, 20, 0, $this->context);

        // "Hi" is 2 chars, centered in 20 => (20-2)/2 = 9
        self::assertSame('H', $frame->characters[9]);
        self::assertSame('i', $frame->characters[10]);
    }

    /**
     * Kills L66 IncrementInteger: same as L25 for renderCentered.
     */
    #[Test]
    public function renderCenteredUsesHeightOneForTempBuffer(): void
    {
        $frame = new Frame(10, 3);
        BarSlotRenderer::renderCentered($frame, Text::new('X'), 0, 1, 10, 0, $this->context);

        // "X" centered in 10 => floor((10-1)/2) = 4, on row 1
        self::assertSame('X', $frame->characters[14]); // row 1, col 4
        self::assertSame(' ', $frame->characters[24]); // row 2, col 4
    }

    /**
     * Kills L67 IncrementInteger: Rect height must be 1, not 2.
     */
    #[Test]
    public function renderCenteredPassesHeightOneRectToWidget(): void
    {
        $capturedRect = null;
        $widget = new class($capturedRect) implements Widget {
            public function __construct(private ?Rect &$capturedRect)
            {
            }

            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $this->capturedRect = $area;
                $frame->putChar(0, 0, 'Z');
            }
        };

        $frame = new Frame(10, 1);
        BarSlotRenderer::renderCentered($frame, $widget, 0, 0, 10, 0, $this->context);

        self::assertNotNull($capturedRect);
        self::assertSame(1, $capturedRect->height);
    }

    /**
     * Kills L70 DecrementInteger: same as L29 for renderCentered.
     */
    #[Test]
    public function renderCenteredEarlyReturnsWhenRenderedWidthIsZero(): void
    {
        $frame = new Frame(10, 1);
        $frame->dirtyRows = [];
        $emptyWidget = $this->createEmptyWidget();

        BarSlotRenderer::renderCentered($frame, $emptyWidget, 0, 0, 10, 99, $this->context);

        self::assertEmpty($frame->dirtyRows);
    }

    // --- measureRenderedWidth tests (tested indirectly) ---

    /**
     * Kills L81 IncrementInteger: loop starts at $maxWidth - 1, not $maxWidth - 2.
     * If the last column has content, it must be detected. With -2, the last column is skipped.
     */
    #[Test]
    public function measureRenderedWidthDetectsContentInLastColumn(): void
    {
        $widget = new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                // Write only in the very last column
                $frame->putChar($area->width - 1, 0, 'X');
            }
        };

        $frame = new Frame(10, 1);
        BarSlotRenderer::renderLeft($frame, $widget, 0, 0, 10, 0, $this->context);

        // The 'X' should appear at position 9 (last column)
        self::assertSame('X', $frame->characters[9]);
    }

    /**
     * A slot's width is where its characters end. Colour alone does not make a
     * cell content — a widget that paints its style across the whole row, as
     * StatusBar does, would otherwise measure full width and could never be
     * centred or right-aligned.
     */
    #[Test]
    public function aCellCarryingOnlyColourIsNotContent(): void
    {
        $painter = new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putString(0, 0, 'ab');
                // Style with no text, out to the far edge.
                for ($x = 2; $x < 10; ++$x) {
                    $frame->foregrounds[$x] = 42;
                    $frame->backgrounds[$x] = 77;
                    $frame->attributes[$x] = Attr::BOLD;
                }
            }
        };

        $frame = new Frame(10, 1);
        BarSlotRenderer::renderRight($frame, $painter, 0, 0, 10, 0, $this->context);

        self::assertSame('a', $frame->characters[8], 'Two characters wide, so it starts at column 8');
        self::assertSame('b', $frame->characters[9]);
    }

    #[Test]
    public function aSlotOfNothingButColourDrawsNothing(): void
    {
        $blank = new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->foregrounds[4] = 42;
                $frame->backgrounds[3] = 77;
                $frame->attributes[2] = Attr::BOLD;
            }
        };

        $frame = new Frame(10, 1);
        BarSlotRenderer::renderLeft($frame, $blank, 0, 0, 10, 0, $this->context);

        self::assertSame(0, $frame->foregrounds[4], 'Nothing was copied across');
        self::assertSame(0, $frame->backgrounds[3]);
        self::assertSame(0, $frame->attributes[2]);
    }

    /**
     * Kills L82 LogicalOr: Tests that a non-space character at a position is detected even with
     * all other properties being zero/default. This is the first condition in the OR chain.
     */
    #[Test]
    public function measureDetectsNonSpaceCharacterOnly(): void
    {
        $widget = new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->characters[5] = 'Z';
            }
        };

        $frame = new Frame(10, 1);
        BarSlotRenderer::renderLeft($frame, $widget, 0, 0, 10, 0, $this->context);

        // renderedWidth should be 6 (positions 0-5 copied), position 5 must have 'Z'
        self::assertSame('Z', $frame->characters[5]);
        // Position 6 should be untouched (still default space from Buffer init)
        self::assertSame(' ', $frame->characters[6]);
        self::assertSame(0, $frame->foregrounds[6]);
    }

    // --- copyWithBackground tests (tested indirectly) ---

    /**
     * Kills L102 LessThan: loop condition is $x < $width, not $x <= $width.
     * Off-by-one: if it were <=, we'd copy one extra cell.
     */
    #[Test]
    public function copyWithBackgroundCopiesExactWidth(): void
    {
        $widget = new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putChar(0, 0, 'A');
                $frame->putChar(1, 0, 'B');
                $frame->putChar(2, 0, 'C');
            }
        };

        $frame = new Frame(10, 1);
        BarSlotRenderer::renderLeft($frame, $widget, 0, 0, 10, 0, $this->context);

        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame('C', $frame->characters[2]);
        // Characters beyond renderedWidth (3) should remain as default spaces
        self::assertSame(' ', $frame->characters[3]);
        self::assertSame(0, $frame->foregrounds[3]);
    }

    /**
     * Kills L105 GreaterThanOrEqualTo + LogicalOr: bounds check on destX.
     * destX < 0 || destX >= destination->width must skip out-of-bounds cells.
     * If >= became >, cell at width would be written out of bounds.
     */
    #[Test]
    public function copyWithBackgroundSkipsCellsAtExactBufferWidth(): void
    {
        // Widget writes to positions 0-4 (5 chars), place right-aligned so it tries
        // to write beyond buffer width
        $widget = new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                for ($x = 0; $x < 5; ++$x) {
                    $frame->putChar($x, 0, chr(65 + $x));
                }
            }
        };

        // Use renderRight with a small destination buffer to test boundary
        $frame = new Frame(8, 1);
        // contentX=5, contentWidth=5, right-aligned: offsetX = 5 + 5 - 5 = 5
        // This writes at positions 5,6,7,8,9 -- 8 and 9 are out of bounds for width=8
        BarSlotRenderer::renderRight($frame, $widget, 5, 0, 5, 0, $this->context);

        self::assertSame('A', $frame->characters[5]);
        self::assertSame('B', $frame->characters[6]);
        self::assertSame('C', $frame->characters[7]);
        // No crash = boundary check works
    }

    /**
     * Kills L105 LogicalOr: destX < 0 must be checked. If the OR is changed to AND,
     * negative destX wouldn't be caught.
     */
    #[Test]
    public function copyWithBackgroundSkipsNegativeDestX(): void
    {
        $widget = new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                for ($x = 0; $x < 5; ++$x) {
                    $frame->putChar($x, 0, chr(65 + $x));
                }
            }
        };

        // Use renderCentered where centering pushes some cells to negative X
        // contentX=0, contentWidth=5, widget renders 5 chars, centered => offsetX = 0 + floor((5-5)/2) = 0
        // Instead, let's test via renderRight with negative offset
        // contentX = -2, contentWidth=5 => offsetX = -2 + 5 - 5 = -2
        // destX would be -2, -1, 0, 1, 2
        $frame = new Frame(10, 1);
        BarSlotRenderer::renderRight($frame, $widget, -2, 0, 5, 0, $this->context);

        // Cells at destX=-2 and destX=-1 should be skipped, only 0,1,2 should be written
        self::assertSame('C', $frame->characters[0]); // 3rd character
        self::assertSame('D', $frame->characters[1]); // 4th character
        self::assertSame('E', $frame->characters[2]); // 5th character
    }

    /**
     * Kills L108 Multiplication: $destinationY * $destination->width + $destX.
     * If multiplication is changed, the destination index calculation is wrong.
     * We test on row > 0 to ensure the row calculation matters.
     */
    #[Test]
    public function copyWithBackgroundWritesToCorrectRowInDestination(): void
    {
        $frame = new Frame(10, 3);
        BarSlotRenderer::renderLeft($frame, Text::new('Hi'), 0, 2, 10, 0, $this->context);

        // "Hi" on row 2: index = 2 * 10 + 0 = 20
        self::assertSame('H', $frame->characters[20]);
        self::assertSame('i', $frame->characters[21]);
        // Row 0 and row 1 should be untouched
        self::assertSame(' ', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[10]);
    }

    /**
     * Kills L111 DecrementInteger: source background !== 0 check.
     * If 0 is decremented to -1, then background=0 would pass the check
     * and never use fallbackBackground.
     */
    #[Test]
    public function copyWithBackgroundUsesFallbackWhenSourceBackgroundIsZero(): void
    {
        $widget = new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                // Write a character with NO background (background remains 0)
                $frame->characters[0] = 'X';
                $frame->foregrounds[0] = 42;
            }
        };

        $frame = new Frame(10, 1);
        $fallbackBg = 123;
        BarSlotRenderer::renderLeft($frame, $widget, 0, 0, 10, $fallbackBg, $this->context);

        self::assertSame($fallbackBg, $frame->backgrounds[0]);
    }

    /**
     * Kills L111 Ternary: when source has an explicit background, use it instead of fallback.
     */
    #[Test]
    public function copyWithBackgroundPreservesSourceBackgroundWhenNonZero(): void
    {
        $widget = new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putChar(0, 0, 'X', 0, 77, 0);
            }
        };

        $frame = new Frame(10, 1);
        $fallbackBg = 123;
        BarSlotRenderer::renderLeft($frame, $widget, 0, 0, 10, $fallbackBg, $this->context);

        // Source has background=77, should NOT use fallback=123
        self::assertSame(77, $frame->backgrounds[0]);
    }

    /**
     * Kills L116 TrueValue: dirtyRows must be set to true, not false.
     */
    #[Test]
    public function copyWithBackgroundMarksDirtyRowAsTrue(): void
    {
        $frame = new Frame(10, 3);
        $frame->dirtyRows = [];

        BarSlotRenderer::renderLeft($frame, Text::new('X'), 0, 1, 10, 0, $this->context);

        self::assertArrayHasKey(1, $frame->dirtyRows);
        self::assertTrue($frame->dirtyRows[1]);
    }

    /**
     * Additional: verify that only the specific row is marked dirty, not others.
     */
    #[Test]
    public function copyWithBackgroundOnlyMarksCopiedRowAsDirty(): void
    {
        $frame = new Frame(10, 3);
        $frame->dirtyRows = [];

        BarSlotRenderer::renderLeft($frame, Text::new('X'), 0, 2, 10, 0, $this->context);

        self::assertArrayHasKey(2, $frame->dirtyRows);
        self::assertArrayNotHasKey(0, $frame->dirtyRows);
        self::assertArrayNotHasKey(1, $frame->dirtyRows);
    }

    /**
     * Kills L81 IncrementInteger more precisely: with a single-char widget filling the entire
     * width, we test that measureRenderedWidth returns the full width (not width-1).
     */
    #[Test]
    public function measureRenderedWidthIncludesLastColumnContent(): void
    {
        $widget = new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                for ($x = 0; $x < $area->width; ++$x) {
                    $frame->putChar($x, 0, 'X');
                }
            }
        };

        $frame = new Frame(5, 1);
        BarSlotRenderer::renderRight($frame, $widget, 0, 0, 5, 0, $this->context);

        // All 5 chars rendered, right-aligned: 0+5-5=0
        // Every position should be 'X'
        for ($x = 0; $x < 5; ++$x) {
            self::assertSame('X', $frame->characters[$x], "Position $x should be 'X'");
        }
    }

    /**
     * Tests renderCentered with contentX offset to verify centering math.
     */
    #[Test]
    public function renderCenteredWithContentXOffset(): void
    {
        $frame = new Frame(20, 1);
        // contentX=2, contentWidth=16
        BarSlotRenderer::renderCentered($frame, Text::new('AB'), 2, 0, 16, 0, $this->context);

        // "AB" is 2 chars, centered in 16 => 2 + floor((16-2)/2) = 2 + 7 = 9
        self::assertSame('A', $frame->characters[9]);
        self::assertSame('B', $frame->characters[10]);
    }

    /**
     * Tests that foregrounds are correctly copied from source to destination.
     */
    #[Test]
    public function copyTransfersForegrounds(): void
    {
        $style = new Style(foreground: Color::ansi(1));
        $frame = new Frame(10, 1);
        BarSlotRenderer::renderLeft($frame, Text::styled('X', $style), 0, 0, 10, 0, $this->context);

        self::assertSame($style->foregroundValue(), $frame->foregrounds[0]);
    }

    /**
     * Tests that attributes are correctly copied from source to destination.
     */
    #[Test]
    public function copyTransfersAttributes(): void
    {
        $style = new Style(attributes: 4); // ITALIC
        $frame = new Frame(10, 1);
        BarSlotRenderer::renderLeft($frame, Text::styled('X', $style), 0, 0, 10, 0, $this->context);

        self::assertSame(4, $frame->attributes[0]);
    }

    #[Test]
    public function boundsCheckPreventsWriteAtExactBufferWidth(): void
    {
        $widget = new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                for ($x = 0; $x < 3; ++$x) {
                    $frame->putChar($x, 0, chr(65 + $x));
                }
            }
        };

        $frame = new Frame(5, 1);
        BarSlotRenderer::renderLeft($frame, $widget, 4, 0, 5, 0, $this->context);

        self::assertSame('A', $frame->characters[4]);
        self::assertFalse(isset($frame->characters[5]), 'No write at destX == buffer width');
    }

    #[Test]
    public function boundsCheckSkipsCellWithNegativeDestX(): void
    {
        $widget = new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                for ($x = 0; $x < 3; ++$x) {
                    $frame->putChar($x, 0, chr(65 + $x));
                }
            }
        };

        $frame = new Frame(10, 1);
        BarSlotRenderer::renderLeft($frame, $widget, -1, 0, 10, 0, $this->context);

        self::assertFalse(isset($frame->characters[-1]), 'destX=-1 must be skipped');
        self::assertSame('B', $frame->characters[0], 'destX=0 must be written');
        self::assertSame('C', $frame->characters[1], 'destX=1 must be written');
    }

    protected function setUp(): void
    {
        $this->context = new RenderContext(Theme::dark());
    }

    private function createEmptyWidget(): Widget
    {
        return new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                // Intentionally empty -- renders nothing
            }
        };
    }
}
