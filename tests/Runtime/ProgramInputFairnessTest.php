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
use RuntimeException;

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

    #[Test]
    public function aFloodOfKeysIsHandledInSeconds(): void
    {
        $terminal = new VirtualTerminal(20, 3);
        $terminal->simulateInput(str_repeat('x', KeyCountingModel::FLOOD));
        $guard = EventLoop::delay(5, static fn () => throw new RuntimeException('the flood was not handled within 5 s'));

        try {
            Program::new(new KeyCountingModel())->withTerminal($terminal)->start();
        } finally {
            EventLoop::cancel($guard);
        }

        self::assertSame(KeyCountingModel::FLOOD, KeyCountingModel::$keys);
    }
}

final class KeyCountingModel implements Model
{
    public const FLOOD = 20_000;
    public static int $keys = 0;

    public function init(): ?Cmd
    {
        self::$keys = 0;

        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg) {
            ++self::$keys;
        }

        return new UpdateResult($this, self::$keys === self::FLOOD ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
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
