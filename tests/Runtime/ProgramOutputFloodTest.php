<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramOutputFloodTest extends TestCase
{
    #[Test]
    public function aKeyTypedWhileAProcessFloodsItsOutputStillArrivesPromptly(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $handledWhenTyped = null;
        $typist = EventLoop::delay(2.0, static function () use ($terminal, &$handledWhenTyped): void {
            $handledWhenTyped = FloodedModel::$handled;
            $terminal->simulateInput('q');
        });

        Program::new(new FloodedModel())->withTerminal($terminal)->start();
        EventLoop::cancel($typist);

        self::assertNotNull($handledWhenTyped);
        self::assertNotNull(FloodedModel::$handledBeforeTheKey, 'the watchdog ended the run, not the key');
        // The key waits behind one batch of output at most, 64 messages. A queue that starves input handled thousands first.
        self::assertLessThan(1_024, FloodedModel::$handledBeforeTheKey - $handledWhenTyped);
    }
}

final class FloodedModel implements Model
{
    public static int $handled = 0;
    public static ?int $handledBeforeTheKey = null;

    public function init(): ?Cmd
    {
        self::$handled = 0;
        self::$handledBeforeTheKey = null;

        return Cmd::batch(
            Cmd::process([PHP_BINARY, '-r', '$line = str_repeat("y", 79) . "\n"; while (true) echo $line;'])->withOutputChunkBytes(1024),
            Cmd::after(8, static fn () => Cmd::quit()),
        );
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        usleep(500);

        if ($message instanceof KeyMsg && $message->key === 'q') {
            self::$handledBeforeTheKey = self::$handled;

            return new UpdateResult($this, Cmd::quit());
        }

        ++self::$handled;

        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
