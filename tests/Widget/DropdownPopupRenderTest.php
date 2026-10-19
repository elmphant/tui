<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\FloatTarget;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Tests\Support\RootPass;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Plan\LayoutPlan;
use Elmphant\Tui\Widget\Popup\DropdownPopup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropdownPopupRenderTest extends TestCase
{
    #[Test]
    public function drawsABorderAndLeavesEverythingInsideToItsContent(): void
    {
        $screen = self::render(self::popupOf(['json', 'yaml']));

        self::assertSame('╭', $screen->textAt(0, 0, 1), 'the border is the popup');
        self::assertSame('json', $screen->textAt(1, 1, 4), 'and the first row inside it is the content');
        self::assertSame('yaml', $screen->textAt(1, 2, 4));
    }

    #[Test]
    public function anAreaExactlyThreeCellsSquareStillDrawsTheBorder(): void
    {
        $frame = new Frame(10, 10);

        self::popupOf([])->render($frame, new Rect(0, 0, 3, 3), new RenderContext(Theme::dark()));

        self::assertSame('╭', ScreenBuffer::fromFrame($frame)->textAt(0, 0, 1));
    }

    #[Test]
    public function aWidthOfOnlyTwoDrawsNothingEvenWithAmpleHeight(): void
    {
        $frame = new Frame(10, 10);

        self::popupOf([])->render($frame, new Rect(0, 0, 2, 10), new RenderContext(Theme::dark()));

        self::assertNotSame('╭', ScreenBuffer::fromFrame($frame)->textAt(0, 0, 1), 'Too narrow: neither dimension guard alone should let this through');
    }

    #[Test]
    public function thePageFillsThePopupEvenWhereNothingElseIsDrawn(): void
    {
        $theme = Theme::solarizedDark();
        $frame = new Frame(30, 10);
        self::assertNotSame(0, $theme->base->backgroundValue(), 'precondition: this theme paints a page at all');

        self::popupOf(['a', 'b'])->render($frame, new Rect(0, 0, 20, 10), new RenderContext($theme));

        self::assertSame($theme->base->backgroundValue(), $frame->background(6 * $frame->width + 5), 'below the options');
        self::assertSame($theme->base->backgroundValue(), $frame->background(2 * $frame->width + 1), 'and behind an option');
    }

    #[Test]
    public function theOptionUnderTheCursorKeepsTheFillItChose(): void
    {
        $theme = Theme::dark();
        $frame = new Frame(30, 10);
        $context = new RenderContext($theme);

        $context->withFocusHere(static fn () => self::popupOf(['json', 'yaml'])->render($frame, new Rect(0, 0, 20, 10), $context));

        self::assertSame($theme->cursorStyle->backgroundValue(), $frame->background(1 * $frame->width + 1));
    }

    #[Test]
    public function theBorderIsDrawnTheWayAnythingHoldingTheKeyboardIs(): void
    {
        $theme = Theme::dark();
        $frame = new Frame(30, 10);

        self::popupOf(['json', 'yaml'])->render($frame, new Rect(0, 0, 20, 7), new RenderContext($theme));

        self::assertNotSame(
            $theme->borderStyle->foregroundValue(),
            $theme->focusedBorderStyle->foregroundValue(),
            'precondition: this theme tells the two apart',
        );
        self::assertSame(
            $theme->focusedBorderStyle->foregroundValue(),
            $frame->foregrounds[0],
            'an open list is what the reader is working in, so it is lit like a focused pane',
        );
    }

    #[Test]
    public function aPreResolvedLayoutPlanDrawsExactlyWhatDirectLayoutDraws(): void
    {
        $area = new Rect(0, 0, 20, 7);
        $popup = self::popupOf(['json', 'yaml', 'toml']);

        $planned = new Frame(30, 10);
        RootPass::draw(RenderPass::new(Theme::dark()), $popup, $planned, $area, LayoutPlan::resolve($popup, $area));

        $direct = new Frame(30, 10);
        $popup->render($direct, $area, new RenderContext(Theme::dark()));

        self::assertSame($direct->characters, $planned->characters);
    }

    /**
     * @param list<string> $options
     */
    private static function popupOf(array $options): DropdownPopup
    {
        return DropdownPopup::of(Select::new($options), new FloatTarget());
    }

    private static function render(DropdownPopup $popup): ScreenBuffer
    {
        $frame = new Frame(30, 10);
        $popup->render($frame, new Rect(0, 0, 20, 7), new RenderContext(Theme::dark()));

        return ScreenBuffer::fromFrame($frame);
    }
}
