<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Widget\DropdownPopup;
use Elmphant\Tui\Widget\FloatTarget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropdownPopupPointerTest extends TestCase
{
    #[Test]
    public function itReportsTheTokenItWasBuiltWith(): void
    {
        $target = new FloatTarget();

        self::assertSame($target, self::popup(['a'], target: $target)->target());
    }

    #[Test]
    public function itCapturesPressAndBothWheelsAndNothingElse(): void
    {
        $popup = self::popup(['a']);

        self::assertTrue($popup->captures(MouseAction::Press));
        self::assertTrue($popup->captures(MouseAction::WheelUp));
        self::assertTrue($popup->captures(MouseAction::WheelDown));
        self::assertFalse($popup->captures(MouseAction::Motion), 'a drag must reach whatever lies beneath');
        self::assertFalse($popup->captures(MouseAction::Release), 'a Split divider needs its Release');
    }

    #[Test]
    public function optionRowsAreTheRowsBetweenTheDividerAndTheBottomBorder(): void
    {
        $popup = self::popup(['a', 'b', 'c']);

        self::assertSame(4, $popup->optionRowsIn(new Rect(0, 0, 10, 8)));
        self::assertSame(0, $popup->optionRowsIn(new Rect(0, 0, 10, 4)));
        self::assertSame(0, $popup->optionRowsIn(new Rect(0, 0, 0, 0)));
    }

    #[Test]
    public function theRowUnderThePointerAnswersWithTheOptionDrawnThere(): void
    {
        $placement = new Rect(0, 4, 10, 8);
        $popup = self::popup(['a', 'b', 'c']);

        self::assertSame('a', $popup->optionAt($placement, 7));
        self::assertSame('b', $popup->optionAt($placement, 8));
        self::assertSame('c', $popup->optionAt($placement, 9));
    }

    #[Test]
    public function theQueryRowTheDividerAndTheBordersAnswerWithNothing(): void
    {
        $placement = new Rect(0, 4, 10, 8);
        $popup = self::popup(['a', 'b', 'c']);

        self::assertNull($popup->optionAt($placement, 4), 'top border');
        self::assertNull($popup->optionAt($placement, 5), 'query row');
        self::assertNull($popup->optionAt($placement, 6), 'divider');
        self::assertNull($popup->optionAt($placement, 11), 'bottom border');
    }

    #[Test]
    public function aRowPastTheLastOptionAnswersWithNothing(): void
    {
        $placement = new Rect(0, 4, 10, 8);

        self::assertNull(self::popup(['a'])->optionAt($placement, 8));
    }

    #[Test]
    public function theScrolledWindowDecidesWhichOptionARowHolds(): void
    {
        $placement = new Rect(0, 4, 10, 8);
        $options = ['a', 'b', 'c', 'd', 'e', 'f'];

        self::assertSame('c', self::popup($options, scroll: 2)->optionAt($placement, 7));
    }

    #[Test]
    public function aPlacementTooNarrowToDrawAnythingIsEmpty(): void
    {
        $placement = self::popup(['a', 'b'])->placement(new Rect(0, 0, 2, 1), Rect::fromSize(2, 20));

        self::assertSame(0, $placement->width, 'a popup that paints nothing must not claim the cells');
        self::assertSame(0, $placement->height);
    }

    #[Test]
    public function aPlacementTooShortToDrawAnythingIsEmpty(): void
    {
        $placement = self::popup(['a', 'b'])->placement(new Rect(0, 0, 10, 1), Rect::fromSize(10, 3));

        self::assertSame(0, $placement->width);
        self::assertSame(0, $placement->height);
    }

    #[Test]
    public function aPlacementExactlyThreeCellsWideStillDraws(): void
    {
        $placement = self::popup(['a'])->placement(new Rect(0, 0, 3, 1), Rect::fromSize(3, 20));

        self::assertSame(3, $placement->width, 'three cells is the narrowest the popup can draw in');
        self::assertGreaterThanOrEqual(3, $placement->height);
    }

    #[Test]
    public function aPlacementExactlyThreeRowsHighStillDraws(): void
    {
        $placement = self::popup(['a'])->placement(new Rect(0, 0, 10, 1), Rect::fromSize(10, 4));

        self::assertSame(3, $placement->height, 'three rows is the shortest the popup can draw in');
        self::assertGreaterThanOrEqual(3, $placement->width);
    }

    /**
     * @param list<string> $options
     */
    private static function popup(array $options, int $scroll = 0, ?FloatTarget $target = null): DropdownPopup
    {
        return DropdownPopup::of($options, [], 0, '', $scroll, $target ?? new FloatTarget());
    }
}
