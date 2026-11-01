<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use ArrayObject;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Tests\Support\GuardedTerminal;
use Elmphant\Tui\Tests\Support\TestMessage;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramDrainBudgetTest extends TestCase
{
    #[Test]
    public function aStormOfMessagesGivesWayToAFrameAfterTheFirstThousandAndTwentyFour(): void
    {
        $clock = new TestClock(100.0);
        $model = new UpdatesPerFrameModel($clock, messages: 1_100, secondsPerUpdate: 0.0);

        Program::new($model)->withTerminal(GuardedTerminal::of(20, 3))->withClock($clock)->start();

        self::assertSame(1_024, $model->updatesAtEachFrame[0]);
    }

    #[Test]
    public function aSlowDrainGivesWayToAFrameOnceItHasTakenFiftyMilliseconds(): void
    {
        $clock = new TestClock(100.0);
        $model = new UpdatesPerFrameModel($clock, messages: 20, secondsPerUpdate: 0.01);

        Program::new($model)->withTerminal(GuardedTerminal::of(20, 3))->withClock($clock)->start();

        self::assertSame(5, $model->updatesAtEachFrame[0]);
    }

    #[Test]
    #[RequiresFunction('posix_kill')]
    public function aResizeThatArrivesWhileAnUpdateAwaitsReachesTheModelBeforeTheFrameOfTheNewSize(): void
    {
        $clock = new TestClock(100.0);
        $terminal = GuardedTerminal::of(40, 10);
        $model = new AwaitsWhileTheTerminalResizesModel($clock);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput('a'));
        EventLoop::delay(0.05, static function () use ($terminal): void {
            $terminal->resizeTo(30, 5);
            posix_kill(posix_getpid(), SIGWINCH);
        });
        EventLoop::delay(0.3, static fn () => $terminal->simulateInput('q'));

        Program::new($model)->withTerminal($terminal)->withClock($clock)->start();

        self::assertSame([true], $model->resizeSeenAtEachNewSizeFrame->getArrayCopy());
    }
}

final readonly class AwaitsWhileTheTerminalResizesModel implements Model
{
    /**
     * @param ArrayObject<int, bool> $resizeSeenAtEachNewSizeFrame
     * @param ArrayObject<int, true> $resizeSeen
     */
    public function __construct(
        private TestClock $clock,
        public ArrayObject $resizeSeenAtEachNewSizeFrame = new ArrayObject(),
        private ArrayObject $resizeSeen = new ArrayObject(),
    ) {
    }

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof ResizeMsg && $message->width === 30) {
            $this->resizeSeen->append(true);
        }
        if ($message instanceof KeyMsg && $message->key === 'a') {
            $this->clock->advance(0.06);
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.1, static fn () => $suspension->resume());
            $suspension->suspend();
        }

        return new UpdateResult($this, $message instanceof KeyMsg && $message->key === 'q' ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        if ($frame->width === 30 && $this->resizeSeenAtEachNewSizeFrame->count() === 0) {
            $this->resizeSeenAtEachNewSizeFrame->append($this->resizeSeen->count() > 0);
        }
    }
}

final readonly class UpdatesPerFrameModel implements Model
{
    /**
     * @param ArrayObject<int, int>  $updatesAtEachFrame
     * @param ArrayObject<int, true> $updates
     */
    public function __construct(
        private TestClock $clock,
        private int $messages,
        private float $secondsPerUpdate,
        public ArrayObject $updatesAtEachFrame = new ArrayObject(),
        private ArrayObject $updates = new ArrayObject(),
    ) {
    }

    public function init(): ?Cmd
    {
        return Cmd::batch(...array_map(static fn (int $index): Cmd => Cmd::message(new TestMessage((string)$index)), range(1, $this->messages)));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $this->updates->append(true);
        $this->clock->advance($this->secondsPerUpdate);
        $last = $message instanceof TestMessage && $message->value === (string)$this->messages;

        return new UpdateResult($this, $last ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        $this->updatesAtEachFrame->append(\count($this->updates));
    }
}
