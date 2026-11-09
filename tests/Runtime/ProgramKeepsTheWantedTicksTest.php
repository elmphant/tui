<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\WantsTicks;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramKeepsTheWantedTicksTest extends TestCase
{
    #[Test]
    public function aWantedTickArrivesUntilTheModelNoLongerWantsIt(): void
    {
        $ended = Program::new(new CountsToThree())->withTerminal(new VirtualTerminal(20, 2))->start();

        self::assertInstanceOf(CountsToThree::class, $ended);
        self::assertSame(3, $ended->ticks);
    }

    #[Test]
    public function anUnnamedWantedTickReachesTheErrorHandler(): void
    {
        $sources = [];
        Program::new(new WantsAnUnnamedTick())
            ->withTerminal(new VirtualTerminal(20, 2))
            ->onError(static function (ErrorMsg $error) use (&$sources): Cmd {
                $sources[] = $error->source;

                return Cmd::quit();
            })
            ->start();

        self::assertSame([ErrorSource::Command], $sources);
    }
}

final readonly class WantsAnUnnamedTick implements Model, WantsTicks
{
    public function wantedTicks(): ?Cmd
    {
        return Cmd::tick(1.0);
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

final readonly class CountsToThree implements Model, WantsTicks
{
    public function __construct(public int $ticks = 0)
    {
    }

    public function wantedTicks(): ?Cmd
    {
        return $this->ticks < 3 ? Cmd::tick(0.005, EffectId::named('clock')) : null;
    }

    public function init(): ?Cmd
    {
        return Cmd::after(0.2, static fn (): Cmd => Cmd::quit());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($message instanceof TickMsg ? new self($this->ticks + 1) : $this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
