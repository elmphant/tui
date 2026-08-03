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
    public function capsItsHeightAtTheRoomOnTheSideItOpened(): void
    {
        $many = array_map(static fn (int $index): string => 'option ' . $index, range(1, 40));

        $placement = self::popup($many)->placement(new Rect(0, 2, 20, 1), Rect::fromSize(80, 24));

        self::assertSame(21, $placement->height, 'Everything below the anchor, no more');
        self::assertSame(24, $placement->bottom());
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

    /**
     * @param list<string> $options
     */
    private static function popup(array $options): DropdownPopup
    {
        return DropdownPopup::of($options, [], 0, '', 0);
    }
}
