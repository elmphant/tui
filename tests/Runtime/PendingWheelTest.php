<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Runtime\PendingWheel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PendingWheelTest extends TestCase
{
    #[Test]
    public function handsBackWhatTheNotchesCameToTogether(): void
    {
        $wheel = new PendingWheel();

        $wheel->add($this->notch(MouseAction::WheelDown));
        $wheel->add($this->notch(MouseAction::WheelDown));
        $wheel->add($this->notch(MouseAction::WheelDown));
        $wheel->add($this->notch(MouseAction::WheelUp));

        $taken = $wheel->take();

        self::assertInstanceOf(MouseMsg::class, $taken);
        self::assertSame(MouseAction::WheelDown, $taken->action);
        self::assertSame(2, $taken->repeat, 'three down and one up is two down');
    }

    #[Test]
    public function holdsNothingBackWhenTheNotchesCancelledOut(): void
    {
        $wheel = new PendingWheel();

        $wheel->add($this->notch(MouseAction::WheelUp));
        $wheel->add($this->notch(MouseAction::WheelDown));

        self::assertNull($wheel->take(), 'a hand that went nowhere moves the view nowhere');
    }

    #[Test]
    public function anEventStandingForSeveralNotchesCountsEachOfThem(): void
    {
        $wheel = new PendingWheel();

        $wheel->add($this->notch(MouseAction::WheelUp)->withRepeat(3));

        self::assertSame(3, $wheel->take()?->repeat);
    }

    #[Test]
    public function aTakenTotalStartsTheNextOneFromNothing(): void
    {
        $wheel = new PendingWheel();
        $wheel->add($this->notch(MouseAction::WheelDown));
        $wheel->take();

        $wheel->add($this->notch(MouseAction::WheelDown));

        self::assertSame(1, $wheel->take()?->repeat);
    }

    private function notch(MouseAction $action): MouseMsg
    {
        return new MouseMsg(1, 1, $action, MouseButton::None);
    }
}
