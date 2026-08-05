<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime\Trace;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\StdinBuffer;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\AdaptiveTicker;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Testing\InMemoryTracer;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Support\TestMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SplQueue;

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

        $this->tickOnce($tracer);

        $messages = array_column($tracer->fieldsOf(TraceEvent::Update), 'message');

        self::assertContains(TestMessage::class, $messages, 'The model saw this message, so the trace must show it');
    }

    #[Test]
    public function anUpdateReportsWhetherItChangedTheModel(): void
    {
        $tracer = new InMemoryTracer();

        $this->tickOnce($tracer);

        $updates = $tracer->fieldsOf(TraceEvent::Update);

        self::assertNotSame([], $updates, 'The model handled a message, so the trace must show an update');
        foreach ($updates as $fields) {
            self::assertArrayHasKey('changed', $fields);
            self::assertArrayHasKey('ms', $fields);
        }
    }

    #[Test]
    public function everyEffectDispatchedIsReported(): void
    {
        $tracer = new InMemoryTracer();

        $this->tickOnce($tracer);

        $commands = array_column($tracer->fieldsOf(TraceEvent::Command), 'command');

        self::assertNotSame([], $commands, 'The model asked for effects, so the trace must name them');
    }

    #[Test]
    public function eachFrameReportsWhatItCostAndWhatTheTerminalMadeItWaitFor(): void
    {
        $tracer = new InMemoryTracer();

        $this->tickOnce($tracer);

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

        $this->tickOnce($tracer);

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
        $clock = new TestClock(100.0);

        // One frame a second, and the last one landed just now: this frame waits.
        $program = $this->tickableProgram($tracer, $clock, framesPerSecond: 1.0, lastRender: 100.0);
        $program->tick();

        $skipped = $tracer->fieldsOf(TraceEvent::FrameSkipped);

        self::assertNotSame([], $skipped, 'A frame the budget holds back must be reported');
        self::assertSame(1000.0, $skipped[0]['waitMs']);
    }

    /**
     * Drives exactly one loop iteration with a message already queued and the
     * frame budget already elapsed, so update, effect and render all happen
     * without depending on wall-clock timers firing in the right order.
     */
    private function tickOnce(InMemoryTracer $tracer): void
    {
        $this->tickableProgram($tracer, new TestClock(100.0))->tick();
    }

    private function tickableProgram(
        InMemoryTracer $tracer,
        TestClock $clock,
        float $framesPerSecond = 30.0,
        float $lastRender = 0.0,
    ): Program {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->tracedModel())
            ->withTerminal($terminal)
            ->withTracer($tracer)
            ->withClock($clock)
            ->fps($framesPerSecond);

        $queue = new SplQueue();
        $queue->enqueue(new TestMessage('traced'));

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $this->tracedModel());
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(static fn (): ?string => null));
        $reflection->getProperty('width')->setValue($program, 80);
        $reflection->getProperty('height')->setValue($program, 24);
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);
        $reflection->getProperty('running')->setValue($program, true);
        $reflection->getProperty('isDirty')->setValue($program, true);
        $reflection->getProperty('lastRender')->setValue($program, $lastRender);
        $reflection->getProperty('adaptiveTicker')->setValue($program, new AdaptiveTicker(static fn (): ?float => null));

        return $program;
    }

    private function runUntraced(): void
    {
        Program::new($this->shortLivedModel())
            ->withTerminal(new VirtualTerminal(80, 24))
            ->start();
    }

    /**
     * Changes on every message and asks for an effect, so one iteration leaves
     * an update, a command and a dirty frame behind.
     */
    private function tracedModel(): Model
    {
        return new class(0) implements Model {
            public function __construct(private readonly int $revision)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult(new self($this->revision + 1), Cmd::after(60.0, static fn (): Cmd => Cmd::quit()));
            }

            public function render(Frame $frame, RenderContext $context): void
            {
                $frame->putString(0, 0, 'revision ' . $this->revision);
            }
        };
    }

    /**
     * Handles one real message, then quits from a timer so the loop completes an
     * iteration before stopping.
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
