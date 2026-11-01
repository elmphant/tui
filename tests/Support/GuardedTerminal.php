<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Support;

use Elmphant\Tui\Testing\VirtualTerminal;
use Revolt\EventLoop;

final class GuardedTerminal
{
    public static function of(int $width, int $height): VirtualTerminal
    {
        $terminal = new VirtualTerminal($width, $height);
        // A program that never quits ends with its input instead of hanging the suite.
        EventLoop::unreference(EventLoop::delay(3.0, static fn () => $terminal->closeInput()));

        return $terminal;
    }
}
