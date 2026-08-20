<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\QuitMsg;
use Elmphant\Tui\Widget\Button;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Being held down is only true while the reader is holding it. A button that goes
 * disabled between the press and the release never saw the release, so it went on
 * believing it was held: drawn pressed once enabled again, and firing on the next
 * bare release as though someone had pushed it.
 */
final class ADisabledButtonLetsGoOfThePressTest extends TestCase
{
    #[Test]
    public function aReleaseWithNoPressBehindItStartsNothing(): void
    {
        $area = new Rect(0, 0, 12, 3);
        $press = new MouseMsg(2, 1, MouseAction::Press, MouseButton::Left);
        $release = new MouseMsg(2, 1, MouseAction::Release, MouseButton::Left);

        $held = Button::new('Run', QuitMsg::Quit)->update($press, $area)->model;
        $whileDisabled = $held->withDisabled(true)->update($release, $area)->model;

        $result = $whileDisabled->withDisabled(false)->update($release, $area);

        self::assertNull($result->command, 'nothing was pushed, so nothing runs');
    }
}
