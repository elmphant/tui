<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\CommandHandler;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ProgramState;
use Elmphant\Tui\Runtime\ShutdownAware;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
    public function aProgramToldToQuitIsStoppingUntilItHasTornDown(): void
    {
        $model = new StateAtShutdownModel();
        $program = Program::new($model)->withTerminal(new VirtualTerminal(40, 10));
        $model->program = $program;

        $program->start();

        self::assertSame(ProgramState::Stopping, $model->stateAtShutdown);
        self::assertSame(ProgramState::Stopped, $program->state());
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
}

final readonly class CountedStart extends Cmd
{
}

final class StateAtShutdownModel implements Model, ShutdownAware
{
    /**
     * @var Program<self>|null
     */
    public ?Program $program = null;
    public ?ProgramState $stateAtShutdown = null;

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
        $this->stateAtShutdown = $this->program?->state();
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
