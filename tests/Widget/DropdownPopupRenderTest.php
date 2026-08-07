<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\DropdownPopup;
use Elmphant\Tui\Widget\FloatTarget;
use Elmphant\Tui\Widget\Select;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropdownPopupRenderTest extends TestCase
{
    #[Test]
    public function drawsABorderAndLeavesEverythingInsideToItsContent(): void
    {
        $screen = self::render(self::popupOf(['json', 'yaml']));

        self::assertSame('╭', $screen->getTextAt(0, 0, 1), 'the border is the popup');
        self::assertSame('json', $screen->getTextAt(1, 1, 4), 'and the first row inside it is the content');
        self::assertSame('yaml', $screen->getTextAt(2, 1, 4));
    }

    #[Test]
    public function anAreaExactlyThreeCellsSquareStillDrawsTheBorder(): void
    {
        $frame = new Frame(10, 10);

        self::popupOf([])->render($frame, new Rect(0, 0, 3, 3), new RenderContext(Theme::dark()));

        self::assertSame('╭', ScreenBuffer::fromFrame($frame)->getTextAt(0, 0, 1));
    }

    #[Test]
    public function aWidthOfOnlyTwoDrawsNothingEvenWithAmpleHeight(): void
    {
        $frame = new Frame(10, 10);

        self::popupOf([])->render($frame, new Rect(0, 0, 2, 10), new RenderContext(Theme::dark()));

        self::assertNotSame('╭', ScreenBuffer::fromFrame($frame)->getTextAt(0, 0, 1), 'Too narrow: neither dimension guard alone should let this through');
    }

    #[Test]
    public function theBackgroundIsFilledEvenWhereNothingElseIsDrawn(): void
    {
        $theme = Theme::solarizedDark();
        $frame = new Frame(30, 10);

        self::popupOf(['a', 'b'])->render($frame, new Rect(0, 0, 20, 10), new RenderContext($theme));

        $blankCellBelowTheOptions = 6 * $frame->width + 5;

        self::assertNotSame(0, $theme->base->backgroundValue(), 'Precondition: this theme paints a background at all');
        self::assertSame(
            $theme->base->backgroundValue(),
            $frame->background($blankCellBelowTheOptions),
            'The base background must fill the whole popup, not just where text is drawn',
        );
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
