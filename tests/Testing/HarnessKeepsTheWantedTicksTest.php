<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\TickCmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\WantsTicks;
use Elmphant\Tui\Testing\ModelHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessKeepsTheWantedTicksTest extends TestCase
{
    #[Test]
    public function aTickTheModelWantsRuns(): void
    {
        $driven = ModelHarness::for(new Stopwatch(), 20, 2)->init();

        $driven->tick('clock');

        self::assertSame(1, $driven->model()->ticks);
    }

    #[Test]
    public function aTickTheModelNoLongerWantsStops(): void
    {
        $driven = ModelHarness::for(new Stopwatch(), 20, 2)->init();

        $driven->dispatch(new Pace(running: false));

        $this->expectExceptionMessage('No effect named "clock" is running');
        $driven->tick('clock');
    }

    #[Test]
    public function aNewIntervalStartsTheTickAgainAtThatPace(): void
    {
        $driven = ModelHarness::for(new Stopwatch(), 20, 2)->init();

        $driven->dispatch(new Pace(running: true, interval: 5.0));

        self::assertEquals([Cmd::tick(5.0, EffectId::named('clock'))], $driven->issued(TickCmd::class));
    }

    #[Test]
    public function anUnchangedTickIsLeftRunning(): void
    {
        $driven = ModelHarness::for(new Stopwatch(), 20, 2)->init();

        $driven->dispatch(new Pace(running: true));

        self::assertSame([], $driven->issued());
    }

    #[Test]
    public function aWantedTickNeedsAName(): void
    {
        $this->expectExceptionMessage('wantedTicks() answers named ticks only');

        ModelHarness::for(new AnyTicks(Cmd::tick(1.0)), 20, 2)->init();
    }

    #[Test]
    public function aWantedTickIsATick(): void
    {
        $this->expectExceptionMessage('wantedTicks() answers named ticks only');

        ModelHarness::for(new AnyTicks(Cmd::quit()), 20, 2)->init();
    }
}

final readonly class AnyTicks implements Model, WantsTicks
{
    public function __construct(private Cmd $wanted)
    {
    }

    public function wantedTicks(): ?Cmd
    {
        return $this->wanted;
    }

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}

final readonly class Stopwatch implements Model, WantsTicks
{
    public function __construct(
        public bool $running = true,
        public float $interval = 1.0,
        public int $ticks = 0,
    ) {
    }

    public function wantedTicks(): ?Cmd
    {
        return $this->running ? Cmd::tick($this->interval, EffectId::named('clock')) : null;
    }

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult(match (true) {
            $message instanceof TickMsg => new self($this->running, $this->interval, $this->ticks + 1),
            $message instanceof Pace => new self($message->running, $message->interval, $this->ticks),
            default => $this,
        });
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}

final readonly class Pace implements Message
{
    public function __construct(
        public bool $running,
        public float $interval = 1.0,
    ) {
    }
}
