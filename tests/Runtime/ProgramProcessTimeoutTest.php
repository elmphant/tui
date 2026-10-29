<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\ProcessOutputMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramProcessTimeoutTest extends TestCase
{
    #[Test]
    public function aLineFramedProcessThatTimesOutStillHandsOnItsLastUnfinishedLine(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $typist = EventLoop::delay(4.0, static fn () => $terminal->simulateInput('q'));

        Program::new(new TimingOutModel())->withTerminal($terminal)->start();
        EventLoop::cancel($typist);

        self::assertSame(['partial'], TimingOutModel::$lines);
    }
}

final class TimingOutModel implements Model
{
    /**
     * @var list<string>
     */
    public static array $lines = [];

    public function init(): ?Cmd
    {
        self::$lines = [];

        return Cmd::process([PHP_BINARY, '-r', 'echo "partial"; sleep(10);'])->withLineFraming()->withTimeout(0.5);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof ProcessOutputMsg) {
            self::$lines[] = $message->output;
        }

        return new UpdateResult($this, $message instanceof KeyMsg || $message instanceof ErrorMsg ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
