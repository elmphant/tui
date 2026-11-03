<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use ArrayObject;
use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\QuitMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\CommandHandler;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ShutdownAware;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Support\GuardedTerminal;
use Elmphant\Tui\Tests\Support\TestMessage;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramQuitTest extends TestCase
{
    #[Test]
    public function aCommandBatchedAfterAQuitIsNeverStarted(): void
    {
        $handler = new CountingStartHandler();

        Program::new(new QuitThenStartModel())
            ->withTerminal(new VirtualTerminal(40, 10))
            ->withCommandHandler($handler)
            ->start();

        self::assertSame(0, $handler->started);
    }

    #[Test]
    public function aProgramToldToQuitStillHoldsTheTerminalWhileItsModelShutsDown(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        $model = new StateAtShutdownModel($terminal);

        Program::new($model)->withTerminal($terminal)->start();

        self::assertTrue($model->rawModeAtShutdown, 'the model shuts down before the terminal is given back');
        self::assertFalse($terminal->isRawModeEnabled(), 'and afterwards it is');
    }

    #[Test]
    public function aCommandBatchedBeforeAQuitStillStarts(): void
    {
        $handler = new CountingStartHandler();

        Program::new(new StartThenQuitModel())
            ->withTerminal(new VirtualTerminal(40, 10))
            ->withCommandHandler($handler)
            ->start();

        self::assertSame(1, $handler->started);
    }

    #[Test]
    public function noMessageReachesTheModelOnceItAskedToQuit(): void
    {
        $seen = new ArrayObject();

        Program::new(new MessageRecordingModel($seen, quitsOnAnyMessage: true))->withTerminal(GuardedTerminal::of(40, 10))->start();

        self::assertCount(1, $seen);
    }

    #[Test]
    #[RequiresFunction('posix_kill')]
    public function anInterruptStopsTheProgramWithoutTheModelHearingOfIt(): void
    {
        $seen = new ArrayObject();
        EventLoop::delay(0.05, static function (): void {
            posix_kill(posix_getpid(), SIGINT);
        });

        $program = Program::new(new MessageRecordingModel($seen, quitsOnAnyMessage: false))->withTerminal(GuardedTerminal::of(40, 10));
        $started = microtime(true);
        $program->start();

        self::assertLessThan(1.0, microtime(true) - $started, 'the interrupt, not the guard, ended the program');
        self::assertNotContains(QuitMsg::Quit, $seen->getArrayCopy());
    }
}

final readonly class MessageRecordingModel implements Model
{
    /**
     * @param ArrayObject<int, Message> $seen
     */
    public function __construct(
        private ArrayObject $seen,
        private bool $quitsOnAnyMessage,
    ) {
    }

    public function init(): ?Cmd
    {
        return Cmd::batch(Cmd::message(new TestMessage('first')), Cmd::message(new TestMessage('second')));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $this->seen->append($message);

        return new UpdateResult($this, $this->quitsOnAnyMessage ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}

final readonly class CountedStart extends Cmd
{
}

final class StateAtShutdownModel implements Model, ShutdownAware
{
    public ?bool $rawModeAtShutdown = null;

    public function __construct(private readonly VirtualTerminal $terminal)
    {
    }

    public function init(): ?Cmd
    {
        return Cmd::quit();
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }

    public function onShutdown(): void
    {
        $this->rawModeAtShutdown = $this->terminal->isRawModeEnabled();
    }
}

final readonly class QuitThenStartModel implements Model
{
    public function init(): ?Cmd
    {
        return Cmd::batch(Cmd::quit(), new CountedStart());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}

final readonly class StartThenQuitModel implements Model
{
    public function init(): ?Cmd
    {
        return Cmd::batch(new CountedStart(), Cmd::quit());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}

final class CountingStartHandler implements CommandHandler
{
    public int $started = 0;

    public function handles(Cmd $command): bool
    {
        return $command instanceof CountedStart;
    }

    public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure
    {
        ++$this->started;

        return null;
    }
}
