<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

final class ProgramFiberTest extends TestCase
{
    #[Test]
    public function theProgramKeepsTickingWhileAFiberWaitsAndThenGetsItsMessage(): void
    {
        $model = new FiberLoggingModel(Cmd::batch(
            Cmd::fiber(self::waitingFor(0.1, new FiberDone('loaded')), EffectId::named('load')),
            Cmd::tick(0.01),
        ));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertContains('tick', $model->log);
        self::assertSame('loaded', end($model->log));
        self::assertLessThan(array_search('loaded', $model->log, true), array_search('tick', $model->log, true));
    }

    #[Test]
    public function theFirstFrameIsDrawnBeforeWorkSetupAskedForRuns(): void
    {
        $model = new FrameOrderModel();

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertSame('frame', $model->log[0] ?? null);
    }

    #[Test]
    public function aMappedFibersMessageArrivesWrapped(): void
    {
        $model = new FiberLoggingModel(Cmd::fiber(self::waitingFor(0.01, new FiberDone('loaded')))->map(
            static fn (Message $message): Message => new FiberDone('wrapped ' . ($message instanceof FiberDone ? $message->what : '?')),
        ));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertSame(['wrapped loaded'], $model->log);
    }

    #[Test]
    public function aFiberThatThrowsSendsAnErrorFromItsEffect(): void
    {
        $model = new FiberLoggingModel(Cmd::fiber(static function (): ?Message {
            EventLoop::delay(0.01, ($suspension = EventLoop::getSuspension())->resume(...));
            $suspension->suspend();

            throw new RuntimeException('offline');
        }, EffectId::named('load')));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertSame(['error from load: fiber offline'], $model->log);
    }

    #[Test]
    public function aFiberThatAnswersWithSomethingElseThanAMessageSendsAnError(): void
    {
        $model = new FiberLoggingModel(Cmd::fiber(static fn (): string => 'loaded'));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertSame(['error: fiber Cmd::fiber() work must return a Message or null'], $model->log);
    }

    #[Test]
    public function aCancelledFiberIsNotHeardFromAgain(): void
    {
        $model = new FiberLoggingModel(Cmd::batch(
            Cmd::fiber(self::waitingFor(0.05, new FiberDone('late')), EffectId::named('load')),
            Cmd::cancel(EffectId::named('load')),
            Cmd::after(0.15, static fn (): Message => new FiberDone('quiet')),
        ));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertSame(['quiet'], $model->log);
    }

    #[Test]
    public function aFiberStartedUnderTheSameNameReplacesTheOneBefore(): void
    {
        $model = new FiberLoggingModel(Cmd::batch(
            Cmd::fiber(self::waitingFor(0.05, new FiberDone('first')), EffectId::named('load')),
            Cmd::fiber(self::waitingFor(0.1, new FiberDone('second')), EffectId::named('load')),
        ));

        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertSame(['second'], $model->log);
    }

    #[Test]
    public function quittingWhileAFiberWaitsReturnsAtOnceAndItsLateAnswerGoesNowhere(): void
    {
        $model = new FiberLoggingModel(Cmd::batch(
            Cmd::fiber(self::waitingFor(0.3, new FiberDone('late'))),
            Cmd::quit(),
        ));

        $start = hrtime(true);
        Program::new($model)->withTerminal(new VirtualTerminal(20, 2))->start();
        $took = (hrtime(true) - $start) / 1e9;

        EventLoop::delay(0.4, ($suspension = EventLoop::getSuspension())->resume(...));
        $suspension->suspend();

        self::assertLessThan(0.2, $took);
        self::assertSame([], $model->log);
    }

    /**
     * @return Closure(): ?Message
     */
    private static function waitingFor(float $seconds, ?Message $result): Closure
    {
        return static function () use ($seconds, $result): ?Message {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay($seconds, static fn () => $suspension->resume());
            $suspension->suspend();

            return $result;
        };
    }
}
