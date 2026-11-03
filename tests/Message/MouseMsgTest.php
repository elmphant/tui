<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Message;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MouseMsgTest extends TestCase
{
    #[Test]
    public function inRect(): void
    {
        $msg = new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left);
        $rect = new Rect(0, 0, 10, 10);
        self::assertTrue($msg->inRect($rect));
    }

    #[Test]
    public function notInRect(): void
    {
        $msg = new MouseMsg(15, 5, MouseAction::Press, MouseButton::Left);
        $rect = new Rect(0, 0, 10, 10);
        self::assertFalse($msg->inRect($rect));
    }

    #[Test]
    public function isClick(): void
    {
        $press = new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left);
        self::assertTrue($press->isClick());

        $release = new MouseMsg(0, 0, MouseAction::Release, MouseButton::Left);
        self::assertFalse($release->isClick());
    }

    #[Test]
    public function isDrag(): void
    {
        $drag = new MouseMsg(5, 5, MouseAction::Motion, MouseButton::Left);
        self::assertTrue($drag->isDrag());

        $motion = new MouseMsg(5, 5, MouseAction::Motion, MouseButton::None);
        self::assertFalse($motion->isDrag());
    }

    #[Test]
    public function inRectWithZeroSizeRect(): void
    {
        $msg = new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left);
        $rect = new Rect(5, 5, 0, 0);
        self::assertFalse($msg->inRect($rect));
    }

    #[Test]
    public function isClickOnlyForPress(): void
    {
        self::assertFalse((new MouseMsg(0, 0, MouseAction::Release, MouseButton::Left))->isClick());
        self::assertFalse((new MouseMsg(0, 0, MouseAction::Motion, MouseButton::Left))->isClick());
        self::assertFalse((new MouseMsg(0, 0, MouseAction::WheelUp, MouseButton::None))->isClick());
    }

    #[Test]
    public function isDragRequiresMotionWithButton(): void
    {
        self::assertTrue((new MouseMsg(0, 0, MouseAction::Motion, MouseButton::Left))->isDrag());
        self::assertFalse((new MouseMsg(0, 0, MouseAction::Motion, MouseButton::None))->isDrag());
    }

    #[Test]
    public function constructingWithoutAScreenRectLeavesItNull(): void
    {
        self::assertNull((new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left))->screen);
    }

    #[Test]
    public function withScreenChangesTheScreenRectAndNothingElse(): void
    {
        $screen = new Rect(0, 0, 100, 30);
        $stamped = self::fullyPopulated()->withScreen($screen);

        self::assertSame($screen, $stamped->screen);
        self::assertSame(7, $stamped->x);
        self::assertSame(9, $stamped->y);
        self::assertSame(MouseAction::WheelDown, $stamped->action);
        self::assertSame(MouseButton::Middle, $stamped->button);
        self::assertSame(4, $stamped->repeat);
    }

    private static function fullyPopulated(): MouseMsg
    {
        return new MouseMsg(7, 9, MouseAction::WheelDown, MouseButton::Middle, 4, new Rect(0, 0, 80, 24));
    }
}
