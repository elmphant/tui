<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd\ForkCmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Runtime\ForkHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ForkHandlerTest extends TestCase
{
    #[Test]
    public function withoutForkingTheWorkRunsInTheProgramItselfAndStillAnswers(): void
    {
        $heard = [];
        $finished = false;

        (new ForkHandler(forks: false))->start(
            new ForkCmd(static function (Closure $send): Message {
                $send(new ForkSaid('sent', last: false));

                return new ForkSaid((string)getmypid());
            }),
            static function (Message $message) use (&$heard): void {
                $heard[] = $message instanceof ForkSaid ? $message->what : '?';
            },
            static function () use (&$finished): void {
                $finished = true;
            },
        );
        EventLoop::delay(0.01, ($suspension = EventLoop::getSuspension())->resume(...));
        $suspension->suspend();

        self::assertSame(['sent', (string)getmypid()], $heard);
        self::assertTrue($finished);
    }

    #[Test]
    public function withoutForkingWorkCancelledBeforeItsTurnNeverRuns(): void
    {
        $ran = false;

        $stop = (new ForkHandler(forks: false))->start(
            new ForkCmd(static function (Closure $send) use (&$ran): null {
                $ran = true;

                return null;
            }),
            static function (Message $message): void {
            },
            static function (): void {
            },
        );
        self::assertNotNull($stop);
        $stop();
        EventLoop::delay(0.01, ($suspension = EventLoop::getSuspension())->resume(...));
        $suspension->suspend();

        self::assertFalse($ran);
    }
}
