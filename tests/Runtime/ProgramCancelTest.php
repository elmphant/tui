<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
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

final class ProgramCancelTest extends TestCase
{
    #[Test]
    public function aCancelStillDeliversWhatTheEffectHadAlreadySaid(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $typist = EventLoop::delay(2.0, static fn () => $terminal->simulateInput('q'));

        Program::new(new CancellingModel())->withTerminal($terminal)->start();
        EventLoop::cancel($typist);

        self::assertSame(['aaaa', 'bbbb', 'cccc'], CancellingModel::$heard);
    }
}

final class CancellingModel implements Model
{
    /**
     * @var list<string>
     */
    public static array $heard = [];

    public function init(): ?Cmd
    {
        self::$heard = [];

        return Cmd::stream([PHP_BINARY, '-r', 'echo "aaaabbbbcccc"; sleep(10);'])
            ->withOutputChunkBytes(4)
            ->withId(EffectId::named('run'));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg) {
            return new UpdateResult($this, Cmd::quit());
        }
        if (!$message instanceof ProcessOutputMsg) {
            return new UpdateResult($this);
        }

        self::$heard[] = $message->output;

        return new UpdateResult($this, count(self::$heard) === 1 ? Cmd::cancel(EffectId::named('run')) : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
