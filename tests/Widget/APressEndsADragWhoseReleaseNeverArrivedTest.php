<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Widget\Split;
use Elmphant\Tui\Widget\Text;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A divider is only being dragged while the button is down, and the release that
 * says otherwise can go somewhere else — a layer opening over the split takes every
 * message behind it. Left believing it is still held, the split resizes on the next
 * unrelated drag anywhere in it.
 */
final class APressEndsADragWhoseReleaseNeverArrivedTest extends TestCase
{
    #[Test]
    public function aDragSomewhereElseDoesNotGoOnResizing(): void
    {
        $area = new Rect(0, 0, 40, 10);
        $split = Split::row(SplitState::even(2), Text::new('left'), Text::new('right'));

        $grabbed = $split->update($this->mouse(20, MouseAction::Press), $area)->model;
        self::assertNotSame($split, $grabbed, 'precondition: the divider was grabbed');

        // The release went to whatever opened over the split, so the next thing this
        // widget hears is the press that begins something else entirely.
        $regripped = $grabbed->update($this->mouse(4, MouseAction::Press), $area)->model;
        $dragged = $regripped->update($this->mouse(8, MouseAction::Motion), $area)->model;

        self::assertSame(
            SplitState::even(2)->shares,
            $dragged->state()->shares,
            'a drag the reader never started on the divider leaves the panes alone',
        );
    }

    private function mouse(int $x, MouseAction $action): MouseMsg
    {
        return new MouseMsg($x, 5, $action, MouseButton::Left);
    }
}
