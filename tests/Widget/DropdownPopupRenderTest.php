<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\DropdownPopup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropdownPopupRenderTest extends TestCase
{
    #[Test]
    public function drawsTheQueryAboveTheOptionsItLeft(): void
    {
        $screen = self::render(DropdownPopup::of(['yaml', 'yml'], [], 0, 'ya', 0));

        self::assertStringContainsString('ya', $screen->getLine(1), 'The filter row');
        self::assertStringContainsString('yaml', $screen->getLine(3), 'and the options below the divider');
    }

    #[Test]
    public function drawsTheWindowStartingAtItsScrollOffset(): void
    {
        $options = array_map(static fn (int $index): string => 'option' . $index, range(1, 20));

        $screen = self::render(DropdownPopup::of($options, [], 5, '', 5));

        self::assertStringContainsString('option6', $screen->getLine(3), 'The sixth option is the first drawn');
        self::assertStringContainsString('option7', $screen->getLine(4), 'The second row is scroll+1, ascending, not scroll-1');
        self::assertStringNotContainsString('option1 ', $screen->getPlainText());
    }

    #[Test]
    public function anAreaExactlyThreeCellsSquareStillDrawsTheBorder(): void
    {
        $frame = new Frame(10, 10);

        DropdownPopup::of([], [], 0, '', 0)->render($frame, new Rect(0, 0, 3, 3), new RenderContext(Theme::dark()));

        self::assertSame('╭', ScreenBuffer::fromFrame($frame)->getTextAt(0, 0, 1));
    }

    #[Test]
    public function aWidthOfOnlyTwoDrawsNothingEvenWithAmpleHeight(): void
    {
        $frame = new Frame(10, 10);

        DropdownPopup::of([], [], 0, '', 0)->render($frame, new Rect(0, 0, 2, 10), new RenderContext(Theme::dark()));

        self::assertNotSame('╭', ScreenBuffer::fromFrame($frame)->getTextAt(0, 0, 1), 'Too narrow: neither dimension guard alone should let this through');
    }

    #[Test]
    public function theBackgroundIsFilledEvenWhereNothingElseIsDrawn(): void
    {
        $theme = Theme::dark();
        $frame = new Frame(30, 10);

        DropdownPopup::of(['a', 'b'], [], 0, '', 0)->render($frame, new Rect(0, 0, 20, 10), new RenderContext($theme));

        $blankCellBelowTheOptions = 6 * $frame->width + 5;

        self::assertSame(
            $theme->base->foregroundValue(),
            $frame->foreground($blankCellBelowTheOptions),
            'The base style must apply across the whole popup, not just where text is drawn',
        );
    }

    #[Test]
    public function theDividerSitsOnItsOwnRowAndSpansExactlyTheInnerWidth(): void
    {
        $frame = new Frame(30, 10);

        DropdownPopup::of([], [], 0, '', 0)->render($frame, new Rect(0, 0, 20, 10), new RenderContext(Theme::dark()));

        $dividerRow = ScreenBuffer::fromFrame($frame)->getLine(2);

        self::assertNotSame('─', mb_substr($dividerRow, 0, 1), 'Column 0 is the border, not the divider');
        self::assertSame('─', mb_substr($dividerRow, 1, 1), 'The divider starts at the inner left edge');
        self::assertSame('─', mb_substr($dividerRow, 18, 1), 'The divider reaches the inner right edge');
        self::assertNotSame('─', mb_substr($dividerRow, 19, 1), 'and stops before the border column on the right');
    }

    #[Test]
    public function theDividerDrawsWhenInnerHeightIsExactlyTwo(): void
    {
        $frame = new Frame(10, 4);

        DropdownPopup::of([], [], 0, '', 0)->render($frame, new Rect(0, 0, 10, 4), new RenderContext(Theme::dark()));

        self::assertStringContainsString('─', ScreenBuffer::fromFrame($frame)->getLine(2), 'Two inner rows is exactly enough room for the divider');
    }

    #[Test]
    public function theFieldYieldsToALongQueryScrollingItsLeadingCharacterOutOfView(): void
    {
        $query = 'Z' . str_repeat('x', 16);

        $screen = self::render(DropdownPopup::of(['only'], [], 0, $query, 0));

        self::assertStringNotContainsString(
            'Z',
            $screen->getLine(1),
            'The 17-character query overflows the 16-wide field, scrolling the leading Z out of view',
        );
    }

    #[Test]
    public function theFieldNeverBorrowsWidthFromTheCountsGap(): void
    {
        $frame = new Frame(10, 10);

        DropdownPopup::of(['a'], [], 0, '', 0)->render($frame, new Rect(0, 0, 4, 10), new RenderContext(Theme::dark()));

        self::assertStringNotContainsString(
            '1',
            ScreenBuffer::fromFrame($frame)->getLine(1),
            'No room for both the field and the count: the count must yield, not the field',
        );
    }

    #[Test]
    public function theQueryFieldIsRenderedFocused(): void
    {
        $theme = Theme::dark();
        self::assertNotSame(
            $theme->base->foregroundValue(),
            $theme->dim->foregroundValue(),
            'The theme must distinguish the two, or this test proves nothing',
        );

        $frame = new Frame(30, 10);
        DropdownPopup::of(['a', 'b'], [], 0, 'a', 0)->render($frame, new Rect(0, 0, 20, 7), new RenderContext($theme));

        self::assertSame($theme->base->foregroundValue(), $frame->foreground($frame->width + 1));
    }

    #[Test]
    public function theOptionCountIsShownAtItsExactColumn(): void
    {
        $screen = self::render(DropdownPopup::of(['a', 'b', 'c'], [], 0, '', 0));

        self::assertSame('3', $screen->getTextAt(1, 18, 1), 'Three options, drawn at inner->right() minus its own width');
    }

    #[Test]
    public function theOptionWindowIsCappedToExactlyTheAvailableRows(): void
    {
        $options = array_map(static fn (int $index): string => 'opt' . $index, range(1, 8));
        $frame = new Frame(30, 10);

        DropdownPopup::of($options, [], 0, '', 0)->render($frame, new Rect(0, 0, 20, 10), new RenderContext(Theme::dark()));

        $screen = ScreenBuffer::fromFrame($frame)->getPlainText();
        self::assertStringContainsString('opt6', $screen, 'The sixth option fits in the six available rows');
        self::assertStringNotContainsString('opt7', $screen, 'The seventh option has no row to draw into');
    }

    #[Test]
    public function noOptionsDrawWhenThereIsNoRowLeftForThem(): void
    {
        $frame = new Frame(30, 10);

        DropdownPopup::of(['yaml', 'yml', 'json'], [], 0, '', 0)->render($frame, new Rect(0, 0, 8, 3), new RenderContext(Theme::dark()));

        self::assertStringNotContainsString('yaml', ScreenBuffer::fromFrame($frame)->getPlainText());
    }

    #[Test]
    public function onlyTheCursorRowGetsTheHighlightStyleAndTheNormalTone(): void
    {
        $theme = Theme::dark();
        $frame = new Frame(30, 10);

        DropdownPopup::of(['yaml', 'yml', 'json'], [], 1, '', 0)->render($frame, new Rect(0, 0, 20, 8), new RenderContext($theme));

        $cursorRowCell = 4 * $frame->width + 1;
        $otherRowCell = 3 * $frame->width + 1;

        self::assertSame(
            $theme->cursorStyle->backgroundValue(),
            $frame->background($cursorRowCell),
            'The cursor row carries the highlight background',
        );
        self::assertNotSame(
            $theme->cursorStyle->backgroundValue(),
            $frame->background($otherRowCell),
            'A non-cursor row must not carry it',
        );
        self::assertSame(
            $theme->base->foregroundValue(),
            $frame->foreground($otherRowCell),
            'and must use the plain focused tone, not accent or dim',
        );
    }

    #[Test]
    public function drawsTheLabelInsteadOfTheRawOptionValue(): void
    {
        $screen = self::render(DropdownPopup::of(['yml'], ['yml' => 'YAML file'], 0, '', 0));

        self::assertStringContainsString('YAML file', $screen->getLine(3));
        self::assertStringNotContainsString('yml', $screen->getLine(3));
    }

    #[Test]
    public function leavesTheBottomBorderStyledWhenThereIsNoRoomForADivider(): void
    {
        $frame = new Frame(30, 10);
        $context = new RenderContext(Theme::dark());

        DropdownPopup::of(['yaml'], [], 0, '', 0)->render($frame, new Rect(0, 0, 8, 3), $context);

        $bottomBorderRow = 2;
        $bottomBorderIndex = $bottomBorderRow * $frame->width + 1;

        self::assertSame(
            $context->theme->borderStyle->foregroundValue(),
            $frame->foreground($bottomBorderIndex),
            'The bottom border keeps its own colour instead of a divider drawn over it',
        );
    }

    private static function render(DropdownPopup $popup): ScreenBuffer
    {
        $frame = new Frame(30, 10);
        $popup->render($frame, new Rect(0, 0, 20, 7), new RenderContext(Theme::dark()));

        return ScreenBuffer::fromFrame($frame);
    }
}
