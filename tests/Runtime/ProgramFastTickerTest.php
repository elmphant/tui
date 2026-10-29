<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\ProcessResultMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Clock;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramFastTickerTest extends TestCase
{
    #[Test]
    public function aProcessStillAnswersWhileATickerIsDueOnEveryTurn(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $typist = EventLoop::delay(2.0, static fn () => $terminal->simulateInput('q'));

        Program::new(new TickingModel())->withTerminal($terminal)->withClock(new HurriedClock())->start();
        EventLoop::cancel($typist);

        self::assertSame("done\n", TickingModel::$answer);
    }
}

/**
 * Every reading is ten milliseconds after the last, so a ten millisecond ticker is due whenever the loop looks.
 */
final class HurriedClock implements Clock
{
    private float $now = 100.0;

    public function now(): float
    {
        return $this->now += 0.01;
    }
}

final class TickingModel implements Model
{
    public static ?string $answer = null;

    public function init(): ?Cmd
    {
        self::$answer = null;

        return Cmd::batch(Cmd::tick(0.01), Cmd::exec(['echo', 'done']));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof ProcessResultMsg) {
            self::$answer = $message->stdout;
        }

        return new UpdateResult($this, $message instanceof KeyMsg ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
