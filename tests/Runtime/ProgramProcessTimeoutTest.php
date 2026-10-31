<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\ProcessFinishedMsg;
use Elmphant\Tui\Message\ProcessOutputMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramProcessTimeoutTest extends TestCase
{
    #[Test]
    public function aLineFramedProcessThatTimesOutStillHandsOnItsLastUnfinishedLine(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $typist = EventLoop::delay(4.0, static fn () => $terminal->simulateInput('q'));

        Program::new(new TimingOutModel())->withTerminal($terminal)->start();
        EventLoop::cancel($typist);

        self::assertSame(['partial'], TimingOutModel::$lines);
    }

    #[Test]
    public function aStreamingProcessThatTimesOutStillSaysItFinished(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $typist = EventLoop::delay(4.0, static fn () => $terminal->simulateInput('q'));

        Program::new(new TimingOutModel())->withTerminal($terminal)->start();
        EventLoop::cancel($typist);

        self::assertSame([ErrorMsg::class, ProcessFinishedMsg::class], TimingOutModel::$endings);
    }

    #[Test]
    public function aProcessTimesOutWhileTheQueueNeverEmpties(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $safety = EventLoop::delay(3.0, static fn () => $terminal->closeInput());

        Program::new(new BusyWhileTimingOutModel())->withTerminal($terminal)->start();
        EventLoop::cancel($safety);

        self::assertTrue(BusyWhileTimingOutModel::$timedOut);
    }
}

final readonly class KeepBusy implements Message
{
}

final class BusyWhileTimingOutModel implements Model
{
    public static bool $timedOut = false;

    public function init(): ?Cmd
    {
        self::$timedOut = false;

        return Cmd::batch(Cmd::exec(['sleep', '10'])->withTimeout(0.05), Cmd::message(new KeepBusy()));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof ErrorMsg) {
            self::$timedOut = true;

            return new UpdateResult($this, Cmd::quit());
        }

        return new UpdateResult($this, $message instanceof KeepBusy ? Cmd::message(new KeepBusy()) : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}

final class TimingOutModel implements Model
{
    /**
     * @var list<string>
     */
    public static array $lines = [];

    /**
     * @var list<class-string<Message>>
     */
    public static array $endings = [];

    public function init(): ?Cmd
    {
        self::$lines = [];
        self::$endings = [];

        return Cmd::process([PHP_BINARY, '-r', 'echo "partial"; sleep(10);'])->withLineFraming()->withTimeout(0.5);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof ProcessOutputMsg) {
            self::$lines[] = $message->output;
        }
        if ($message instanceof ErrorMsg || $message instanceof ProcessFinishedMsg) {
            self::$endings[] = $message::class;
        }

        return new UpdateResult($this, $message instanceof KeyMsg || $message instanceof ProcessFinishedMsg ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
