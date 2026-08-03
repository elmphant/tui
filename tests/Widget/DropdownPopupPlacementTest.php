<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\DropdownPopup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropdownPopupPlacementTest extends TestCase
{
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
        // No options: wanted = CHROME + 1 = 4. The anchor sticks out 15 rows
        // past the bottom of the viewport, so the raw room below is negative
        // and must clamp to 0, not go further negative — a further-negative
        // floor would make "below >= above" false and flip the popup above
        // the anchor with a phantom row of height instead of 0.
        $placement = self::popup([])
            ->placement(new Rect(10, 0, 20, 20), Rect::fromSize(80, 5));

        self::assertSame(20, $placement->y, 'Still opens below the anchor, flush with its bottom');
        self::assertSame(0, $placement->height, 'and there is no room for it at all');
    }

    #[Test]
    public function theRoomAboveNeverGoesNegativeWhenTheAnchorStartsFarAboveTheViewport(): void
    {
        // No options: wanted = 4. Room below is exactly 0 (anchor bottom
        // flush with the viewport bottom). Room above is deeply negative
        // (anchor starts far above the viewport) and must clamp to 0, not a
        // positive floor — a positive floor on "above" would make
        // "below >= above" false and flip the popup upward with height 1
        // instead of 0.
        $placement = self::popup([])
            ->placement(new Rect(10, -15, 20, 20), Rect::fromSize(80, 5));

        self::assertSame(5, $placement->y, 'Still opens below the anchor, not above it');
        self::assertSame(0, $placement->height);
    }

    #[Test]
    public function theRoomAboveIsMeasuredFromTheViewportTopNotAddedToIt(): void
    {
        // 16 options: wanted = 3 + 1 + 16 = 20, capped to half of 20 (10).
        // Room below (14) and room above (5) are both real subtraction. If
        // "above" were computed by addition instead (anchor.y + viewport.y
        // = 15), it would read as 15 > 14 and the popup would flip upward
        // with the wrong height.
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
        // 4 options: wanted = 3 + 1 + 4 = 8, exactly equal to the room below.
        // The room above (20) is far larger, so only the "wanted <= below"
        // boundary decides — and it must open downward at the boundary, not
        // require strictly more room than needed.
        $placement = self::popup(['a', 'b', 'c', 'd'])
            ->placement(new Rect(10, 20, 20, 22), Rect::fromSize(80, 50));

        self::assertSame(42, $placement->y, 'Opens below, flush with the anchor bottom');
    }

    #[Test]
    public function theBoundaryWhereRoomBelowExactlyEqualsRoomAboveOpensDownward(): void
    {
        // 4 options: wanted = 8, bigger than either side (5 each), so only
        // the "below >= above" boundary decides. Tied, it must still open
        // downward.
        $placement = self::popup(['a', 'b', 'c', 'd'])
            ->placement(new Rect(10, 15, 20, 30), new Rect(0, 10, 80, 40));

        self::assertSame(45, $placement->y, 'Tied room on both sides opens downward, not upward');
    }

    #[Test]
    public function wantedHeightIsChromePlusOneRowPerOption(): void
    {
        $placement = self::popup(['a', 'b'])
            ->placement(new Rect(0, 0, 20, 1), Rect::fromSize(80, 24));

        self::assertSame(DropdownPopup::CHROME + 1 + 2, $placement->height);
    }

    #[Test]
    public function widthNeverShrinksBelowTwoPlusBordersEvenWithNoOptions(): void
    {
        $placement = self::popup([])
            ->placement(new Rect(0, 0, 1, 1), Rect::fromSize(80, 24));

        self::assertSame(2, $placement->width, 'An empty list still reserves room for the two side borders');
    }

    #[Test]
    public function widthAccountsForTheLabelNotJustTheRawValue(): void
    {
        $placement = DropdownPopup::of(['x'], ['x' => 'a much longer label'], 0, '', 0)
            ->placement(new Rect(0, 0, 3, 1), Rect::fromSize(80, 24));

        self::assertGreaterThanOrEqual(
            mb_strlen('a much longer label') + 2,
            $placement->width,
            'The label the user sees must fit, not just the opaque value',
        );
    }

    #[Test]
    public function stillShowsAtLeastOneOptionWhenHalfTheViewportFallsBelowTheChrome(): void
    {
        $many = array_map(static fn (int $index): string => 'option ' . $index, range(1, 40));
        $viewport = Rect::fromSize(80, 7);

        self::assertLessThanOrEqual(DropdownPopup::CHROME, intdiv($viewport->height, 2), 'Precondition: half the viewport is at or below the chrome');

        $placement = self::popup($many)->placement(new Rect(0, 0, 20, 1), $viewport);

        self::assertLessThanOrEqual($viewport->height, $placement->height, 'Never taller than the viewport itself');
        self::assertGreaterThanOrEqual(1, $placement->height - DropdownPopup::CHROME - 1, 'At least one option row is visible');
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
        return DropdownPopup::of($options, [], 0, '', 0);
    }
}
