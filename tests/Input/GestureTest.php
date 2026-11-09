<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Gesture;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GestureTest extends TestCase
{
    #[Test]
    public function aGestureTellsTheMouseMessagesThatMakeIt(): void
    {
        $press = new MouseMsg(1, 1, MouseAction::Press, MouseButton::Left);
        $drag = new MouseMsg(2, 1, MouseAction::Motion, MouseButton::Left);
        $wheel = new MouseMsg(1, 1, MouseAction::WheelDown, MouseButton::None);

        self::assertTrue(Gesture::Click->matches($press));
        self::assertTrue(Gesture::Drag->matches($drag));
        self::assertTrue(Gesture::Wheel->matches($wheel));
        self::assertFalse(Gesture::Drag->matches($press));
        self::assertFalse(Gesture::Click->matches($wheel));
    }

    #[Test]
    public function hintsNameAGestureBesideTheKeys(): void
    {
        $hints = (new Keybindings([Actions::SELECT => ['enter']]))->hints(['move on' => Actions::SELECT, 'move card' => Gesture::Drag]);

        self::assertSame(['Enter' => 'move on', 'Drag' => 'move card'], $hints);
    }
}
