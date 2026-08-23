<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Widget\Choice\OptionRowLayout;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OptionRowLayoutTest extends TestCase
{
    #[Test]
    public function laysTheOptionsSideBySideWithAGapBetweenThem(): void
    {
        $placements = OptionRowLayout::placements(['○ system', '○ pages'], new Rect(0, 0, 40, 1));

        self::assertSame([[0, 0, 8], [10, 0, 7]], $placements);
    }

    #[Test]
    public function startsAFurtherRowRatherThanRunningPastTheRightEdge(): void
    {
        $placements = OptionRowLayout::placements(['○ system', '○ pages'], new Rect(0, 0, 12, 2));

        self::assertSame([[0, 0, 8], [0, 1, 7]], $placements, 'the second option moves down whole, mark included');
    }

    #[Test]
    public function countsTheRowsTheOptionsNeedAtAGivenWidth(): void
    {
        $entries = ['○ system', '○ pages'];

        self::assertSame(1, OptionRowLayout::height($entries, 40));
        self::assertSame(2, OptionRowLayout::height($entries, 12));
    }

    #[Test]
    public function namesTheOptionUnderAPointAndClaimsNoneOfTheGap(): void
    {
        $entries = ['○ system', '○ pages'];
        $area = new Rect(0, 0, 40, 1);

        self::assertSame(0, OptionRowLayout::indexAt($entries, $area, 7, 0), 'its last column still counts');
        self::assertNull(OptionRowLayout::indexAt($entries, $area, 8, 0), 'the gap after it belongs to neither');
        self::assertSame(1, OptionRowLayout::indexAt($entries, $area, 10, 0));
    }

    #[Test]
    public function looksOnlyOnTheRowThePointFellOn(): void
    {
        $entries = ['○ system', '○ pages'];
        $area = new Rect(0, 0, 12, 2);

        self::assertSame(0, OptionRowLayout::indexAt($entries, $area, 3, 0));
        self::assertSame(1, OptionRowLayout::indexAt($entries, $area, 3, 1), 'the wrapped option owns column 3 of the row below');
    }
}
