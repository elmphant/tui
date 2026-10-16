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
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Testing\InMemoryTracer;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramReadsInputWhileUpdateAwaitsTest extends TestCase
{
    #[Test]
    public function aKeyTypedWhileUpdateAwaitsIsReadAtOnce(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        $tracer = new InMemoryTracer();
        $program = Program::new(new AwaitingModel())->withTerminal($terminal)->withTracer($tracer);
        $readsDuringTheAwait = null;

        EventLoop::delay(0.02, static fn () => $terminal->simulateInput('a'));
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput('b'));
        EventLoop::delay(0.15, static function () use ($tracer, &$readsDuringTheAwait): void {
            $readsDuringTheAwait = $tracer->countOf(TraceEvent::InputRead);
        });
        EventLoop::delay(0.3, static fn () => $terminal->simulateInput('q'));

        $program->start();

        self::assertSame(2, $readsDuringTheAwait);
    }
}

final readonly class AwaitingModel implements Model
{
    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg && $message->key === 'a') {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.2, static fn () => $suspension->resume());
            $suspension->suspend();
        }

        return new UpdateResult($this, $message instanceof KeyMsg && $message->key === 'q' ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
