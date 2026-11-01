<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime\Trace;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Testing\InMemoryTracer;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Support\GuardedTerminal;
use Elmphant\Tui\Tests\Support\TestMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramTracingTest extends TestCase
{
    #[Test]
    public function anUntracedProgramRunsWithoutRecordingAnything(): void
    {
        $tracer = new InMemoryTracer();

        $this->runUntraced();

        self::assertSame([], $tracer->records());
    }

    #[Test]
    public function everyMessageHandedToTheModelIsReported(): void
    {
        $tracer = new InMemoryTracer();

        $this->runTraced($tracer);

        $messages = array_column($tracer->fieldsOf(TraceEvent::Update), 'message');

        self::assertContains(TestMessage::class, $messages, 'The model saw this message, so the trace must show it');
    }

    /**
     * Include every modifier so traces can explain why a key did or did not match a binding.
     */
    #[Test]
    public function aTracedKeyIsNamedWithEveryModifierThatWasHeld(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput("\x1b[97;8u");
        $terminal->simulateInput('q');

        Program::new($this->quittingOnQ())->withTerminal($terminal)->withTracer($tracer)->start();

        $keys = array_column($tracer->fieldsOf(TraceEvent::Update), 'key');

        self::assertContains('ctrl+alt+shift+a', $keys);
    }

    #[Test]
    public function anUpdateReportsWhetherItChangedTheModel(): void
    {
        $tracer = new InMemoryTracer();

        $this->runTraced($tracer);

        $updates = $tracer->fieldsOf(TraceEvent::Update);

        self::assertNotSame([], $updates, 'The model handled a message, so the trace must show an update');
        foreach ($updates as $fields) {
            self::assertArrayHasKey('changed', $fields);
            self::assertArrayHasKey('ms', $fields);
        }
    }

    #[Test]
    public function anUpdateThatKeptTheModelSaysSoAndHowLongItTook(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('q');

        Program::new($this->quittingOnQ())->withTerminal($terminal)->withTracer($tracer)->start();

        $updates = $tracer->fieldsOf(TraceEvent::Update);
        self::assertNotSame([], $updates);
        foreach ($updates as $fields) {
            self::assertFalse($fields['changed']);
            self::assertIsFloat($fields['ms']);
            self::assertGreaterThanOrEqual(0.0, $fields['ms']);
            self::assertLessThan(1000.0, $fields['ms']);
        }
    }

    #[Test]
    public function everyEffectDispatchedIsReported(): void
    {
        $tracer = new InMemoryTracer();

        $this->runTraced($tracer);

        $commands = array_column($tracer->fieldsOf(TraceEvent::Command), 'command');

        self::assertNotSame([], $commands, 'The model asked for effects, so the trace must name them');
    }

    #[Test]
    public function eachFrameReportsWhatItCostAndWhatTheTerminalMadeItWaitFor(): void
    {
        $tracer = new InMemoryTracer();

        $this->runTraced($tracer);

        $renders = $tracer->fieldsOf(TraceEvent::Render);

        self::assertNotSame([], $renders, 'A program that drew a frame must report it');
        self::assertArrayHasKey('ms', $renders[0]);
        self::assertArrayHasKey('bytes', $renders[0]);
        self::assertArrayHasKey('blockedMs', $renders[0]);
        self::assertArrayHasKey('drawn', $renders[0]);
    }

    #[Test]
    public function aFrameSaysHowMuchWentOnPaintingAndHowMuchOnDiffing(): void
    {
        $tracer = new InMemoryTracer();

        $this->runTraced($tracer);

        $renders = $tracer->fieldsOf(TraceEvent::Render);

        self::assertNotSame([], $renders, 'A program that drew a frame must report it');
        self::assertArrayHasKey('paintMs', $renders[0], 'One number for a frame cannot say which half was slow');
        self::assertArrayHasKey('diffMs', $renders[0]);
    }

    #[Test]
    public function inputReportsHowMuchArrivedAndHowMuchWasStillWaiting(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput("\x1b[<64;10;10M");

        Program::new($this->shortLivedModel())
            ->withTerminal($terminal)
            ->withTracer($tracer)
            ->start();

        $reads = $tracer->fieldsOf(TraceEvent::InputRead);

        self::assertNotSame([], $reads, 'Bytes arrived, so the trace must show them');
        self::assertArrayHasKey('bytes', $reads[0]);
        self::assertArrayHasKey('queued', $reads[0]);
    }

    #[Test]
    public function aFrameHeldBackByTheBudgetSaysHowLongItWasHeld(): void
    {
        $tracer = new InMemoryTracer();

        $this->runTraced($tracer, framesPerSecond: 1.0);

        $skipped = $tracer->fieldsOf(TraceEvent::FrameSkipped);

        self::assertNotSame([], $skipped, 'A frame the budget holds back must be reported');
        self::assertSame(1000.0, $skipped[0]['waitMs']);
    }

    private function runTraced(InMemoryTracer $tracer, float $framesPerSecond = 30.0): void
    {
        $terminal = GuardedTerminal::of(80, 24);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput('x'));
        EventLoop::delay(0.15, static fn () => $terminal->closeInput());

        Program::new($this->tracedModel())
            ->withTerminal($terminal)
            ->withTracer($tracer)
            ->withClock(new TestClock(100.0))
            ->withFps($framesPerSecond)
            ->start();
    }

    private function runUntraced(): void
    {
        Program::new($this->shortLivedModel())
            ->withTerminal(new VirtualTerminal(80, 24))
            ->start();
    }

    private function tracedModel(): Model
    {
        return new class(0) implements Model {
            public function __construct(private readonly int $revision)
            {
            }

            public function init(): ?Cmd
            {
                return Cmd::message(new TestMessage('traced'));
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult(new self($this->revision + 1));
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                $frame->putString(0, 0, 'revision ' . $this->revision);
            }
        };
    }

    private function quittingOnQ(): Model
    {
        return new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult(
                    $this,
                    $message instanceof KeyMsg && 'q' === $message->key ? Cmd::quit() : null,
                );
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
    }

    private function shortLivedModel(): Model
    {
        return new class implements Model {
            public function init(): ?Cmd
            {
                return Cmd::batch(
                    Cmd::message(new TestMessage('traced')),
                    Cmd::after(0.01, static fn (): Cmd => Cmd::quit()),
                );
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                $frame->putString(0, 0, 'traced');
            }
        };
    }
}
