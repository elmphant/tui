<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Widget\Split;
use Elmphant\Tui\Widget\Text;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Narrow the terminal and the panes have to give ground. What they give comes from
 * whatever sits above its floor, so a pane already at its floor keeps it while the
 * roomy ones shrink — and every column is still handed out.
 */
final class ASqueezedSplitTakesFromWhatHasRoomTest extends TestCase
{
    #[Test]
    public function thePaneWithRoomGivesGroundAndTheOneAtItsFloorKeepsIt(): void
    {
        $split = Split::row(new SplitState([100, 900]), Text::new('left'), Text::new('right'))
            ->withMinimums(10, 4);

        self::assertSame([10, 29], $this->widthsIn($split, 40), 'roomy at 40 columns');
        $squeezed = $this->widthsIn($split, 20);

        self::assertSame(
            [10, 9],
            $squeezed,
            'the right pane gave up its slack; the left was already on its floor',
        );
        self::assertSame(20, array_sum($squeezed) + 1, 'and every column is still handed out, gap included');
    }

    /**
     * @return list<int>
     */
    private function widthsIn(Split $split, int $width): array
    {
        $widths = [];

        foreach ($split->childWidgets(new Rect(0, 0, $width, 6)) as [, $rect]) {
            $widths[] = $rect->width;
        }

        return $widths;
    }
}
