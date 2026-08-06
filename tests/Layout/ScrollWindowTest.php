<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\ScrollWindow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScrollWindowTest extends TestCase
{
    #[Test]
    public function countsTheLastOffsetTheSameWayForEveryScrolledWidget(): void
    {
        self::assertSame(
            13 - 8 + ScrollWindow::BOTTOM_PADDING,
            ScrollWindow::lastOffset(13, 8),
            'the last row may sit one above the floor of the viewport',
        );
        self::assertSame(0, ScrollWindow::lastOffset(4, 8), 'content that fits does not scroll');
    }

    #[Test]
    public function holdsAnOffsetBetweenTheFirstRowAndTheLastItCanReach(): void
    {
        self::assertSame(0, ScrollWindow::clamped(-5, 10), 'the first row is as far up as it goes');
        self::assertSame(10, ScrollWindow::clamped(99, 10), 'and the last offset is as far down');
        self::assertSame(4, ScrollWindow::clamped(4, 10));
    }

    #[Test]
    public function leavesAnOffsetAloneWhileWhatMustBeSeenIsAlreadyOnScreen(): void
    {
        self::assertSame(2, ScrollWindow::showing(2, 3, 1, 4, 10), 'rows 2 to 5 are drawn, and row 3 is one of them');
    }

    #[Test]
    public function scrollsDownJustFarEnoughToReachWhatSitsBelow(): void
    {
        self::assertSame(3, ScrollWindow::showing(0, 5, 2, 4, 10), 'the last of the two rows lands on the last drawn row');
    }

    #[Test]
    public function scrollsUpToTheTopOfWhatSitsAbove(): void
    {
        self::assertSame(1, ScrollWindow::showing(6, 1, 2, 4, 10), 'coming back up shows its first row, not its last');
    }

    #[Test]
    public function showsTheTopOfSomethingTallerThanTheRoomThereIsForIt(): void
    {
        self::assertSame(2, ScrollWindow::showing(0, 2, 9, 4, 10), 'its first row, since its last cannot be reached');
    }
}
