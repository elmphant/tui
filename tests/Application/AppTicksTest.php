<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Runtime\WantsTicks;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppTicksTest extends TestCase
{
    #[Test]
    public function aTickTheAppWantsReachesOnTick(): void
    {
        $driven = ModelHarness::for(new RefreshingApp(), 20, 2)->init();

        $driven->tick('refresh');

        self::assertSame(1, $driven->model()->refreshed);
    }

    #[Test]
    public function aTickAMountedModelWantsRunsUnderTheMountAndReachesIt(): void
    {
        $driven = ModelHarness::for(new StopwatchHost(new MountedStopwatch()), 20, 2)->init();

        $driven->tick(EffectId::named('clock')->within('stopwatch'));

        self::assertSame(1, $driven->model()->stopwatch->ticks);
    }
}

final readonly class StopwatchHost extends App
{
    public function __construct(public MountedStopwatch $stopwatch)
    {
    }

    public function view(): Widget
    {
        return Mount::named(
            'stopwatch',
            $this->stopwatch,
            Text::new('ticks ' . $this->stopwatch->ticks),
            static fn (self $app, MountedStopwatch $stopwatch): self => new self($stopwatch),
        );
    }
}

final readonly class MountedStopwatch implements Reducer, WantsTicks
{
    public function __construct(public int $ticks = 0)
    {
    }

    public function wantedTicks(): ?Cmd
    {
        return Cmd::tick(1.0, EffectId::named('clock'));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($message instanceof TickMsg ? new self($this->ticks + 1) : $this);
    }
}

final readonly class RefreshingApp extends App
{
    public function __construct(public int $refreshed = 0)
    {
    }

    public function view(): Widget
    {
        return Text::new('refreshed ' . $this->refreshed);
    }

    protected function ticks(): ?Cmd
    {
        return Cmd::tick(30.0, EffectId::named('refresh'));
    }

    protected function onTick(TickMsg $message, InputContext $input): self
    {
        return new self($this->refreshed + 1);
    }
}
