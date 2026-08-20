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
 * Equal shares of an odd number of columns cannot come out even. Whatever is left
 * over has to land somewhere, or the split paints narrower than the room it was
 * given and a column of the terminal stays blank.
 */
final class ASplitHandsOutEveryColumnTest extends TestCase
{
    #[Test]
    public function theColumnThatWillNotDivideGoesToTheLastPane(): void
    {
        $split = Split::row(SplitState::even(3), Text::new('a'), Text::new('b'), Text::new('c'));

        $widths = [];
        foreach ($split->childWidgets(new Rect(0, 0, 12, 6)) as [, $rect]) {
            $widths[] = $rect->width;
        }

        self::assertSame([3, 3, 4], $widths, 'ten columns among three panes leaves one over');
        self::assertSame(12, array_sum($widths) + 2, 'and with the two gaps, nothing is left unpainted');
    }
}
