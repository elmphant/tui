<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Bar\BarSlotRenderer;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BarSlotRendererTest extends TestCase
{
    private RenderContext $context;

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

    #[Test]
    public function renderLeftDrawsOnTheContentRowAlone(): void
    {
        $frame = new Frame(10, 3);
        BarSlotRenderer::renderLeft($frame, Text::new('X'), 0, 1, 10, 0, $this->context);

        // Content should appear on row 1 (y=1), not row 2
        self::assertSame('X', $frame->characters[10]); // row 1, col 0
        self::assertSame(' ', $frame->characters[20]); // row 2, col 0 should be untouched
    }

    #[Test]
    public function renderLeftHandsTheWidgetOneRowOfTheWidthItDeclares(): void
    {
        $capturedRect = null;
        $widget = new class($capturedRect) implements Widget {
            public function __construct(private ?Rect &$capturedRect)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
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
        self::assertSame(1, $capturedRect->width);
    }

    #[Test]
    public function aSlotWithNoWidthOfItsOwnIsNotDrawn(): void
    {
        $frame = new Frame(10, 1);
        $frame->dirtyRows = [];

        BarSlotRenderer::renderLeft($frame, $this->createWidthlessWidget(), 0, 0, 10, 99, $this->context);

        self::assertEmpty($frame->dirtyRows);
        self::assertSame(0, $frame->backgrounds[0]);
    }

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

    #[Test]
    public function renderRightDrawsOnTheContentRowAlone(): void
    {
        $frame = new Frame(10, 3);
        BarSlotRenderer::renderRight($frame, Text::new('X'), 0, 1, 10, 0, $this->context);

        // "X" right-aligned in width 10 => position 9, on row 1
        self::assertSame('X', $frame->characters[19]); // row 1, col 9
        self::assertSame(' ', $frame->characters[29]); // row 2, col 9
    }

    #[Test]
    public function renderRightHandsTheWidgetOneRow(): void
    {
        $capturedRect = null;
        $widget = new class($capturedRect) implements Widget {
            public function __construct(private ?Rect &$capturedRect)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
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

    #[Test]
    public function fillingTheBarBackgroundInUnderASlotMarksTheRow(): void
    {
        $frame = new Frame(10, 1);
        $frame->dirtyRows = [];

        BarSlotRenderer::renderLeft($frame, $this->createEmptyWidget(), 0, 0, 10, 99, $this->context);

        self::assertSame(99, $frame->backgrounds[0]);
        self::assertArrayHasKey(0, $frame->dirtyRows);
    }

    #[Test]
    public function renderCenteredPlacesWidgetInCenter(): void
    {
        $frame = new Frame(20, 1);
        BarSlotRenderer::renderCentered($frame, Text::new('Hi'), 0, 0, 20, 0, $this->context);

        // "Hi" is 2 chars, centered in 20 => (20-2)/2 = 9
        self::assertSame('H', $frame->characters[9]);
        self::assertSame('i', $frame->characters[10]);
    }

    #[Test]
    public function renderCenteredDrawsOnTheContentRowAlone(): void
    {
        $frame = new Frame(10, 3);
        BarSlotRenderer::renderCentered($frame, Text::new('X'), 0, 1, 10, 0, $this->context);

        // "X" centered in 10 => floor((10-1)/2) = 4, on row 1
        self::assertSame('X', $frame->characters[14]); // row 1, col 4
        self::assertSame(' ', $frame->characters[24]); // row 2, col 4
    }

    #[Test]
    public function renderCenteredHandsTheWidgetOneRow(): void
    {
        $capturedRect = null;
        $widget = new class($capturedRect) implements Widget {
            public function __construct(private ?Rect &$capturedRect)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
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

    #[Test]
    public function aSlotDrawsOnTheRowItWasGiven(): void
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

    #[Test]
    public function aCellTheSlotLeftUncolouredTakesTheBarBackground(): void
    {
        $widget = new class implements Widget {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
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

    #[Test]
    public function aCellTheSlotColouredKeepsItsOwnBackground(): void
    {
        $widget = new class implements Widget {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
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

    #[Test]
    public function aDrawnSlotMarksItsRowDirty(): void
    {
        $frame = new Frame(10, 3);
        $frame->dirtyRows = [];

        BarSlotRenderer::renderLeft($frame, Text::new('X'), 0, 1, 10, 0, $this->context);

        self::assertArrayHasKey(1, $frame->dirtyRows);
        self::assertTrue($frame->dirtyRows[1]);
    }

    #[Test]
    public function aDrawnSlotMarksNoOtherRowDirty(): void
    {
        $frame = new Frame(10, 3);
        $frame->dirtyRows = [];

        BarSlotRenderer::renderLeft($frame, Text::new('X'), 0, 2, 10, 0, $this->context);

        self::assertArrayHasKey(2, $frame->dirtyRows);
        self::assertArrayNotHasKey(0, $frame->dirtyRows);
        self::assertArrayNotHasKey(1, $frame->dirtyRows);
    }

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

    #[Test]
    public function aSlotsForegroundReachesTheBar(): void
    {
        $style = new Style(foreground: Color::ansi(AnsiColor::Red));
        $frame = new Frame(10, 1);
        BarSlotRenderer::renderLeft($frame, Text::styled('X', $style), 0, 0, 10, 0, $this->context);

        self::assertSame($style->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function aSlotsAttributesReachTheBar(): void
    {
        $style = new Style(attributes: 4);
        $frame = new Frame(10, 1);
        BarSlotRenderer::renderLeft($frame, Text::styled('X', $style), 0, 0, 10, 0, $this->context);

        self::assertSame(4, $frame->attributes[0]);
    }

    protected function setUp(): void
    {
        $this->context = new RenderContext(Theme::dark());
    }

    private function createWidthlessWidget(): Widget
    {
        return new class implements Widget {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(0), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putChar($area->x, $area->y, 'X');
            }
        };
    }

    private function createEmptyWidget(): Widget
    {
        return new class implements Widget {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }
}
