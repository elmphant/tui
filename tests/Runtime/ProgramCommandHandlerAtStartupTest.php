<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Runtime\CommandHandler;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramCommandHandlerAtStartupTest extends TestCase
{
    #[Test]
    public function aMessageEmittedWhileInitIsDispatchedMayStartATimer(): void
    {
        $final = Program::new(new StartupPingModel())
            ->withTerminal(new VirtualTerminal(40, 10))
            ->withCommandHandler(new AnsweringPingHandler())
            ->start();

        self::assertSame([], $final->errors);
        self::assertTrue($final->timerFired);
    }
}

final readonly class StartupPing extends Cmd
{
}

final readonly class StartupPong implements Message
{
}

final readonly class StartupTimerFired implements Message
{
}

final class StartupPingModel implements Model
{
    /**
     * @var list<string>
     */
    public array $errors = [];

    public bool $timerFired = false;

    public function init(): ?Cmd
    {
        return new StartupPing();
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $next = clone $this;

        return match (true) {
            $message instanceof ErrorMsg => new UpdateResult($next->withError($message->error->getMessage()), Cmd::quit()),
            $message instanceof StartupPong => new UpdateResult($next, Cmd::after(0.01, static fn (): Message => new StartupTimerFired())),
            $message instanceof StartupTimerFired => new UpdateResult($next->withTimerFired(), Cmd::quit()),
            default => new UpdateResult($next),
        };
    }

    public function render(Frame $frame, RenderContext $context): void
    {
    }

    private function withError(string $error): self
    {
        $this->errors[] = $error;

        return $this;
    }

    private function withTimerFired(): self
    {
        $this->timerFired = true;

        return $this;
    }
}

final class AnsweringPingHandler implements CommandHandler
{
    public function handles(Cmd $command): bool
    {
        return $command instanceof StartupPing;
    }

    public function start(Cmd $command, Closure $emit): ?Closure
    {
        $emit(new StartupPong());

        return null;
    }
}
