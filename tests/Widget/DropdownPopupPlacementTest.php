<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\DropdownPopup;
use Elmphant\Tui\Widget\FloatTarget;
use Elmphant\Tui\Widget\Select;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropdownPopupPlacementTest extends TestCase
{
    #[Test]
    public function holdsOneContentWidgetAndSizesItselfAroundIt(): void
    {
        $content = Select::new(['json', 'yaml']);
        $popup = DropdownPopup::of($content, new FloatTarget());

        self::assertSame($content, $popup->content());

        $placement = $popup->placement(new Rect(0, 5, 4, 1), Rect::fromSize(80, 24));

        self::assertSame($content->popupWidth() + 2, $placement->width, 'a border is all the popup adds sideways');
        self::assertSame(
            $content->popupHeight($placement->width - 2) + 2,
            $placement->height,
            'and two rows of it downwards, with no filter row of its own',
        );
    }

    #[Test]
    public function opensDirectlyBelowTheAnchorWithoutCoveringIt(): void
    {
        $placement = self::popup(['json', 'yaml'])
            ->placement(new Rect(10, 5, 20, 1), Rect::fromSize(80, 24));

        self::assertSame(6, $placement->y, 'The row below the anchor');
        self::assertSame(10, $placement->x);
    }

    #[Test]
    public function flipsAboveTheAnchorWhenTheRoomBelowIsShorter(): void
    {
        $placement = self::popup(['json', 'yaml', 'yml'])
            ->placement(new Rect(0, 20, 20, 1), Rect::fromSize(80, 24));

        self::assertLessThan(20, $placement->y, 'It opened upwards');
        self::assertSame(20, $placement->bottom(), 'and stops at the anchor row');
    }

    #[Test]
    public function capsItsHeightAtHalfTheViewportRatherThanTheRoomOnTheSideItOpened(): void
    {
        $many = array_map(static fn (int $index): string => 'option ' . $index, range(1, 40));
        $viewport = Rect::fromSize(80, 24);

        $placement = self::popup($many)->placement(new Rect(0, 2, 20, 1), $viewport);

        self::assertSame(intdiv($viewport->height, 2), $placement->height, 'Half the viewport, well short of the 21 rows actually free below');
    }

    #[Test]
    public function growsToTheWidestOptionAndStaysInsideTheViewport(): void
    {
        $wide = self::popup(['a-very-long-option-value-that-outgrows-the-anchor'])
            ->placement(new Rect(70, 2, 6, 1), Rect::fromSize(80, 24));

        self::assertSame(80, $wide->right(), 'Shifted left rather than overflowing');
        self::assertGreaterThan(6, $wide->width, 'and wider than the anchor');
    }

    #[Test]
    public function neverStartsLeftOfTheViewportItWasGiven(): void
    {
        $wide = self::popup(['a-very-long-option-value-that-outgrows-the-anchor'])
            ->placement(new Rect(0, 2, 6, 1), new Rect(2, 0, 20, 24));

        self::assertSame(2, $wide->x, 'A viewport not starting at zero still bounds the left edge');
        self::assertLessThanOrEqual(20, $wide->width);
    }

    #[Test]
    public function doesNotPadShortOptionListsPastTheAnchorOrTheirOwnWidth(): void
    {
        $placement = self::popup(['json', 'yaml', 'yml'])
            ->placement(new Rect(0, 2, 3, 1), Rect::fromSize(80, 24));

        self::assertSame(6, $placement->width, 'json/yaml is 4 wide, plus 2 for the borders');
    }

    #[Test]
    public function theRoomBelowNeverGoesNegativeWhenTheAnchorOverhangsTheViewport(): void
    {
        $placement = self::popup([])
            ->placement(new Rect(10, 0, 20, 20), Rect::fromSize(80, 5));

        self::assertSame(20, $placement->y, 'Still opens below the anchor, flush with its bottom');
        self::assertSame(0, $placement->height, 'and there is no room for it at all');
    }

    #[Test]
    public function theRoomAboveNeverGoesNegativeWhenTheAnchorStartsFarAboveTheViewport(): void
    {
        $placement = self::popup([])
            ->placement(new Rect(10, -15, 20, 20), Rect::fromSize(80, 5));

        self::assertSame(5, $placement->y, 'Still opens below the anchor, not above it');
        self::assertSame(0, $placement->height);
    }

    #[Test]
    public function theRoomAboveIsMeasuredFromTheViewportTopNotAddedToIt(): void
    {
        $options = array_map(static fn (int $index): string => 'o' . $index, range(1, 16));
        $viewport = new Rect(0, 5, 80, 20);

        $placement = self::popup($options)
            ->placement(new Rect(10, 10, 20, 1), $viewport);

        self::assertSame(11, $placement->y, 'Opens below the anchor');
        self::assertSame(intdiv($viewport->height, 2), $placement->height, 'Capped to half the viewport, well under the 14 rows actually available below');
    }

    #[Test]
    public function theBoundaryWhereWantedExactlyFillsTheRoomBelowStillOpensDownward(): void
    {
        $placement = self::popup(['a', 'b', 'c', 'd'])
            ->placement(new Rect(10, 20, 20, 22), Rect::fromSize(80, 50));

        self::assertSame(42, $placement->y, 'Opens below, flush with the anchor bottom');
    }

    #[Test]
    public function theBoundaryWhereRoomBelowExactlyEqualsRoomAboveOpensDownward(): void
    {
        $placement = self::popup(['a', 'b', 'c', 'd'])
            ->placement(new Rect(10, 15, 20, 30), new Rect(0, 10, 80, 40));

        self::assertSame(45, $placement->y, 'Tied room on both sides opens downward, not upward');
    }

    #[Test]
    public function wantedHeightLeavesExactlyOneRowPerOption(): void
    {
        $popup = self::popup(['a', 'b']);

        $placement = $popup->placement(new Rect(0, 0, 20, 1), Rect::fromSize(80, 24));

        self::assertSame(2, $popup->contentArea($placement)->height, 'with room to spare every option gets a row, and none is spare');
    }

    #[Test]
    public function theScrollbarTakesItsColumnFromInsideRatherThanGrowingPastTheAnchor(): void
    {
        $shortLabelsThatScroll = array_map(static fn (int $index): string => 'option' . $index, range(1, 30));

        $placement = self::popup($shortLabelsThatScroll)->placement(new Rect(0, 0, 30, 1), Rect::fromSize(80, 20));

        self::assertSame(30, $placement->width, 'a popup already wider than its content finds the bar a column in the slack it has');
    }

    #[Test]
    public function aScrollingListWhoseLabelsFillThePopupGetsAColumnForTheBar(): void
    {
        $longLabelsThatScroll = array_map(static fn (int $index): string => str_repeat('x', 20) . $index, range(1, 30));

        $placement = self::popup($longLabelsThatScroll)->placement(new Rect(0, 0, 5, 1), Rect::fromSize(80, 12));

        self::assertSame(25, $placement->width, 'the widest label, two borders and one column for the bar');
    }

    #[Test]
    public function widthReservesOneCellForEachBorderAroundTheWidestOption(): void
    {
        $placement = self::popup(['ab'])->placement(new Rect(0, 0, 1, 1), Rect::fromSize(80, 24));

        self::assertSame(4, $placement->width, 'two cells for the option, one for each border');
    }

    #[Test]
    public function stillShowsAtLeastOneOptionWhenHalfTheViewportIsOnlyChrome(): void
    {
        $many = array_map(static fn (int $index): string => 'option ' . $index, range(1, 40));
        $viewport = Rect::fromSize(80, 7);
        $popup = self::popup($many);

        self::assertSame(
            1,
            intdiv($viewport->height, 2) - 2,
            'Precondition: half of this viewport is two rows of border and a single row over',
        );

        $placement = $popup->placement(new Rect(0, 0, 20, 1), $viewport);

        self::assertLessThanOrEqual($viewport->height, $placement->height, 'Never taller than the viewport itself');
        self::assertSame(1, $popup->contentArea($placement)->height, 'The floor buys exactly one option row, no more');
    }

    #[Test]
    public function letsTheAvailableRoomWinWhenItIsSmallerThanHalfTheViewport(): void
    {
        $many = array_map(static fn (int $index): string => 'option ' . $index, range(1, 40));
        $viewport = Rect::fromSize(80, 30);
        $anchor = new Rect(10, 10, 20, 8);

        $below = $viewport->bottom() - $anchor->bottom();
        self::assertLessThan(intdiv($viewport->height, 2), $below, 'Precondition: room below is tighter than half the viewport');

        $placement = self::popup($many)->placement($anchor, $viewport);

        self::assertSame($below, $placement->height);
    }

    #[Test]
    public function decidesWhetherToFlipUsingTheCappedHeightNotTheRawWantedHeight(): void
    {
        $many = array_map(static fn (int $index): string => 'option ' . $index, range(1, 40));
        $viewport = Rect::fromSize(80, 25);
        $half = intdiv($viewport->height, 2);
        $anchor = new Rect(10, $half + 1, 20, 0);

        $below = $viewport->bottom() - $anchor->bottom();
        $above = $anchor->y - $viewport->y;
        self::assertSame($half, $below, 'Precondition: room below matches the capped height exactly');
        self::assertGreaterThan($below, $above, 'Precondition: raw room above is bigger, tempting an unnecessary flip');

        $placement = self::popup($many)->placement($anchor, $viewport);

        self::assertSame($anchor->bottom(), $placement->y, 'Opens below: the capped height fits in the room below');
        self::assertSame($half, $placement->height);
    }

    /**
     * @param list<string> $options
     */
    private static function popup(array $options): DropdownPopup
    {
        return DropdownPopup::of(Select::new($options), new FloatTarget());
    }
}
