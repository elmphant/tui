<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\PositionedOverlay;
use Elmphant\Tui\Widget\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PositionedOverlayTest extends TestCase
{
    #[Test]
    public function rendersContentAtAbsoluteCoordinates(): void
    {
        $frame = new Frame(20, 10);
        $context = new RenderContext(Theme::dark());
        $overlay = PositionedOverlay::at(5, 3)->content(Text::new('Hi'));

        $overlay->render($frame, new Rect(0, 0, 20, 10), $context);

        self::assertSame('H', $frame->characters[3 * 20 + 5]);
        self::assertSame('i', $frame->characters[3 * 20 + 6]);
    }

    #[Test]
    public function explicitSizeIsUsedOverComputedSize(): void
    {
        // L57/L58 Coalesce: when $this->width/height is set, it must be used (not the computed expression).
        $frame = new Frame(20, 10);
        $context = new RenderContext(Theme::dark());
        $overlay = PositionedOverlay::at(0, 0)
            ->withSize(3, 1)
            ->content(Text::new('abcdefghij'));

        $overlay->render($frame, new Rect(0, 0, 20, 10), $context);

        self::assertSame('a', $frame->characters[0]);
        self::assertSame('b', $frame->characters[1]);
        self::assertSame('c', $frame->characters[2]);
        self::assertSame(' ', $frame->characters[3], 'Explicit width must clip the text');
    }

    #[Test]
    public function defaultWidthSubtractsXOffsetFromAreaWidth(): void
    {
        // L57 Minus: area.width - (x - area.x). Mutation `+` would not shrink.
        $frame = new Frame(15, 1);
        for ($i = 0; $i < 15; ++$i) {
            $frame->putChar($i, 0, '.');
        }
        $context = new RenderContext(Theme::dark());
        $overlay = PositionedOverlay::at(10, 0)->content(Text::new('abcdefghij'));

        $overlay->render($frame, new Rect(0, 0, 15, 1), $context);

        self::assertSame('.', $frame->characters[9], 'Cell before overlay must be untouched');
        self::assertSame('a', $frame->characters[10]);
        self::assertSame('e', $frame->characters[14]);
    }

    #[Test]
    public function defaultWidthUsesOffsetRelativeToAreaX(): void
    {
        // L57 Minus (second): area.width - (x - area.x) vs area.width - (x + area.x)
        $frame = new Frame(30, 1);
        for ($i = 0; $i < 30; ++$i) {
            $frame->putChar($i, 0, '.');
        }
        $context = new RenderContext(Theme::dark());
        $overlay = PositionedOverlay::at(10, 0)->content(Text::new('abcdefghij'));

        $overlay->render($frame, new Rect(5, 0, 20, 1), $context);

        self::assertSame('a', $frame->characters[10]);
        self::assertSame('j', $frame->characters[19], 'All 10 chars must render — width subtracts relative offset (x - area.x)');
    }

    #[Test]
    public function defaultHeightUsesConstraintWhenExactConstraintSet(): void
    {
        // L59 ternary / LogicalAnd: when constraint->min === max && min > 0, use constraint.
        $frame = new Frame(20, 10);
        for ($i = 0; $i < 200; ++$i) {
            $frame->putChar($i % 20, intdiv($i, 20), '.');
        }
        $context = new RenderContext(Theme::dark());
        $overlay = PositionedOverlay::at(0, 5)->content(Text::new('hi'));

        $overlay->render($frame, new Rect(0, 0, 20, 10), $context);

        self::assertSame('h', $frame->characters[5 * 20 + 0]);
        self::assertSame('.', $frame->characters[6 * 20 + 0], 'Overlay height must respect exact-constraint, not flex into available area');
    }

    #[Test]
    public function returnsWithoutRenderingWhenWidthIsZero(): void
    {
        // L64 LogicalOr/LessThanOrEqualTo: width <= 0 must short-circuit
        $frame = new Frame(5, 5);
        for ($i = 0; $i < 25; ++$i) {
            $frame->putChar($i % 5, intdiv($i, 5), '.');
        }
        $context = new RenderContext(Theme::dark());
        $overlay = PositionedOverlay::at(0, 0)->withSize(0, 2)->content(Text::new('abc'));

        $overlay->render($frame, new Rect(0, 0, 5, 5), $context);

        for ($i = 0; $i < 25; ++$i) {
            self::assertSame('.', $frame->characters[$i], "Cell {$i} must remain untouched when width=0");
        }
    }

    #[Test]
    public function returnsWithoutRenderingWhenHeightIsZero(): void
    {
        // L64 LogicalOr: height <= 0 must short-circuit
        $frame = new Frame(5, 5);
        for ($i = 0; $i < 25; ++$i) {
            $frame->putChar($i % 5, intdiv($i, 5), '.');
        }
        $context = new RenderContext(Theme::dark());
        $overlay = PositionedOverlay::at(0, 0)->withSize(3, 0)->content(Text::new('abc'));

        $overlay->render($frame, new Rect(0, 0, 5, 5), $context);

        for ($i = 0; $i < 25; ++$i) {
            self::assertSame('.', $frame->characters[$i], "Cell {$i} must remain untouched when height=0");
        }
    }

    #[Test]
    public function defaultHeightWithNoConstraintUsesAreaRemainder(): void
    {
        // L59/L61: when constraint is not exact (min !== max or min == 0), height = max(0, area.height - (y - area.y)).
        // Use a multi-line widget OR a widget with flex constraint to hit the else branch.
        // PositionedOverlay's render passes a Rect with the computed height — if widget renders all rows, we can detect.
        $frame = new Frame(5, 10);
        $context = new RenderContext(Theme::dark());

        // Widget that fills every row of its given area with '#'
        $widget = new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::flex();
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                for ($y = $area->y; $y < $area->y + $area->height; ++$y) {
                    $frame->putChar($area->x, $y, '#');
                }
            }
        };

        $overlay = PositionedOverlay::at(0, 3)->content($widget);
        $overlay->render($frame, new Rect(0, 0, 5, 10), $context);

        // Height = 10 - (3 - 0) = 7. Widget should fill rows 3..9.
        for ($y = 3; $y <= 9; ++$y) {
            self::assertSame('#', $frame->characters[$y * 5 + 0], "Row {$y} col 0 should be '#'");
        }
    }

    #[Test]
    public function renderWithNoContentDoesNothing(): void
    {
        // Render with no content() called — must return without rendering anything
        $frame = new Frame(5, 5);
        for ($i = 0; $i < 25; ++$i) {
            $frame->putChar($i % 5, intdiv($i, 5), '.');
        }
        $context = new RenderContext(Theme::dark());
        $overlay = PositionedOverlay::at(0, 0); // No content

        $overlay->render($frame, new Rect(0, 0, 5, 5), $context);

        for ($i = 0; $i < 25; ++$i) {
            self::assertSame('.', $frame->characters[$i], "Cell {$i} must remain untouched without content");
        }
    }
}
