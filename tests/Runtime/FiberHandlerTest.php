<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Runtime\FiberHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class FiberHandlerTest extends TestCase
{
    #[Test]
    public function aFiberSaysItIsFinishedOnceItHasAnswered(): void
    {
        $said = [];
        (new FiberHandler())->start(
            Cmd::fiber(static fn (): Message => new FiberDone('loaded')),
            static function (Message $message) use (&$said): void {
                $said[] = $message instanceof FiberDone ? $message->what : '?';
            },
            static function () use (&$said): void {
                $said[] = 'finished';
            },
        );

        EventLoop::delay(0.01, ($suspension = EventLoop::getSuspension())->resume(...));
        $suspension->suspend();

        self::assertSame(['loaded', 'finished'], $said);
    }
}
