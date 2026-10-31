<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramInputFairnessTest extends TestCase
{
    #[Test]
    public function aKeyIsHandledBeforeABacklogOfSlowMessagesThatWasQueuedEarlier(): void
    {
        $terminal = new VirtualTerminal(20, 3);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput('x'));

        Program::new(new SlowBacklogModel())->withTerminal($terminal)->start();

        self::assertContains(SlowBacklogModel::$slowBeforeTheKey, range(1, 150));
    }
}

final readonly class SlowWork implements Message
{
    public function __construct(public int $number)
    {
    }
}

final class SlowBacklogModel implements Model
{
    public static int $slowBeforeTheKey = -1;
    private static int $slowDone = 0;

    public function init(): ?Cmd
    {
        self::$slowBeforeTheKey = -1;
        self::$slowDone = 0;

        return Cmd::batch(...array_map(static fn (int $number): Cmd => Cmd::message(new SlowWork($number)), range(1, 200)));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof SlowWork) {
            usleep(2_000);
            ++self::$slowDone;
        }
        if ($message instanceof KeyMsg) {
            self::$slowBeforeTheKey = self::$slowDone;
        }

        return new UpdateResult($this, self::$slowDone === 200 && self::$slowBeforeTheKey >= 0 ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
