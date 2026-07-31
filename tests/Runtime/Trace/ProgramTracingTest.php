<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime\Trace;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Testing\InMemoryTracer;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Support\TestMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramTracingTest extends TestCase
{
    #[Test]
    public function anUntracedProgramRunsWithoutRecordingAnything(): void
    {
        $tracer = new InMemoryTracer();

        $this->runTraced(null);

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

    #[Test]
    public function anUpdateReportsWhetherItChangedTheModel(): void
    {
        $tracer = new InMemoryTracer();

        $this->runTraced($tracer);

        foreach ($tracer->fieldsOf(TraceEvent::Update) as $fields) {
            self::assertArrayHasKey('changed', $fields);
            self::assertArrayHasKey('ms', $fields);
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

        $render = $tracer->fieldsOf(TraceEvent::Render)[0];

        self::assertArrayHasKey('paintMs', $render, 'One number for a frame cannot say which half was slow');
        self::assertArrayHasKey('diffMs', $render);
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

        Program::new($this->modelThatChangesTwice())
            ->withTerminal(new VirtualTerminal(80, 24))
            ->withTracer($tracer)
            ->fps(1)
            ->start();

        $skipped = $tracer->fieldsOf(TraceEvent::FrameSkipped);

        self::assertNotSame([], $skipped, 'One frame a second leaves the first one waiting');
        self::assertGreaterThan(0.0, $skipped[0]['waitMs']);
    }

    /**
     * Draws a frame, then dirties itself again while the budget still forbids
     * another one.
     */
    private function modelThatChangesTwice(): Model
    {
        return new class(0) implements Model {
            public function __construct(private readonly int $revision)
            {
            }

            public function init(): ?Cmd
            {
                return Cmd::batch(
                    Cmd::message(new TestMessage('first')),
                    Cmd::after(0.02, static fn (): Message => new TestMessage('second')),
                    Cmd::after(0.3, static fn (): Cmd => Cmd::quit()),
                );
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult(new self($this->revision + 1));
            }

            public function render(Frame $frame, RenderContext $context): void
            {
                $frame->putString(0, 0, 'revision ' . $this->revision);
            }
        };
    }

    private function runTraced(?InMemoryTracer $tracer): void
    {
        $program = Program::new($this->shortLivedModel())
            ->withTerminal(new VirtualTerminal(80, 24));

        if ($tracer !== null) {
            $program = $program->withTracer($tracer);
        }

        $program->start();
    }

    /**
     * Handles one real message, then quits from a timer so the loop completes an
     * iteration — and therefore a frame — before stopping.
     */
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

            public function render(Frame $frame, RenderContext $context): void
            {
                $frame->putString(0, 0, 'traced');
            }
        };
    }
}
