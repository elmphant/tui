<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\NamesItsEffect;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Runtime\CommandHandler;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

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

    #[Test]
    public function anEffectCancelledInAnswerToItsFirstMessageIsStopped(): void
    {
        $handler = new StoppableFeedHandler();

        $final = Program::new(new CancellingFeedModel($handler))
            ->withTerminal(new VirtualTerminal(40, 10))
            ->withCommandHandler($handler)
            ->start();

        self::assertTrue($final->stoppedBeforeQuitting);
    }

    #[Test]
    public function aHandlerThatFailsToStartDoesNotKeepLaterMessagesWaiting(): void
    {
        $final = Program::new(new FirstArrivalModel(hrtime(true)))
            ->withTerminal(new VirtualTerminal(40, 10))
            ->withCommandHandler(new FailingStartHandler())
            ->withCommandHandler(new LateEmittingHandler())
            ->start();

        self::assertNotNull($final->secondsUntilTheLateMessage);
        self::assertLessThan(0.5, $final->secondsUntilTheLateMessage, 'it woke the program rather than waiting for the next timer');
    }
}

final readonly class FailToStart extends Cmd
{
}

final readonly class EmitLater extends Cmd
{
}

final readonly class LateMessage implements Message
{
}

final readonly class SafetyTimeout implements Message
{
}

final readonly class FirstArrivalModel implements Model
{
    public function __construct(
        private int|float $startedAt,
        public ?float $secondsUntilTheLateMessage = null,
    ) {
    }

    public function init(): ?Cmd
    {
        return Cmd::batch(
            new FailToStart(),
            new EmitLater(),
            Cmd::after(1.0, static fn (): Message => new SafetyTimeout()),
        );
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return match (true) {
            $message instanceof LateMessage => new UpdateResult(new self($this->startedAt, (hrtime(true) - $this->startedAt) / 1e9), Cmd::quit()),
            $message instanceof SafetyTimeout => new UpdateResult($this, Cmd::quit()),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}

final class FailingStartHandler implements CommandHandler
{
    public function handles(Cmd $command): bool
    {
        return $command instanceof FailToStart;
    }

    public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure
    {
        throw new RuntimeException('cannot start');
    }
}

final class LateEmittingHandler implements CommandHandler
{
    public function handles(Cmd $command): bool
    {
        return $command instanceof EmitLater;
    }

    public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure
    {
        EventLoop::delay(0.02, static fn () => $emit(new LateMessage()));

        return null;
    }
}

final readonly class StartFeed extends Cmd implements NamesItsEffect
{
    public function effectId(): EffectId
    {
        return EffectId::named('feed');
    }
}

final readonly class FeedOpened implements Message
{
}

final readonly class TimeToQuit implements Message
{
}

final readonly class CancellingFeedModel implements Model
{
    public function __construct(
        private StoppableFeedHandler $handler,
        public bool $stoppedBeforeQuitting = false,
    ) {
    }

    public function init(): ?Cmd
    {
        return new StartFeed();
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return match (true) {
            $message instanceof FeedOpened => new UpdateResult($this, Cmd::batch(
                Cmd::cancel(EffectId::named('feed')),
                Cmd::after(0.01, static fn (): Message => new TimeToQuit()),
            )),
            $message instanceof TimeToQuit => new UpdateResult(new self($this->handler, $this->handler->stopped), Cmd::quit()),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}

final class StoppableFeedHandler implements CommandHandler
{
    public bool $stopped = false;

    public function handles(Cmd $command): bool
    {
        return $command instanceof StartFeed;
    }

    public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure
    {
        $emit(new FeedOpened());

        return function (): void {
            $this->stopped = true;
        };
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

    public function render(Frame $frame, RenderPass $pass): void
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

    public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure
    {
        $emit(new StartupPong());

        return null;
    }
}
