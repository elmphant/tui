<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use ArrayObject;
use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\KeyPattern;
use Elmphant\Tui\Input\StdinBuffer;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\ProcessResultMsg;
use Elmphant\Tui\Message\QuitMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\FloatMouseMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\AdaptiveTicker;
use Elmphant\Tui\Runtime\Clock;
use Elmphant\Tui\Runtime\CommandHandler;
use Elmphant\Tui\Runtime\EffectRunner;
use Elmphant\Tui\Runtime\EventLoopWatcher;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ProgramState;
use Elmphant\Tui\Runtime\RenderRevisioned;
use Elmphant\Tui\Runtime\ShutdownAware;
use Elmphant\Tui\Runtime\TerminalSession;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\ColorProfile;
use Elmphant\Tui\Terminal\ColorScheme;
use Elmphant\Tui\Terminal\Terminal;
use Elmphant\Tui\Terminal\TerminalOutput;
use Elmphant\Tui\Terminal\TerminalWriteException;
use Elmphant\Tui\Testing\InMemoryTracer;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Support\FloatingModel;
use Elmphant\Tui\Tests\Support\PlaySoundCmd;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Tests\Support\TestPopup;
use Error;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Revolt\EventLoop;
use RuntimeException;
use SplQueue;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

final class ProgramTest extends TestCase
{
    #[Test]
    public function aLoneEscapeArrivesWithoutWaitingForTheNextKey(): void
    {
        $model = new InputLoggingModel();
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput("\x1b"));
        EventLoop::delay(0.15, static fn () => $terminal->simulateInput('q'));

        Program::new($model)->withTerminal($terminal)->start();

        self::assertSame(['esc', 'q'], $model->log);
    }

    #[Test]
    public function anEscapeSequenceSplitAcrossReadsIsStillOneKey(): void
    {
        $model = new InputLoggingModel();
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput("\x1b"));
        EventLoop::delay(0.024, static fn () => $terminal->simulateInput('['));
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput('A'));
        EventLoop::delay(0.15, static fn () => $terminal->simulateInput('q'));

        Program::new($model)->withTerminal($terminal)->start();

        self::assertSame(['up', 'q'], $model->log);
    }

    #[Test]
    public function inputHeldBackWhenTheTerminalClosesStillArrives(): void
    {
        $model = new InputLoggingModel();
        $terminal = new VirtualTerminal(20, 5);
        $terminal->simulateInput("\x1b");
        $terminal->closeInput();

        Program::new($model)->withTerminal($terminal)->start();

        self::assertSame(['esc'], $model->log);
    }

    /**
     * @param array<string, string> $environment
     * @param list<string>          $seenAfterFiftyMilliseconds
     */
    #[Test]
    #[DataProvider('sessions')]
    public function aLoneEscapeIsWaitedOnLongerOverSsh(array $environment, array $seenAfterFiftyMilliseconds): void
    {
        $saved = ['SSH_CONNECTION' => getenv('SSH_CONNECTION'), 'SSH_TTY' => getenv('SSH_TTY')];
        foreach (array_keys($saved) as $name) {
            putenv(isset($environment[$name]) ? $name . '=' . $environment[$name] : $name);
        }
        $model = new InputLoggingModel();
        $terminal = new VirtualTerminal(20, 5);
        $seen = null;
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput("\x1b"));
        EventLoop::delay(0.07, static function () use ($model, &$seen): void {
            $seen = $model->log;
        });
        EventLoop::delay(0.2, static fn () => $terminal->simulateInput('q'));

        try {
            Program::new($model)->withTerminal($terminal)->start();
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }

        self::assertSame($seenAfterFiftyMilliseconds, $seen);
    }

    /**
     * @return iterable<string, array{array<string, string>, list<string>}>
     */
    public static function sessions(): iterable
    {
        yield 'local' => [[], ['esc']];
        yield 'ssh connection' => [['SSH_CONNECTION' => '10.0.0.1 22 10.0.0.2 22'], []];
        yield 'ssh tty' => [['SSH_TTY' => '/dev/pts/0'], []];
    }

    #[Test]
    public function wheelNotchesReadTogetherReachTheModelAsOneMove(): void
    {
        $model = new InputLoggingModel();
        $terminal = new VirtualTerminal(20, 5);
        $terminal->simulateInput(str_repeat("\x1b[<65;5;3M", 3));
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput('q'));

        Program::new($model)->withTerminal($terminal)->start();

        self::assertSame(['wheeldown×3', 'q'], $model->log);
    }

    #[Test]
    public function wheelingWithinOneFrameWaitsForTheNextOneByItself(): void
    {
        $clock = new TestClock(100.0);
        $model = new InputLoggingModel($clock, quitOnWheelUp: true);
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput("\x1b[<65;5;3M"));
        EventLoop::delay(0.04, static fn () => $terminal->simulateInput(str_repeat("\x1b[<64;5;3M", 2)));
        EventLoop::delay(0.07, static fn () => $clock->advance(0.1));
        EventLoop::delay(0.5, static fn () => $terminal->simulateInput('q'));

        Program::new($model)->withTerminal($terminal)->withClock($clock)->withFps(10)->start();

        self::assertSame(['wheeldown×1@100', 'wheelup×2@100.1'], $model->log);
    }

    #[Test]
    public function anUpdateThatKeepsTheModelDrawsNoNewFrame(): void
    {
        $model = new InputLoggingModel();
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput('z'));
        EventLoop::delay(0.06, static fn () => $terminal->simulateInput('q'));

        Program::new($model)->withTerminal($terminal)->start();

        self::assertSame(1, $model->renders);
    }

    #[Test]
    public function aStoppedProgramLeavesNothingBehindInTheEventLoop(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput('q'));
        $before = EventLoop::getIdentifiers();

        Program::new(new InputLoggingModel(init: Cmd::after(60.0, static fn (): ?Message => null)))->withTerminal($terminal)->start();

        self::assertSame([], array_values(array_diff(EventLoop::getIdentifiers(), $before)), 'a timer still due keeps the loop awake');
    }

    #[Test]
    public function updateErrorsTheHandlerDealtWithNeverAddUpToGivingUp(): void
    {
        $handled = 0;
        $terminal = new VirtualTerminal(20, 5);
        $terminal->simulateInput(str_repeat('a', 12) . 'q');

        Program::new(new InputLoggingModel(failOn: 'a'))
            ->withTerminal($terminal)
            ->onError(static function () use (&$handled): ?Cmd {
                ++$handled;

                return null;
            })
            ->start();

        self::assertSame(12, $handled);
    }

    #[Test]
    public function aCallAnsweringWithSomethingElseSaysSoRatherThanFailingQuietly(): void
    {
        $model = new InputLoggingModel(init: Cmd::call(static fn (): int => 42));
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput('q'));

        Program::new($model)->withTerminal($terminal)->start();

        self::assertSame(['error:Call', 'q'], $model->log);
    }

    #[Test]
    public function aFailedFrameIsNotRetriedBeforeTheFrameBudgetHasPassed(): void
    {
        $tracer = new InMemoryTracer();
        $model = new ScriptedRenderModel([new RuntimeException('broken')]);
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.1, static fn () => $terminal->simulateInput('q'));

        Program::new($model)->withTerminal($terminal)->withClock(new TestClock(100.0))->withTracer($tracer)->start();

        self::assertSame(1, $model->attempts);
        self::assertSame([false], array_column($tracer->fieldsOf(TraceEvent::Render), 'drawn'));
    }

    #[Test]
    public function aStalledWriteStartsTheCountOfFailedFramesAgain(): void
    {
        $model = new ScriptedRenderModel([new TerminalWriteException(0, 10, stalled: true), new RuntimeException('broken')]);

        try {
            Program::new($model)->withTerminal(new VirtualTerminal(20, 5))->start();
            self::fail('ten failed frames in a row end the program');
        } catch (RuntimeException $error) {
            self::assertSame('broken', $error->getMessage());
        }

        self::assertSame(11, $model->attempts);
    }

    #[Test]
    public function aFrameThatDrewStartsTheCountOfFailedFramesAgain(): void
    {
        $tracer = new InMemoryTracer();
        $model = new ScriptedRenderModel([null, new RuntimeException('broken')]);

        try {
            Program::new($model)->withTerminal(new VirtualTerminal(20, 5))->withTracer($tracer)->start();
            self::fail('ten failed frames in a row end the program');
        } catch (RuntimeException $error) {
            self::assertSame('broken', $error->getMessage());
        }

        self::assertSame(11, $model->attempts);
        self::assertTrue($tracer->fieldsOf(TraceEvent::Render)[0]['drawn'] ?? null);
    }

    #[Test]
    public function aFrameThatFailsAfterAGoodOneIsDrawnAgain(): void
    {
        $model = new ScriptedRenderModel([null, new RuntimeException('broken'), null]);
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.1, static fn () => $terminal->simulateInput('q'));

        Program::new($model)->withTerminal($terminal)->start();

        self::assertGreaterThanOrEqual(3, $model->attempts, 'a failed frame leaves the screen stale until one is drawn');
    }

    #[Test]
    public function aFrameReportsHowLongItsPaintingTook(): void
    {
        $tracer = new InMemoryTracer();
        $clock = new TestClock(100.0);
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput('q'));

        Program::new(new SlowPaintModel($clock, 0.004))->withTerminal($terminal)->withClock($clock)->withTracer($tracer)->start();

        $render = $tracer->fieldsOf(TraceEvent::Render)[0] ?? [];
        self::assertEqualsWithDelta(4.0, $render['ms'] ?? null, 0.0001);
        self::assertEqualsWithDelta(4.0, $render['paintMs'] ?? null, 0.0001);
    }

    #[Test]
    public function runAndStartHaveANativeModelReturnContract(): void
    {
        self::assertSame(Model::class, (string)(new ReflectionMethod(Program::class, 'run'))->getReturnType());
        self::assertSame(Model::class, (string)(new ReflectionMethod(Program::class, 'start'))->getReturnType());
    }

    #[Test]
    public function aRegisteredHandlerRunsACommandTheRuntimeDoesNotKnow(): void
    {
        $model = new class implements Model {
            /**
             * @var list<string>
             */
            public array $received = [];

            public function init(): ?Cmd
            {
                return Cmd::batch(
                    new PlaySoundCmd(),
                    Cmd::after(0.05, static fn (): Cmd => Cmd::quit()),
                );
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if (!$message instanceof TestMessage) {
                    return new UpdateResult($this);
                }
                $next = clone $this;
                $next->received = [...$this->received, $message->value];

                return new UpdateResult($next);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $handler = new class implements CommandHandler {
            public function handles(Cmd $command): bool
            {
                return $command instanceof PlaySoundCmd;
            }

            public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure
            {
                $emit(new TestMessage('played'));

                return null;
            }
        };

        $final = Program::new($model)
            ->withTerminal(new VirtualTerminal(80, 24))
            ->withCommandHandler($handler)
            ->start();

        self::assertInstanceOf($model::class, $final);
        self::assertSame(['played'], $final->received);
    }

    #[Test]
    public function aTimerDueAlongsideAQuitDoesNotFireAfterIt(): void
    {
        $log = new ArrayObject(['late' => false]);
        $model = new class($log) implements Model {
            public function __construct(private readonly ArrayObject $log)
            {
            }

            public function init(): ?Cmd
            {
                $log = $this->log;

                return Cmd::batch(
                    Cmd::after(0.01, static fn (): Cmd => Cmd::quit()),
                    Cmd::after(0.01, static function () use ($log): ?Message {
                        $log['late'] = true;

                        return null;
                    }),
                );
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        Program::new($model)->withTerminal(new VirtualTerminal(80, 24))->start();

        self::assertFalse($log['late'], 'a quit ends the pass it was dispatched in');
    }

    #[Test]
    public function aStreamSubscriptionDeliversWhatTheStreamProduces(): void
    {
        [$read, $write] = self::streamPair();
        $model = $this->streamModel($read);
        $program = Program::new($model)->withTerminal(new VirtualTerminal(80, 24));

        EventLoop::defer(static function () use ($write): void {
            fwrite($write, 'a frame');
        });

        $final = $program->start();
        self::assertInstanceOf($model::class, $final);
        self::assertSame(['a frame'], $final->received);
    }

    #[Test]
    public function cancellingAStreamSubscriptionStopsItsMessages(): void
    {
        [$read, $write] = self::streamPair();
        $id = EffectId::named('feed');
        $model = $this->streamModel($read, $id);
        $program = Program::new($model)->withTerminal(new VirtualTerminal(80, 24));

        EventLoop::delay(0.05, static function () use ($write): void {
            fwrite($write, 'first');
        });
        EventLoop::delay(0.15, static function () use ($write): void {
            fwrite($write, 'second');
        });

        $final = $program->start();
        self::assertInstanceOf($model::class, $final);
        self::assertSame(['first'], $final->received);
    }

    #[Test]
    public function cancellingASubscriptionFirstMakesItSafeToCloseTheStream(): void
    {
        [$read, $write] = self::streamPair();
        $id = EffectId::named('feed');
        $model = $this->closingStreamModel($read, $write, $id);
        $program = Program::new($model)->withTerminal(new VirtualTerminal(80, 24));

        EventLoop::delay(0.02, static function () use ($write): void {
            fwrite($write, 'last word');
        });

        $program->start();

        self::assertSame(ProgramState::Stopped, $program->state());
    }

    #[Test]
    public function aStoppedProgramNoLongerReadsItsSubscribedStreams(): void
    {
        [$read, $write] = self::streamPair();
        $reads = new ArrayObject(['count' => 0]);
        $model = $this->countingStreamModel($read, $reads);
        $program = Program::new($model)->withTerminal(new VirtualTerminal(80, 24));

        EventLoop::defer(static function () use ($write): void {
            fwrite($write, 'while running');
        });
        $program->start();
        $afterStop = $reads['count'];

        fwrite($write, 'after the program stopped');
        $suspension = EventLoop::getSuspension();
        EventLoop::delay(0.05, static function () use ($suspension): void {
            $suspension->resume();
        });
        $suspension->suspend();

        self::assertSame($afterStop, $reads['count']);
    }

    #[Test]
    public function listeningTwiceUnderOneIdKeepsOnlyTheLatestStream(): void
    {
        [$firstRead, $firstWrite] = self::streamPair();
        [$secondRead, $secondWrite] = self::streamPair();
        $model = new class($firstRead, $secondRead) implements Model {
            /**
             * @var list<string>
             */
            public array $received = [];

            /** @param resource $first
             *  @param resource $second */
            public function __construct(private readonly mixed $first, private readonly mixed $second)
            {
            }

            public function init(): ?Cmd
            {
                $id = EffectId::named('feed');

                return Cmd::batch(
                    Cmd::listen($this->first, self::reader('first'), $id),
                    Cmd::listen($this->second, self::reader('second'), $id),
                    Cmd::after(0.25, static fn (): Cmd => Cmd::quit()),
                );
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if (!$message instanceof TestMessage) {
                    return new UpdateResult($this);
                }
                $next = clone $this;
                $next->received = [...$this->received, $message->value];

                return new UpdateResult($next);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }

            private static function reader(string $label): Closure
            {
                return static function (mixed $stream) use ($label): iterable {
                    $chunk = fread($stream, 1024);
                    if ($chunk === false || $chunk === '') {
                        return [];
                    }

                    return [new TestMessage($label)];
                };
            }
        };
        $program = Program::new($model)->withTerminal(new VirtualTerminal(80, 24));

        EventLoop::delay(0.05, static function () use ($firstWrite, $secondWrite): void {
            fwrite($firstWrite, 'x');
            fwrite($secondWrite, 'y');
        });

        $final = $program->start();
        self::assertInstanceOf($model::class, $final);
        self::assertSame(['second'], $final->received);
    }

    #[Test]
    public function aClosedStreamEndsItsSubscriptionInsteadOfSpinning(): void
    {
        [$read, $write] = self::streamPair();
        $reads = new ArrayObject(['count' => 0]);
        $model = new class($read, $reads) implements Model {
            /**
             * @param resource $stream
             */
            public function __construct(private readonly mixed $stream, private readonly ArrayObject $reads)
            {
            }

            public function init(): ?Cmd
            {
                $reads = $this->reads;

                return Cmd::batch(
                    Cmd::listen($this->stream, static function (mixed $stream) use ($reads): iterable {
                        $reads['count'] = $reads['count'] + 1;
                        fread($stream, 1024);

                        return [];
                    }),
                    Cmd::after(0.2, static fn (): Cmd => Cmd::quit()),
                );
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
        $program = Program::new($model)->withTerminal(new VirtualTerminal(80, 24));

        EventLoop::defer(static function () use ($write): void {
            fclose($write);
        });

        $program->start();
        self::assertSame(1, $reads['count']);
    }

    #[Test]
    public function newReturnsProgram(): void
    {
        $model = $this->createMockModel();
        $program = Program::new($model);
        self::assertInstanceOf(Program::class, $program);
        self::assertSame(ProgramState::New, $program->state());
    }

    #[Test]
    public function startTransitionsRunningProgramToStoppedAndIsOneShot(): void
    {
        $model = new class implements Model {
            public ?Program $program = null;
            public ?ProgramState $stateDuringInit = null;

            public function init(): ?Cmd
            {
                $this->stateDuringInit = $this->program?->state();

                return Cmd::quit();
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
        $program = Program::new($model)->withTerminal(new VirtualTerminal(80, 24));
        $model->program = $program;

        self::assertSame($model, $program->start());
        self::assertSame(ProgramState::Running, $model->stateDuringInit);
        self::assertSame(ProgramState::Stopped, $program->state());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('only be started once');
        $program->start();
    }

    #[Test]
    public function configurationIsRejectedAfterAProgramHasStopped(): void
    {
        $model = new class implements Model {
            public function init(): ?Cmd
            {
                return Cmd::quit();
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
        $program = Program::new($model)->withTerminal(new VirtualTerminal(80, 24));
        $program->start();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('state Stopped');
        $program->withFps(30);
    }

    #[Test]
    public function failedStartStillTransitionsToStopped(): void
    {
        $model = new class implements Model {
            public function init(): ?Cmd
            {
                throw new RuntimeException('init failed');
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($model)->withTerminal($terminal);

        try {
            $program->start();
            self::fail('Expected model initialization to fail');
        } catch (RuntimeException $error) {
            self::assertSame('init failed', $error->getMessage());
        }

        self::assertSame(ProgramState::Stopped, $program->state());
        self::assertFalse($terminal->isRawModeEnabled());
        self::assertTrue($terminal->isCursorVisible());
    }

    #[Test]
    public function builderMethodsChainable(): void
    {
        $program = Program::new($this->createMockModel())
            ->withAltScreen()
            ->withMouseTracking()
            ->withFps(30)
            ->onError(fn (ErrorMsg $error) => Cmd::quit());

        self::assertInstanceOf(Program::class, $program);
    }

    #[Test]
    public function dispatchCommandWithNullIsNoOp(): void
    {
        $program = Program::new($this->createMockModel());

        (new ReflectionProperty(Program::class, 'state'))->setValue($program, ProgramState::Running);

        $method = new ReflectionMethod(Program::class, 'dispatchCommand');
        $method->invoke($program, null);

        self::assertSame(ProgramState::Running, $program->state(), 'null Cmd must not change running state');
    }

    #[Test]
    public function dispatchCommandQuitCmdStopsRunning(): void
    {
        $program = Program::new($this->createMockModel());

        (new ReflectionProperty(Program::class, 'state'))->setValue($program, ProgramState::Running);

        $method = new ReflectionMethod(Program::class, 'dispatchCommand');
        $method->invoke($program, Cmd::quit());

        self::assertNotSame(ProgramState::Running, $program->state(), 'QuitCmd must set running=false');
    }

    #[Test]
    public function dispatchCommandMessageCmdEnqueuesMessage(): void
    {
        $program = Program::new($this->createMockModel());

        $queueProperty = new ReflectionProperty(Program::class, 'messageQueue');
        $queue = new SplQueue();
        $queueProperty->setValue($program, $queue);

        $method = new ReflectionMethod(Program::class, 'dispatchCommand');
        $method->invoke($program, Cmd::message(new TestMessage('hello')));

        self::assertFalse($queue->isEmpty(), 'MessageCmd must enqueue its payload');
        self::assertSame('hello', $queue->dequeue()->value);
    }

    #[Test]
    public function dispatchCommandCallExecutesOnceAndEnqueuesReturnedMessage(): void
    {
        $calls = 0;
        $program = Program::new($this->createMockModel());
        $queue = new SplQueue();
        (new ReflectionProperty(Program::class, 'messageQueue'))->setValue($program, $queue);

        (new ReflectionMethod(Program::class, 'dispatchCommand'))->invoke(
            $program,
            Cmd::call(function () use (&$calls): TestMessage {
                ++$calls;

                return new TestMessage('called');
            }),
        );

        self::assertSame(1, $calls);
        self::assertSame(1, $queue->count());
        self::assertSame('called', $queue->dequeue()->value);
    }

    #[Test]
    public function dispatchCommandCallDispatchesReturnedCommand(): void
    {
        $program = Program::new($this->createMockModel());
        $queue = new SplQueue();
        (new ReflectionProperty(Program::class, 'messageQueue'))->setValue($program, $queue);

        (new ReflectionMethod(Program::class, 'dispatchCommand'))->invoke(
            $program,
            Cmd::call(fn () => Cmd::message(new TestMessage('nested'))),
        );

        self::assertSame(1, $queue->count());
        self::assertSame('nested', $queue->dequeue()->value);
    }

    #[Test]
    public function dispatchCommandCallAcceptsNullResult(): void
    {
        $program = Program::new($this->createMockModel());
        $queue = new SplQueue();
        (new ReflectionProperty(Program::class, 'messageQueue'))->setValue($program, $queue);

        (new ReflectionMethod(Program::class, 'dispatchCommand'))->invoke(
            $program,
            Cmd::call(fn () => null),
        );

        self::assertTrue($queue->isEmpty());
    }

    #[Test]
    public function dispatchCommandCallConvertsThrowableToErrorMessage(): void
    {
        $error = new RuntimeException('call failed');
        $program = Program::new($this->createMockModel());
        $queue = new SplQueue();
        (new ReflectionProperty(Program::class, 'messageQueue'))->setValue($program, $queue);

        (new ReflectionMethod(Program::class, 'dispatchCommand'))->invoke(
            $program,
            Cmd::call(fn () => throw $error),
        );

        self::assertSame(1, $queue->count());
        $message = $queue->dequeue();
        self::assertInstanceOf(ErrorMsg::class, $message);
        self::assertSame($error, $message->error);
        self::assertSame(ErrorSource::Call, $message->source);
    }

    #[Test]
    public function drainMessageQueueForceQuitsAfterTenConsecutiveUpdateErrors(): void
    {
        $throwingModel = new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                throw new RuntimeException('update boom');
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($throwingModel);

        $modelProperty = new ReflectionProperty(Program::class, 'model');
        $modelProperty->setValue($program, $throwingModel);

        (new ReflectionProperty(Program::class, 'state'))->setValue($program, ProgramState::Running);

        $queueProperty = new ReflectionProperty(Program::class, 'messageQueue');
        $queue = new SplQueue();
        for ($i = 0; $i < 15; ++$i) {
            $queue->enqueue(new TickMsg(0.0));
        }
        $queueProperty->setValue($program, $queue);

        $method = new ReflectionMethod(Program::class, 'drainMessageQueue');
        $method->invoke($program);

        self::assertNotSame(ProgramState::Running, $program->state(), 'running must be false after 10 consecutive update errors');
        self::assertFalse($queue->isEmpty(), 'drain must return early leaving remaining messages unprocessed');
    }

    #[Test]
    public function teardownIsIdempotent(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $session = new TerminalSession($terminal, false, false);
        $session->start();

        (new ReflectionProperty(Program::class, 'terminalSession'))->setValue($program, $session);

        $method = new ReflectionMethod(Program::class, 'teardown');
        $method->invoke($program);

        $firstOutput = $terminal->output();

        $method->invoke($program);

        self::assertSame($firstOutput, $terminal->output(), 'teardown() called twice must not produce extra output');
    }

    #[Test]
    public function teardownWritesResetStyleAndShowCursor(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $session = new TerminalSession($terminal, false, false);
        $session->start();

        (new ReflectionProperty(Program::class, 'terminalSession'))->setValue($program, $session);

        $method = new ReflectionMethod(Program::class, 'teardown');
        $method->invoke($program);

        $output = $terminal->output();
        self::assertStringContainsString(Ansi::RESET_STYLE, $output, 'teardown must emit RESET_STYLE');
        self::assertStringContainsString(Ansi::SHOW_CURSOR, $output, 'teardown must re-show cursor');
    }

    #[Test]
    public function teardownInlineModeMovesCursorToLastRow(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $session = new TerminalSession($terminal, false, false);
        $session->start();

        (new ReflectionProperty(Program::class, 'terminalSession'))->setValue($program, $session);

        $method = new ReflectionMethod(Program::class, 'teardown');
        $method->invoke($program);

        $output = $terminal->output();
        self::assertStringContainsString(Ansi::cursorToRowStart(23) . "\n", $output, 'inline mode must position cursor at height-1 row');
    }

    #[Test]
    public function teardownAltScreenModeDoesNotMoveCursor(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel())->withAltScreen();
        $session = new TerminalSession($terminal, true, false);
        $session->start();

        (new ReflectionProperty(Program::class, 'terminalSession'))->setValue($program, $session);

        $method = new ReflectionMethod(Program::class, 'teardown');
        $method->invoke($program);

        $output = $terminal->output();
        self::assertStringNotContainsString(Ansi::cursorToRowStart(23), $output, 'alt-screen mode must NOT emit cursor repositioning');
    }

    #[Test]
    public function startComposesWithAHostEventLoopWithoutStoppingItsDriver(): void
    {
        $quitOnFirstMessageModel = new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this, Cmd::quit());
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($quitOnFirstMessageModel)->withTerminal($terminal);

        $programFinished = false;
        $hostTimerFired = false;

        EventLoop::queue(function () use ($program, &$programFinished): void {
            $program->start();
            $programFinished = true;
        });
        // The host timer doubles as the run() terminator so watchers leaked by
        // unrelated tests cannot keep the loop alive; if the program had stopped
        // the shared driver, run() would return before this ever fires.
        EventLoop::delay(0.2, function () use (&$hostTimerFired): void {
            $hostTimerFired = true;
            EventLoop::getDriver()->stop();
        });

        EventLoop::run();

        self::assertTrue($programFinished, 'Program embedded in a host loop must run to completion (nested EventLoop::run() is fatal in Revolt)');
        self::assertTrue($hostTimerFired, 'The host application timer must survive the program quitting — stopping the global driver kills unrelated watchers');
    }

    #[Test]
    public function startBuildsScreenWithTheTerminalColorProfile(): void
    {
        $hasRendered = false;
        $renderThenQuitModel = new class($hasRendered) implements Model {
            public function __construct(public bool &$hasRendered)
            {
            }

            public function init(): ?Cmd
            {
                return Cmd::tick(0.005);
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof TickMsg && $this->hasRendered) {
                    return new UpdateResult($this, Cmd::quit());
                }
                $next = new self($this->hasRendered);

                return new UpdateResult($next);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                $this->hasRendered = true;
                $frame->putStyled(0, 0, 'R', new Style(foreground: Color::rgb(255, 0, 0)));
            }
        };

        $terminal = new VirtualTerminal(80, 24, ColorProfile::Ansi);
        Program::new($renderThenQuitModel)->withTerminal($terminal)->start();

        self::assertStringContainsString('R', $terminal->output(), 'Precondition: at least one frame must have rendered');
        self::assertStringNotContainsString('38;2;255;0;0', $terminal->output(), 'Program must construct the Screen with the detected terminal color profile so truecolor degrades on 16-color terminals');
    }

    #[Test]
    public function drainMessageQueueStopsOnQuitMsg(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $queue = new SplQueue();
        $queue->enqueue(QuitMsg::Quit);
        $tickMsg = new TickMsg(0.0);
        $queue->enqueue($tickMsg);
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertNotSame(ProgramState::Running, $program->state(), 'QuitMsg must stop the drain');
        self::assertSame(1, $queue->count(), 'QuitMsg must return immediately, leaving the one queued TickMsg untouched');
        self::assertSame($tickMsg, $queue->dequeue(), 'Untouched message must be the original TickMsg, not an ErrorMsg from a later iteration');
    }

    #[Test]
    public function drainMessageQueueStopsDeliveringAfterQuitCmd(): void
    {
        $model = new class implements Model {
            public int $updates = 0;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                ++$this->updates;

                return new UpdateResult($this, Cmd::quit());
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($model);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $model);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $queue = new SplQueue();
        $queue->enqueue(new TestMessage('first'));
        $queue->enqueue(new TestMessage('second'));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertNotSame(ProgramState::Running, $program->state(), 'Cmd::quit must stop the program');
        self::assertSame(1, $model->updates, 'No further messages may be delivered once the model requested quit');
    }

    #[Test]
    public function dispatchCommandBatchCmdDispatchesAllChildren(): void
    {
        $program = Program::new($this->createMockModel());

        $queueProperty = new ReflectionProperty(Program::class, 'messageQueue');
        $queue = new SplQueue();
        $queueProperty->setValue($program, $queue);
        (new ReflectionProperty(Program::class, 'state'))->setValue($program, ProgramState::Running);

        $method = new ReflectionMethod(Program::class, 'dispatchCommand');
        $method->invoke($program, Cmd::batch(Cmd::message(new TestMessage('a')), Cmd::message(new TestMessage('b')), Cmd::quit()));

        self::assertSame('a', $queue->dequeue()->value, 'First batch child dispatched');
        self::assertSame('b', $queue->dequeue()->value, 'Second batch child dispatched');
        self::assertNotSame(ProgramState::Running, $program->state(), 'Third batch child (quit) dispatched');
    }

    #[Test]
    public function execCmdRejectsEmptyCommand(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cmd::exec([]);
    }

    #[Test]
    public function processCmdRejectsEmptyCommand(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cmd::process([]);
    }

    #[Test]
    public function tickCmdRejectsZeroInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cmd::tick(0.0);
    }

    #[Test]
    public function tickCmdRejectsNegativeInterval(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Cmd::tick(-1.0);
    }

    #[Test]
    public function tickCmdAcceptsPositiveInterval(): void
    {
        $command = Cmd::tick(0.5);
        self::assertInstanceOf(Cmd::class, $command);
    }

    #[Test]
    public function suspendGuardsPosixFunctions(): void
    {
        $source = file_get_contents((new ReflectionClass(Program::class))->getFileName());
        self::assertStringContainsString("function_exists('posix_kill')", $source);
    }

    #[Test]
    public function signalRegistrationIsGuardedBehindPcntlSupport(): void
    {
        $source = file_get_contents((new ReflectionClass(Program::class))->getFileName());
        self::assertStringContainsString(
            "extension_loaded('pcntl')",
            $source,
            'SIGWINCH etc. are pcntl constants — referencing them without the extension is a fatal Error',
        );
        self::assertStringContainsString(
            'UnsupportedFeatureException',
            $source,
            'EventLoop::onSignal throws UnsupportedFeatureException on drivers without signal support; setup must survive that',
        );
    }

    #[Test]
    public function suspendGuardsPcntlSignalSeparatelyFromPosix(): void
    {
        $source = file_get_contents((new ReflectionClass(Program::class))->getFileName());
        self::assertStringContainsString(
            "function_exists('pcntl_signal')",
            $source,
            'pcntl_signal() lives in ext-pcntl, not ext-posix — a posix_kill check alone does not guard it',
        );
    }

    #[Test]
    public function setupIsInsideTryBlock(): void
    {
        $source = file_get_contents((new ReflectionClass(Program::class))->getFileName());
        $tryPosition = strpos($source, 'try {');
        $setupCallPosition = strpos($source, '$this->setup()');
        self::assertNotFalse($tryPosition);
        self::assertNotFalse($setupCallPosition);
        self::assertLessThan($setupCallPosition, $tryPosition);
    }

    #[Test]
    public function drainMessageQueueStopsAfterConsecutiveErrors(): void
    {
        $model = new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                throw new RuntimeException('always fails');
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($model);
        $reflection = new ReflectionClass($program);

        $queueProperty = $reflection->getProperty('messageQueue');
        $modelProperty = $reflection->getProperty('model');
        $drainMethod = $reflection->getMethod('drainMessageQueue');

        $queue = new SplQueue();
        $queue->enqueue(new TestMessage('trigger'));
        $queueProperty->setValue($program, $queue);
        $modelProperty->setValue($program, $model);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $drainMethod->invoke($program);

        self::assertNotSame(
            ProgramState::Running,
            $program->state(),
            'Program should force-quit after consecutive errors instead of looping forever'
        );
    }

    #[Test]
    public function aSecondDrainInTheSameTickContinuesTheBudgetInsteadOfBuyingAFreshOne(): void
    {
        $clock = new TestClock(0.0);
        $program = $this->buildTickableProgram($clock);

        $model = new class($clock) implements Model {
            public int $updates = 0;

            public function __construct(private readonly Clock $clock)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                ++$this->updates;
                $this->clock->advance(0.025);

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $reflection = new ReflectionClass($program);
        $queue = new SplQueue();
        for ($message = 0; $message < 8; ++$message) {
            $queue->enqueue(new TickMsg(0.0));
        }
        $reflection->getProperty('messageQueue')->setValue($program, $queue);
        $reflection->getProperty('model')->setValue($program, $model);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $program->tick();

        self::assertSame(
            3,
            $model->updates,
            'Two 25 ms updates exhaust the 50 ms budget; the re-drain may serve one forced message, no more'
        );
    }

    #[Test]
    public function handlePendingResizeIsNoopWhenResizeNotPending(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('resizePending')->setValue($program, false);
        $queueProperty = $reflection->getProperty('messageQueue');
        $queue = new SplQueue();
        $queueProperty->setValue($program, $queue);
        $reflection->getProperty('frames')->getValue($program)->drawn(0.0, null);
        $reflection->getProperty('resizeNeedsScreenClear')->setValue($program, false);

        $method = $reflection->getMethod('handlePendingResize');
        $method->invoke($program);

        self::assertTrue($queue->isEmpty(), 'No ResizeMsg should be enqueued when no resize is pending');
        self::assertFalse($reflection->getProperty('frames')->getValue($program)->isDirty());
        self::assertFalse($reflection->getProperty('resizeNeedsScreenClear')->getValue($program));
    }

    #[Test]
    public function setupRegistersAndTeardownCancelsAllSignalWatchers(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());

        $reflection = new ReflectionClass($program);
        $terminalProperty = $reflection->getProperty('terminal');
        $terminalProperty->setValue($program, $terminal);
        $screenProperty = $reflection->getProperty('screen');
        $screenProperty->setValue($program, new Screen($terminal));
        $queueProperty = $reflection->getProperty('messageQueue');
        $queueProperty->setValue($program, new SplQueue());
        $stdinBufferProperty = $reflection->getProperty('stdinBuffer');
        $stdinBufferProperty->setValue($program, new StdinBuffer(fn () => null));

        $watchersProperty = $reflection->getProperty('watchers');

        $reflection->getMethod('setup')->invoke($program);

        $registered = $watchersProperty->getValue($program);
        self::assertIsArray($registered);
        foreach ([
            EventLoopWatcher::Resize,
            EventLoopWatcher::Interrupt,
            EventLoopWatcher::Terminate,
            EventLoopWatcher::Hangup,
            EventLoopWatcher::Suspend,
            EventLoopWatcher::Resumed,
        ] as $watcher) {
            self::assertArrayHasKey(
                $watcher->name,
                $registered,
                "the {$watcher->name} watcher must be held after setup() so teardown can cancel it",
            );
        }

        $reflection->getMethod('teardown')->invoke($program);

        self::assertSame([], $watchersProperty->getValue($program), 'teardown() must let every watcher go');
        $activeIds = EventLoop::getIdentifiers();
        foreach ($registered as $id) {
            self::assertNotContains($id, $activeIds, 'Watcher must be cancelled in EventLoop, not just forgotten');
        }
    }

    #[Test]
    public function setupEnablesBracketedPaste(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));

        $reflection->getMethod('setup')->invoke($program);
        $enabledAfterSetup = $terminal->isBracketedPasteEnabled();

        (new ReflectionMethod(Program::class, 'teardown'))->invoke($program);

        self::assertTrue($enabledAfterSetup, 'setup() must enable bracketed paste — StdinBuffer only ever sees \x1b[200~ delimiters when mode 2004 is on');
    }

    #[Test]
    public function teardownDisablesBracketedPaste(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));

        $reflection->getMethod('setup')->invoke($program);
        $reflection->getMethod('teardown')->invoke($program);

        self::assertFalse($terminal->isBracketedPasteEnabled(), 'teardown() must switch bracketed paste off — the mode leaks into the parent shell otherwise');
    }

    #[Test]
    public function suspensionRestoreDisablesAndResumeReEnablesBracketedPaste(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $session = new TerminalSession($terminal, false, false);
        $session->start();

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('terminalSession')->setValue($program, $session);

        $reflection->getMethod('restoreTerminalForSuspension')->invoke($program);
        self::assertFalse($terminal->isBracketedPasteEnabled(), 'Ctrl-Z hand-back must restore the shell without bracketed paste');

        $reflection->getMethod('resume')->invoke($program);
        $reflection->getMethod('cancelEventLoopWatchers')->invoke($program);
        self::assertTrue($terminal->isBracketedPasteEnabled(), 'resume after SIGCONT must re-enable bracketed paste');
        $session->close();
    }

    #[Test]
    public function setupEnqueuesInitialResizeMessage(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $queueProperty = $reflection->getProperty('messageQueue');
        $queue = new SplQueue();
        $queueProperty->setValue($program, $queue);
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));

        $reflection->getMethod('setup')->invoke($program);

        $found = false;
        while (!$queue->isEmpty()) {
            if ($queue->dequeue() instanceof ResizeMsg) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'setup() must enqueue an initial ResizeMsg so the model sees the screen size on first tick');

        (new ReflectionMethod(Program::class, 'teardown'))->invoke($program);
    }

    #[Test]
    public function restoreTerminalForSuspensionWritesShowCursorAndResetStyle(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $session = new TerminalSession($terminal, false, false);
        $session->start();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminalSession')->setValue($program, $session);

        $reflection->getMethod('restoreTerminalForSuspension')->invoke($program);

        $output = $terminal->output();
        self::assertStringContainsString(Ansi::SHOW_CURSOR, $output, 'restoreTerminalForSuspension must re-show cursor');
        self::assertStringContainsString(Ansi::RESET_STYLE, $output, 'restoreTerminalForSuspension must reset SGR style');
    }

    #[Test]
    public function resumeRestoresTerminalAndMarksDirtyAndEnqueuesResize(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $screen = new Screen($terminal);
        $reflection->getProperty('screen')->setValue($program, $screen);
        $reflection->getProperty('frames')->getValue($program)->drawn(0.0, null);
        $queueProperty = $reflection->getProperty('messageQueue');
        $queue = new SplQueue();
        $queueProperty->setValue($program, $queue);
        $frame = new Frame(80, 24);
        $screen->flush($frame);
        $terminal->clearOutput();

        $reflection->getMethod('resume')->invoke($program);
        $reflection->getMethod('cancelEventLoopWatchers')->invoke($program);

        self::assertTrue($reflection->getProperty('frames')->getValue($program)->isDirty(), 'resume must mark renderer dirty so the next frame is drawn');

        $found = false;
        while (!$queue->isEmpty()) {
            if ($queue->dequeue() instanceof ResizeMsg) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'resume must enqueue ResizeMsg so the model re-runs layout');

        $screen->flush($frame);
        self::assertStringContainsString(Ansi::CURSOR_HOME, $terminal->output(), 'the frame after a resume is drawn whole, so what another app left cannot show through');
    }

    #[Test]
    public function drainMessageQueueErrorThresholdHitsAtExactlyTenCalls(): void
    {
        $countingModel = new class implements Model {
            public int $callCount = 0;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                ++$this->callCount;
                throw new RuntimeException('boom');
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($countingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $queue = new SplQueue();
        for ($i = 0; $i < 15; ++$i) {
            $queue->enqueue(new TickMsg(0.0));
        }
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertSame(10, $countingModel->callCount, 'Threshold (>= 10) and init (=0) together produce exactly 10 update calls. Init=-1 → 11 calls; >10 → 11 calls.');
    }

    #[Test]
    public function drainMessageQueueResetsConsecutiveErrorCountAfterSuccess(): void
    {
        $alternatingModel = new class implements Model {
            public int $callCount = 0;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                ++$this->callCount;
                if ($this->callCount === 6) {
                    return new UpdateResult($this);
                }
                throw new RuntimeException('boom');
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($alternatingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $alternatingModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $queue = new SplQueue();
        for ($i = 0; $i < 50; ++$i) {
            $queue->enqueue(new TickMsg(0.0));
        }
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertSame(16, $alternatingModel->callCount, 'After 5 errors + 1 success + 10 errors → threshold hit at call #16. Reset to -1 would defer threshold to call #17.');
    }

    #[Test]
    public function drainMessageQueueRoutesErrorMsgToErrorHandler(): void
    {
        $handlerCalled = false;
        $program = Program::new($this->createMockModel())
            ->onError(function (ErrorMsg $err) use (&$handlerCalled) {
                $handlerCalled = true;

                return null;
            });

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $queue = new SplQueue();
        $queue->enqueue(new ErrorMsg(new RuntimeException('test'), ErrorSource::Update));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertTrue($handlerCalled, 'ErrorMsg must be routed to the errorHandler, not to model.update()');
    }

    #[Test]
    public function drainMessageQueueContinuesAfterErrorMsgHandling(): void
    {
        $tickReceived = false;
        $observingModel = new class($tickReceived) implements Model {
            public function __construct(public bool &$tickReceived)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof TickMsg) {
                    $this->tickReceived = true;
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($observingModel)->onError(fn () => null);

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $queue = new SplQueue();
        $queue->enqueue(new ErrorMsg(new RuntimeException('test'), ErrorSource::Update));
        $queue->enqueue(new TickMsg(0.0));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertTrue($tickReceived, 'After handling ErrorMsg, drain must CONTINUE to next message (TickMsg) instead of breaking');
    }

    #[Test]
    public function drainMessageQueueSurvivesThrowingErrorHandler(): void
    {
        $tickReceived = false;
        $observingModel = new class($tickReceived) implements Model {
            public function __construct(public bool &$tickReceived)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof TickMsg) {
                    $this->tickReceived = true;
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($observingModel)
            ->onError(fn (ErrorMsg $error) => throw new RuntimeException('handler boom'));

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $queue = new SplQueue();
        $queue->enqueue(new ErrorMsg(new RuntimeException('original'), ErrorSource::Render));
        $queue->enqueue(new TickMsg(0.0));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertSame(ProgramState::Running, $program->state(), 'A single throwing errorHandler call must not stop the program');
        self::assertTrue($tickReceived, 'Messages after the handled ErrorMsg must still be processed');
    }

    #[Test]
    public function drainMessageQueueDispatchesCmdReturnedByErrorHandler(): void
    {
        $messageDelivered = null;
        $observingModel = new class($messageDelivered) implements Model {
            public function __construct(public mixed &$messageDelivered)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof TestMessage && $message->value === 'from-handler') {
                    $this->messageDelivered = $message;
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($observingModel)
            ->onError(fn (ErrorMsg $e) => Cmd::message(new TestMessage('from-handler')));

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $queue = new SplQueue();
        $queue->enqueue(new ErrorMsg(new RuntimeException('test'), ErrorSource::Update));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertSame('from-handler', $messageDelivered->value, 'Cmd returned by errorHandler must be dispatched (instanceof Cmd check + dispatchCommand)');
    }

    #[Test]
    public function errorsTheHandlerDealtWithNeverStopTheProgram(): void
    {
        $model = new class implements Model {
            public bool $reached = false;

            public function init(): ?Cmd
            {
                $failing = array_fill(0, 15, Cmd::call(static fn () => throw new RuntimeException('boom')));

                return Cmd::batch(...$failing, ...[Cmd::message(new TestMessage('after'))]);
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof TestMessage) {
                    $this->reached = true;

                    return new UpdateResult($this, Cmd::quit());
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
        $handled = 0;

        Program::new($model)
            ->withTerminal(new VirtualTerminal(80, 24))
            ->onError(function () use (&$handled): ?Cmd {
                ++$handled;

                return null;
            })
            ->start();

        self::assertSame(15, $handled);
        self::assertTrue($model->reached);
    }

    #[Test]
    public function anUpdateFailureIsReportedBeforeTheNextQueuedKey(): void
    {
        $model = new class implements Model {
            /**
             * @var list<string>
             */
            public array $seen = [];

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof ErrorMsg) {
                    $this->seen[] = 'error';
                }
                if (!$message instanceof KeyMsg) {
                    return new UpdateResult($this);
                }
                $this->seen[] = $message->key;

                return match ($message->key) {
                    'a' => throw new RuntimeException('a failed'),
                    'q' => new UpdateResult($this, Cmd::quit()),
                    default => new UpdateResult($this),
                };
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('abq');

        Program::new($model)->withTerminal($terminal)->start();

        self::assertSame(['a', 'error', 'b', 'q'], $model->seen);
    }

    #[Test]
    public function closedInputEndsTheProgramAfterTheKeysAlreadySent(): void
    {
        $model = new class implements Model {
            /**
             * @var list<string>
             */
            public array $keys = [];

            public bool $timedOut = false;

            public function init(): ?Cmd
            {
                return Cmd::after(2.0, function (): Cmd {
                    $this->timedOut = true;

                    return Cmd::quit();
                });
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof KeyMsg) {
                    $this->keys[] = $message->key;
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('ab');
        $terminal->closeInput();

        Program::new($model)->withTerminal($terminal)->start();

        self::assertSame(['a', 'b'], $model->keys);
        self::assertFalse($model->timedOut);
    }

    #[Test]
    public function aRenderThatNeverRecoversEndsTheProgramWithItsError(): void
    {
        $model = new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                throw new RuntimeException('render is broken');
            }
        };

        $this->expectExceptionObject(new RuntimeException('render is broken'));

        Program::new($model)->withTerminal(new VirtualTerminal(80, 24))->start();
    }

    #[Test]
    public function tenErrorsInARowFromTheHandlerItselfStopTheProgram(): void
    {
        $model = new class implements Model {
            public bool $reached = false;

            public function init(): ?Cmd
            {
                $failing = array_fill(0, 15, Cmd::call(static fn () => throw new RuntimeException('boom')));

                return Cmd::batch(...$failing, ...[Cmd::message(new TestMessage('after'))]);
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof TestMessage) {
                    $this->reached = true;

                    return new UpdateResult($this, Cmd::quit());
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
        $attempts = 0;

        $program = Program::new($model)
            ->withTerminal(new VirtualTerminal(80, 24))
            ->onError(function () use (&$attempts): ?Cmd {
                ++$attempts;
                throw new RuntimeException('handler broken');
            });
        $thrown = null;
        try {
            $program->start();
        } catch (RuntimeException $error) {
            $thrown = $error;
        }

        self::assertSame('handler broken', $thrown?->getMessage());
        self::assertSame(10, $attempts);
        self::assertFalse($model->reached);
    }

    #[Test]
    public function drainMessageQueueDoesNotMarkDirtyWhenRenderRevisionedModelRevisionIsUnchanged(): void
    {
        $constantRevisionModel = new class implements Model, RenderRevisioned {
            public int $internalCounter = 0;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                $next = new self();
                $next->internalCounter = $this->internalCounter + 1;

                return new UpdateResult($next);
            }

            public function renderRevision(): int
            {
                return 42;
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($constantRevisionModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $constantRevisionModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);
        $reflection->getProperty('frames')->getValue($program)->drawn(0.0, null);

        $queue = new SplQueue();
        $queue->enqueue(new TickMsg(0.0));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertNotSame($constantRevisionModel, $reflection->getProperty('model')->getValue($program), 'Model instance must have swapped (update returned new self())');
        self::assertFalse($reflection->getProperty('frames')->getValue($program)->isDirty(), 'When the model implements RenderRevisioned and renderRevision() is unchanged, isDirty must stay false even though the model instance differs');
    }

    #[Test]
    public function drainMessageQueueMarksDirtyWhenRenderRevisionedRevisionChanges(): void
    {
        $changingRevisionModel = new class implements Model, RenderRevisioned {
            public int $rev = 0;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                $next = new self();
                $next->rev = $this->rev + 1;

                return new UpdateResult($next);
            }

            public function renderRevision(): int
            {
                return $this->rev;
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($changingRevisionModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $changingRevisionModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);
        $reflection->getProperty('frames')->getValue($program)->drawn(0.0, null);

        $queue = new SplQueue();
        $queue->enqueue(new TickMsg(0.0));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertTrue($reflection->getProperty('frames')->getValue($program)->isDirty(), 'renderRevision actually changed (0 → 1) → isDirty must flip to true');
    }

    #[Test]
    public function drainMessageQueueMarksDirtyWhenModelChanges(): void
    {
        $changingModel = new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult(new self());
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($changingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $changingModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);
        $reflection->getProperty('frames')->getValue($program)->drawn(0.0, null);

        $queue = new SplQueue();
        $queue->enqueue(new TickMsg(0.0));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertTrue(
            $reflection->getProperty('frames')->getValue($program)->isDirty(),
            'isDirty must be true after the model instance changed during update'
        );
    }

    #[Test]
    public function oneQueueDrainReusesTheSameScreenRectForEveryMessage(): void
    {
        $observingModel = new class implements Model {
            /**
             * @var list<Rect>
             */
            public array $areas = [];

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                $this->areas[] = $area;

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($observingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(120, 40));

        $queue = new SplQueue();
        $queue->enqueue(new TickMsg(1.0));
        $queue->enqueue(new TickMsg(2.0));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertCount(2, $observingModel->areas);
        self::assertSame($observingModel->areas[0], $observingModel->areas[1]);
        self::assertSame(120, $observingModel->areas[0]->width);
        self::assertSame(40, $observingModel->areas[0]->height);
    }

    #[Test]
    public function drainMessageQueueStampsTheCurrentScreenRectOntoAMouseMsgBeforeDispatch(): void
    {
        $observingModel = new class implements Model {
            public ?MouseMsg $received = null;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof MouseMsg) {
                    $this->received = $message;
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($observingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(120, 40));

        $queue = new SplQueue();
        $queue->enqueue(new MouseMsg(3, 4, MouseAction::Press, MouseButton::Left));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertNotNull($observingModel->received);
        self::assertEquals(new Rect(0, 0, 120, 40), $observingModel->received->screen, 'The rect stamped on the message must match the one the frame will be built from');
    }

    #[Test]
    public function drainMessageQueueStampsTheCurrentScreenRectOntoAKeyMsgBeforeDispatch(): void
    {
        $observingModel = new class implements Model {
            public ?KeyMsg $received = null;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof KeyMsg) {
                    $this->received = $message;
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($observingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(120, 40));

        $queue = new SplQueue();
        $queue->enqueue(new KeyMsg('x'));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertNotNull($observingModel->received);
        self::assertEquals(new Rect(0, 0, 120, 40), $observingModel->received->screen, 'The rect stamped on the key message must match the one the frame will be built from');
    }

    #[Test]
    public function theStampedScreenRectIsTheAreaTheFrameWasDrawnInto(): void
    {
        $observingModel = new class implements Model {
            public ?MouseMsg $received = null;
            public ?Rect $renderedArea = null;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof MouseMsg) {
                    $this->received = $message;
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                $this->renderedArea = $frame->area();
            }
        };

        $program = $this->createRenderablePrgram($observingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getMethod('renderFrame')->invoke($program);

        $queue = new SplQueue();
        $queue->enqueue(new MouseMsg(3, 4, MouseAction::Press, MouseButton::Left));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);
        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertNotNull($observingModel->received);
        self::assertEquals($observingModel->renderedArea, $observingModel->received->screen);
    }

    #[Test]
    public function aPressOverADrawnFloatArrivesAsAFloatMouseMsg(): void
    {
        $popup = new TestPopup(new Rect(5, 5, 10, 4));
        $model = new FloatingModel($popup);

        $program = $this->createRenderablePrgram($model);
        $reflection = new ReflectionClass($program);
        $reflection->getMethod('renderFrame')->invoke($program);

        $queue = new SplQueue();
        $queue->enqueue(new MouseMsg(6, 6, MouseAction::Press, MouseButton::Left));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);
        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertInstanceOf(FloatMouseMsg::class, $model->received);
        self::assertSame($popup, $model->received->popup());
    }

    #[Test]
    public function twoQueuedWheelEventsOverAFloatArriveAsOneMessageWithBothNotches(): void
    {
        $popup = new TestPopup(new Rect(5, 5, 10, 4));
        $model = new FloatingModel($popup);

        $program = $this->createRenderablePrgram($model);
        $reflection = new ReflectionClass($program);
        $reflection->getMethod('renderFrame')->invoke($program);

        $enqueue = $reflection->getMethod('enqueueInputMessage');
        $enqueue->invoke($program, new MouseMsg(6, 6, MouseAction::WheelDown, MouseButton::None));
        $enqueue->invoke($program, new MouseMsg(6, 6, MouseAction::WheelDown, MouseButton::None));

        $reflection->getMethod('flushWheel')->invoke($program);
        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertInstanceOf(FloatMouseMsg::class, $model->received);
        self::assertSame(2, $model->received->mouse->repeat);
        self::assertSame(1, $model->messagesSeen, 'coalescing must still merge the run into one message');
    }

    #[Test]
    public function aRenderThatThrowsAfterRenderRootKeepsRoutingOverTheFrameOnTheScreen(): void
    {
        $shown = new TestPopup(new Rect(0, 0, 4, 2), 'S');
        $neverShown = new TestPopup(new Rect(10, 10, 6, 3), 'N');
        $model = new FloatingModel($shown);

        $program = $this->createRenderablePrgram($model);
        $reflection = new ReflectionClass($program);
        $reflection->getMethod('renderFrame')->invoke($program);

        $model->float = $neverShown;
        $model->throwAfterRenderRoot = true;
        $reflection->getMethod('renderFrame')->invoke($program);

        $queue = new SplQueue();
        $queue->enqueue(new MouseMsg(11, 11, MouseAction::Press, MouseButton::Left));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);
        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertInstanceOf(MouseMsg::class, $model->received, 'a frame that never reached the screen must not route input');
    }

    #[Test]
    public function nextRenderDelayTracksTheExactFrameDeadline(): void
    {
        $clock = new TestClock(10.25);
        $program = Program::new($this->createMockModel())
            ->withClock($clock)
            ->withFps(2.0);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('frames')->getValue($program)->drawn(10.0, null);
        $reflection->getProperty('frames')->getValue($program)->markDirty();
        $nextRenderDelay = $reflection->getMethod('nextRenderDelay');

        self::assertEqualsWithDelta(0.25, $nextRenderDelay->invoke($program), 0.000_001);

        $clock->advance(1.0);
        self::assertSame(0.0, $nextRenderDelay->invoke($program));

        $reflection->getProperty('frames')->getValue($program)->drawn(0.0, null);
        self::assertNull($nextRenderDelay->invoke($program));
    }

    #[Test]
    public function oneQueueDrainHasAFairnessBudget(): void
    {
        $countingModel = new class implements Model {
            public int $updates = 0;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                ++$this->updates;

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($countingModel)->withClock(new TestClock(100.0));
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $queue = new SplQueue();
        for ($index = 0; $index < 1_100; ++$index) {
            $queue->enqueue(new TestMessage((string)$index));
        }
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertSame(1_024, $countingModel->updates);
        self::assertCount(76, $queue, 'A message storm must yield back to rendering and the event loop');
    }

    #[Test]
    public function aSlowDrainYieldsOnceItHasTakenFiftyMilliseconds(): void
    {
        $clock = new TestClock(100.0);
        $slowModel = new class($clock) implements Model {
            public int $updates = 0;

            public function __construct(private readonly TestClock $clock)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                ++$this->updates;
                $this->clock->advance(0.01);

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($slowModel)->withClock($clock);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $slowModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $queue = new SplQueue();
        for ($index = 0; $index < 20; ++$index) {
            $queue->enqueue(new TestMessage((string)$index));
        }
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertSame(5, $slowModel->updates);
    }

    #[Test]
    public function runTickKeepsTickerScheduledWhenRenderErrorIsQueued(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $throwingRenderModel = new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                throw new RuntimeException('render boom');
            }
        };

        $program = Program::new($throwingRenderModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 24));
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $throwingRenderModel);
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);
        $reflection->getProperty('frames')->getValue($program)->markDirty();

        $ticker = new AdaptiveTicker(fn () => null);
        $reflection->getProperty('adaptiveTicker')->setValue($program, $ticker);

        $reflection->getMethod('runTick')->invoke($program);

        $queue = $reflection->getProperty('messageQueue')->getValue($program);
        $callbackId = (new ReflectionProperty($ticker, 'callbackId'))->getValue($ticker);
        $ticker->stop();

        self::assertFalse($queue->isEmpty(), 'Precondition: the render error must sit in the queue after the tick');
        self::assertNotNull($callbackId, 'A queued ErrorMsg must keep the ticker scheduled — otherwise the error is stranded until unrelated input arrives');
    }

    #[Test]
    public function flushingALoneEscapeAlsoDrainsAndRendersIt(): void
    {
        // A bare Escape is held back briefly in case it starts a CSI sequence.
        // When the timer flushes it the loop is idle, so the flush has to tick:
        // otherwise the keystroke sits in the queue until the next one arrives,
        // and every Escape appears to take effect one keypress late.
        $observer = new class implements Model {
            /**
             * @var list<string>
             */
            public array $keys = [];

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof KeyMsg) {
                    $this->keys[] = is_string($message->key) ? $message->key : $message->key->name;
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = $this->createRenderablePrgram($observer);
        $reflection = new ReflectionClass($program);
        $ticker = new AdaptiveTicker(fn () => null);
        $reflection->getProperty('adaptiveTicker')->setValue($program, $ticker);

        $handler = $reflection->getMethod('handleInputSequence');
        $reflection->getProperty('stdinBuffer')->setValue(
            $program,
            new StdinBuffer(
                static fn (string $sequence): mixed => $handler->invoke($program, $sequence),
            ),
        );

        // Feed the lone Escape; it stays buffered.
        $buffer = $reflection->getProperty('stdinBuffer')->getValue($program);
        self::assertInstanceOf(StdinBuffer::class, $buffer);
        $buffer->process("\x1b");
        self::assertSame([], $observer->keys, 'A lone Escape waits for the disambiguation timeout');

        $reflection->getMethod('flushPendingInput')->invoke($program);

        self::assertSame(['Escape'], $observer->keys, 'The flush delivers it without waiting for another key');

        $ticker->stop();
    }

    #[Test]
    public function renderFrameStampsTheFrameTimeFromTheClock(): void
    {
        $observingModel = new class implements Model {
            public ?float $seenNow = null;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                $this->seenNow = $pass->context->now();
            }
        };

        $program = $this->createRenderablePrgram($observingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('clock')->setValue($program, new TestClock(5.0));

        $reflection->getMethod('renderFrame')->invoke($program);

        self::assertSame(5.0, $observingModel->seenNow, 'render() must observe the monotonic frame time via RenderContext::now()');
    }

    #[Test]
    public function animatedRenderSchedulesARepaintWithoutNewMessages(): void
    {
        $animatedModel = new class implements Model {
            public int $renders = 0;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                ++$this->renders;
                $pass->context->requestRepaint(0.05);
            }
        };

        $clock = new TestClock(1.0);
        $program = $this->createRenderablePrgram($animatedModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('clock')->setValue($program, $clock);

        $ticker = new AdaptiveTicker(fn () => null);
        $reflection->getProperty('adaptiveTicker')->setValue($program, $ticker);

        $reflection->getMethod('runTick')->invoke($program);
        self::assertSame(1, $animatedModel->renders);
        self::assertFalse($reflection->getProperty('frames')->getValue($program)->isDirty());

        // Advancing to exactly the requested delay must already repaint (>=, not >).
        $clock->advance(0.05);
        $reflection->getMethod('runTick')->invoke($program);
        $ticker->stop();

        self::assertSame(2, $animatedModel->renders, 'A repaint request must produce another frame without any message traffic');
    }

    #[Test]
    public function animationRepaintWaitsUntilTheRequestedDelayElapsed(): void
    {
        $animatedModel = new class implements Model {
            public int $renders = 0;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                ++$this->renders;
                $pass->context->requestRepaint(0.5);
            }
        };

        $clock = new TestClock(1.0);
        $program = $this->createRenderablePrgram($animatedModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('clock')->setValue($program, $clock);

        $ticker = new AdaptiveTicker(fn () => null);
        $reflection->getProperty('adaptiveTicker')->setValue($program, $ticker);

        $reflection->getMethod('runTick')->invoke($program);
        self::assertSame(1, $animatedModel->renders);

        $clock->advance(0.1);
        $reflection->getMethod('runTick')->invoke($program);
        $ticker->stop();

        self::assertSame(1, $animatedModel->renders, 'No repaint before the requested delay has elapsed');

        $delay = $reflection->getMethod('nextRenderDelay')->invoke($program);
        self::assertNotNull($delay, 'The pending animation repaint must keep the loop scheduled');
        self::assertEqualsWithDelta(0.4, $delay, 0.0001);
    }

    #[Test]
    public function runTickKeepsFrameDirtyWhenRenderFails(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $throwingRenderModel = new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                throw new RuntimeException('render boom');
            }
        };

        $program = Program::new($throwingRenderModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 24));
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $throwingRenderModel);
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);
        $reflection->getProperty('frames')->getValue($program)->markDirty();

        $ticker = new AdaptiveTicker(fn () => null);
        $reflection->getProperty('adaptiveTicker')->setValue($program, $ticker);

        $reflection->getMethod('runTick')->invoke($program);
        $ticker->stop();

        self::assertTrue(
            $reflection->getProperty('frames')->getValue($program)->isDirty(),
            'A failed render must leave the frame dirty so the next tick retries instead of freezing a blank frame'
        );
    }

    #[Test]
    public function runTickStopsTickerWhenFullyIdle(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 24));
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);
        $reflection->getProperty('frames')->getValue($program)->drawn(microtime(true), null);

        $ticker = new AdaptiveTicker(fn () => null);
        $reflection->getProperty('adaptiveTicker')->setValue($program, $ticker);

        $reflection->getMethod('runTick')->invoke($program);

        $callbackId = (new ReflectionProperty($ticker, 'callbackId'))->getValue($ticker);
        $ticker->stop();

        self::assertNull($callbackId, 'With nothing dirty, queued, scheduled or running, the ticker must stop — an idle app must not busy-poll');
    }

    /**
     * @return iterable<string, array{Closure(Program): Program}>
     */
    public static function configurationBuilderProvider(): iterable
    {
        yield 'altScreen' => [fn (Program $program) => $program->withAltScreen()];
        yield 'mouseTracking' => [fn (Program $program) => $program->withMouseTracking()];
        yield 'fps' => [fn (Program $program) => $program->withFps(30.0)];
        yield 'onError' => [fn (Program $program) => $program->onError(fn () => null)];
        yield 'withTerminal' => [fn (Program $program) => $program->withTerminal(new VirtualTerminal(80, 24))];
        yield 'withTheme' => [fn (Program $program) => $program->withTheme(Theme::dark())];
        yield 'withClock' => [fn (Program $program) => $program->withClock(new TestClock())];
    }

    /**
     * @param Closure(Program): Program $configure
     */
    #[DataProvider('configurationBuilderProvider')]
    #[Test]
    public function buildersRejectConfigurationAfterStart(Closure $configure): void
    {
        $program = Program::new($this->createMockModel());
        (new ReflectionProperty(Program::class, 'state'))->setValue($program, ProgramState::Running);

        $this->expectException(LogicException::class);
        $configure($program);
    }

    #[Test]
    public function runTickKeepsTickerScheduledWhileProcessesActive(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 24));
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);
        $reflection->getProperty('frames')->getValue($program)->drawn(microtime(true), null);

        $reflection->getMethod('dispatchCommand')->invoke($program, Cmd::process(['sleep', '30']));

        $ticker = new AdaptiveTicker(fn () => null);
        $reflection->getProperty('adaptiveTicker')->setValue($program, $ticker);

        $reflection->getMethod('runTick')->invoke($program);

        $callbackId = (new ReflectionProperty($ticker, 'callbackId'))->getValue($ticker);
        $interval = (new ReflectionProperty($ticker, 'currentInterval'))->getValue($ticker);
        $ticker->stop();

        self::assertNotNull($callbackId, 'An active process must keep the ticker scheduled so it gets pumped');
        self::assertSame(0.01, $interval, 'Active processes must tick at the active interval');
    }

    #[Test]
    public function renderFrameWritesContentToTerminal(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $rendered = false;
        $model = new class($rendered) implements Model {
            public function __construct(public bool &$rendered)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                $this->rendered = true;
                $frame->putString(0, 0, 'HELLO');
            }
        };

        $program = Program::new($model);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 24));
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $model);
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('renderFrame')->invoke($program);

        self::assertTrue($model->rendered, 'renderFrame must call model->render()');
        self::assertStringContainsString('HELLO', $terminal->output(), 'renderFrame must flush buffer content to the terminal');
    }

    #[Test]
    public function renderFrameReallocatesBackBufferWhenOnlyWidthChanges(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(100, 24));
        $oldBuffer = new Frame(80, 24);
        $reflection->getProperty('backBuffer')->setValue($program, $oldBuffer);
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('renderFrame')->invoke($program);

        self::assertNotSame($oldBuffer, $reflection->getProperty('backBuffer')->getValue($program), 'Width-only change must trigger reallocation (the || in width/height check)');
    }

    #[Test]
    public function renderFrameClearsBackBufferBetweenFrames(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $sequenceModel = new class implements Model {
            public int $renderCount = 0;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                ++$this->renderCount;
                if ($this->renderCount === 1) {
                    $frame->putString(0, 0, 'FIRST');
                }
                // 2nd render writes nothing — should produce blank, not leftover 'FIRST'
            }
        };

        $program = Program::new($sequenceModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 24));
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $sequenceModel);
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('renderFrame')->invoke($program);
        $reflection->getMethod('renderFrame')->invoke($program);

        $frame = $reflection->getProperty('backBuffer')->getValue($program);
        self::assertSame(' ', $frame->characters[0], 'Buffer must be cleared between frames so stale content from previous render doesn’t persist');
    }

    #[Test]
    public function renderFrameDoesNotStartBlankFrameWithAllRowsDirty(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        $model = new class implements Model {
            /**
             * @var list<list<int>>
             */
            public array $dirtyRowsAtRenderStart = [];

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                $this->dirtyRowsAtRenderStart[] = array_keys($frame->dirtyRows);
            }
        };

        $program = Program::new($model);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(20, 5));
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(20, 5));
        $reflection->getProperty('model')->setValue($program, $model);
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('renderFrame')->invoke($program);

        self::assertSame([], $model->dirtyRowsAtRenderStart[0], 'A blank buffer reset should not mark every row dirty before model rendering starts');
    }

    #[Test]
    public function renderFramePreRenderClearMarksOnlyRowsThatErasePreviousContent(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        $model = new class implements Model {
            public int $renderCount = 0;
            /**
             * @var list<list<int>>
             */
            public array $dirtyRowsAtRenderStart = [];

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                $this->dirtyRowsAtRenderStart[] = array_keys($frame->dirtyRows);
                ++$this->renderCount;

                if ($this->renderCount === 1) {
                    $frame->putString(0, 0, 'FIRST');
                    $frame->putString(0, 4, 'LAST');
                }
            }
        };

        $program = Program::new($model);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(20, 5));
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(20, 5));
        $reflection->getProperty('model')->setValue($program, $model);
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('renderFrame')->invoke($program);
        $reflection->getMethod('renderFrame')->invoke($program);

        self::assertSame([], $model->dirtyRowsAtRenderStart[0]);
        self::assertSame([0, 4], $model->dirtyRowsAtRenderStart[1], 'Only rows that contained stale content should be dirty after the frame reset');
    }

    #[Test]
    public function renderFrameReturnsEarlyAfterRenderErrorWithoutFlushingPartialBuffer(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $partialThrowingModel = new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                $frame->putString(0, 0, 'PARTIAL-CONTENT');
                throw new RuntimeException('boom mid-render');
            }
        };

        $program = Program::new($partialThrowingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 24));
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $partialThrowingModel);
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('renderFrame')->invoke($program);

        self::assertStringNotContainsString('PARTIAL-CONTENT', $terminal->output(), 'Render error must return before screen->flush() so half-rendered content is NOT emitted');
    }

    #[Test]
    public function renderFrameAllocatesNewBackBufferOnSizeMismatch(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(100, 30));
        $oldBuffer = new Frame(80, 24);
        $reflection->getProperty('backBuffer')->setValue($program, $oldBuffer);
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('renderFrame')->invoke($program);

        $newBuffer = $reflection->getProperty('backBuffer')->getValue($program);
        self::assertInstanceOf(Frame::class, $newBuffer);
        self::assertNotSame($oldBuffer, $newBuffer, 'Size mismatch must allocate a fresh backBuffer');
        self::assertSame(100, $newBuffer->width);
        self::assertSame(30, $newBuffer->height);
    }

    #[Test]
    public function renderFrameAppliesThemeBackgroundWhenNonZero(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);

        $coloredBg = Color::rgb(50, 50, 50)->value;
        $themeWithBg = Theme::dark()->withBase(
            new Style(background: Color::rgb(50, 50, 50)),
        );

        $frame = new Frame(80, 24);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 24));
        $reflection->getProperty('backBuffer')->setValue($program, $frame);
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new($themeWithBg));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('renderFrame')->invoke($program);

        self::assertSame($coloredBg, $frame->backgrounds[0], 'Theme.base background must fill the buffer when non-zero');
    }

    #[Test]
    public function renderFrameDispatchesErrorMsgWhenModelRenderThrows(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $throwingModel = new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                throw new RuntimeException('render boom');
            }
        };

        $program = Program::new($throwingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 24));
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $throwingModel);
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $queue = new SplQueue();
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('renderFrame')->invoke($program);

        self::assertFalse($queue->isEmpty(), 'Render throw must enqueue an ErrorMsg');
        self::assertInstanceOf(ErrorMsg::class, $queue->dequeue());
    }

    #[Test]
    public function aRenderThatKeepsThrowingStopsTheProgramInsteadOfSpinning(): void
    {
        $alwaysThrowingModel = new class implements Model {
            public int $renderAttempts = 0;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                ++$this->renderAttempts;

                throw new RuntimeException('render boom');
            }
        };

        $program = $this->createRenderablePrgram($alwaysThrowingModel);
        $reflection = new ReflectionClass($program);
        $clock = new TestClock(1.0);
        $reflection->getProperty('clock')->setValue($program, $clock);
        $ticker = new AdaptiveTicker(fn () => null);
        $reflection->getProperty('adaptiveTicker')->setValue($program, $ticker);
        $runTick = $reflection->getMethod('runTick');
        for ($tick = 0; $tick < 100 && $program->state() === ProgramState::Running; ++$tick) {
            $clock->advance(1.0);
            $runTick->invoke($program);
        }
        $ticker->stop();

        self::assertNotSame(ProgramState::Running, $program->state(), 'A render that fails every frame must end the program, not repaint nothing forever');
        self::assertLessThanOrEqual(10, $alwaysThrowingModel->renderAttempts, 'The consecutive-failure threshold bounds the retries');
    }

    #[Test]
    public function aStalledTerminalWriteReachesTheModelAsAnError(): void
    {
        $stalledOutput = new class implements TerminalOutput {
            public function write(string $data): void
            {
                throw new TerminalWriteException(0, strlen($data));
            }

            public function flush(): void
            {
            }
        };

        $errorObservingModel = new class implements Model {
            /**
             * @var list<ErrorSource>
             */
            public array $errorSources = [];

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof ErrorMsg) {
                    $this->errorSources[] = $message->source;
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                $frame->putString(0, 0, 'CONTENT');
            }
        };

        $program = $this->createRenderablePrgram($errorObservingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('screen')->setValue($program, new Screen($stalledOutput));
        $clock = new TestClock(1.0);
        $reflection->getProperty('clock')->setValue($program, $clock);
        $ticker = new AdaptiveTicker(fn () => null);
        $reflection->getProperty('adaptiveTicker')->setValue($program, $ticker);
        $runTick = $reflection->getMethod('runTick');

        $runTick->invoke($program);
        $clock->advance(1.0);
        $runTick->invoke($program);
        $ticker->stop();

        self::assertSame([ErrorSource::Render], $errorObservingModel->errorSources, 'A write that stalls must become an ErrorMsg the app can act on, not unwind the loop');
    }

    #[Test]
    public function aTerminalThatStallsForManyFramesDoesNotEndTheProgram(): void
    {
        $stalledOutput = new class implements TerminalOutput {
            public function write(string $data): void
            {
                throw new TerminalWriteException(0, strlen($data), stalled: true);
            }

            public function flush(): void
            {
            }
        };
        $model = new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                $frame->putString(0, 0, 'CONTENT');
            }
        };

        $program = $this->createRenderablePrgram($model);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('screen')->setValue($program, new Screen($stalledOutput));
        $clock = new TestClock(1.0);
        $reflection->getProperty('clock')->setValue($program, $clock);
        $ticker = new AdaptiveTicker(fn () => null);
        $reflection->getProperty('adaptiveTicker')->setValue($program, $ticker);
        $runTick = $reflection->getMethod('runTick');

        for ($tick = 0; $tick < 20; ++$tick) {
            $clock->advance(1.0);
            $runTick->invoke($program);
        }
        $ticker->stop();

        self::assertSame(ProgramState::Running, $program->state());
    }

    #[Test]
    public function resolveThemeReturnsLightForLightColorScheme(): void
    {
        $lightTerminal = new class implements Terminal {
            public function enableRawMode(): void
            {
            }

            public function disableRawMode(): void
            {
            }

            public function enterAltScreen(): void
            {
            }

            public function leaveAltScreen(): void
            {
            }

            public function hideCursor(): void
            {
            }

            public function showCursor(): void
            {
            }

            public function enableMouseTracking(): void
            {
            }

            public function disableMouseTracking(): void
            {
            }

            public function enableBracketedPaste(): void
            {
            }

            public function disableBracketedPaste(): void
            {
            }

            public function queryKittyProtocol(): void
            {
            }

            public function enableKittyProtocol(): void
            {
            }

            public function disableKittyProtocol(): void
            {
            }

            public function isKittyEnabled(): bool
            {
                return false;
            }

            public function bell(): void
            {
            }

            public function setTitle(string $title): void
            {
            }

            public function size(): array
            {
                return [80, 24];
            }

            public function colorProfile(): ColorProfile
            {
                return ColorProfile::TrueColor;
            }

            public function detectColorScheme(): ?ColorScheme
            {
                return ColorScheme::Light;
            }

            public function readInput(): string
            {
                return '';
            }

            public function write(string $data): void
            {
            }

            public function flush(): void
            {
            }

            public function inputStream(): mixed
            {
                return null;
            }
        };

        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $lightTerminal);

        $theme = $reflection->getMethod('resolveTheme')->invoke($program);

        self::assertEquals(Theme::light(), $theme, 'Light color scheme must resolve to the light theme');
    }

    #[Test]
    public function tickIsCallablePublicly(): void
    {
        $tickableProgram = $this->buildTickableProgram();
        // Direct public-method invocation — would fail with VisibilityError if mutated to protected/private
        $tickableProgram->tick();

        self::assertTrue(true, 'tick() must be invokable as a public method from outside the class');
    }

    #[Test]
    public function reentrantTickDoesNotInterleaveMessageProcessing(): void
    {
        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);

        $orderingModel = new class($program) implements Model {
            /**
             * @var list<string>
             */
            public array $order = [];
            private bool $reentered = false;

            public function __construct(private readonly Program $program)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                $tag = is_object($message) ? $message::class : (string)$message;
                $this->order[] = "start:$tag";
                if (!$this->reentered) {
                    $this->reentered = true;
                    $this->program->tick();
                }
                $this->order[] = "end:$tag";

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
        $reflection->getProperty('model')->setValue($program, $orderingModel);

        $queue = $reflection->getProperty('messageQueue')->getValue($program);
        $queue->enqueue(new TickMsg(microtime(true)));
        $queue->enqueue(new TickMsg(microtime(true)));

        $program->tick();

        $expectedGuarded = [
            'start:' . TickMsg::class,
            'end:' . TickMsg::class,
            'start:' . TickMsg::class,
            'end:' . TickMsg::class,
        ];
        self::assertSame($expectedGuarded, $orderingModel->order, 'With the re-entrancy guard, both TickMsgs are drained by the outer tick sequentially. Without the guard (ticking=false during runTick), the inner re-entrant tick would drain msg#2 before msg#1’s update returns — start/end would interleave (start,start,end,end).');
    }

    #[Test]
    public function tickReturnsImmediatelyWhenAlreadyTicking(): void
    {
        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('ticking')->setValue($program, true);

        $queue = $reflection->getProperty('messageQueue')->getValue($program);
        $queue->enqueue(new TickMsg(microtime(true)));

        $program->tick();

        self::assertFalse($queue->isEmpty(), 'When ticking flag is set (outer tick in progress), a re-entrant tick must return before draining the queue');
        self::assertTrue($reflection->getProperty('ticking')->getValue($program), 'ticking flag must remain unchanged — still owned by the outer tick');
    }

    #[Test]
    public function tickFiresTickersAndDeliversToModel(): void
    {
        $observingModel = new class implements Model {
            public int $tickCount = 0;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof TickMsg) {
                    ++$this->tickCount;
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);

        $clock = new TestClock(1_000.0);
        $reflection->getProperty('clock')->setValue($program, $clock);
        $reflection->getMethod('dispatchCommand')->invoke($program, Cmd::tick(0.1));

        $clock->advance(0.1);
        $program->tick();

        self::assertGreaterThan(0, $observingModel->tickCount, 'a ticker that is due must reach the model as a TickMsg');
    }

    #[Test]
    public function tickFiresTimersAndDispatchesResult(): void
    {
        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);

        $timerCalled = false;
        $reflection->getMethod('dispatchCommand')->invoke($program, Cmd::after(0.0, function () use (&$timerCalled) {
            $timerCalled = true;

            return null;
        }));

        $program->tick();

        self::assertTrue($timerCalled, 'a timer that is due must have its callback run');
    }

    #[Test]
    public function tickPumpsProcessesAndDeliversMessagesToModel(): void
    {
        $observingModel = new class implements Model {
            public mixed $receivedMessage = null;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                $this->receivedMessage = $message;

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);

        $reflection->getMethod('dispatchCommand')->invoke($program, Cmd::exec(['echo', 'from-process']));

        for ($attempt = 0; $attempt < 400 && !$observingModel->receivedMessage instanceof ProcessResultMsg; ++$attempt) {
            $program->tick();
            usleep(5_000);
        }

        self::assertInstanceOf(
            ProcessResultMsg::class,
            $observingModel->receivedMessage,
            'what a process printed must reach the model',
        );
        self::assertStringContainsString('from-process', $observingModel->receivedMessage->stdout);
    }

    #[Test]
    public function tickReturnsEarlyOnQuitMsgWithoutRendering(): void
    {
        $renderCount = 0;
        $countingModel = new class($renderCount) implements Model {
            public function __construct(public int &$renderCount)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                ++$this->renderCount;
            }
        };

        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);

        $queue = $reflection->getProperty('messageQueue')->getValue($program);
        $queue->enqueue(QuitMsg::Quit);

        $reflection->getProperty('frames')->getValue($program)->markDirty();

        $program->tick();

        self::assertNotSame(ProgramState::Running, $program->state(), 'tick must propagate QuitMsg → running=false');
        self::assertSame(0, $renderCount, 'After QuitMsg the early return must skip renderFrame, even when isDirty=true and budget met');
    }

    #[Test]
    public function tickRendersAndClearsDirtyFlagWhenBudgetMet(): void
    {
        $renderCount = 0;
        $countingModel = new class($renderCount) implements Model {
            public function __construct(public int &$renderCount)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                ++$this->renderCount;
            }
        };

        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);
        $reflection->getProperty('frames')->getValue($program)->markDirty();

        $program->tick();

        self::assertSame(1, $renderCount, 'tick must call renderFrame → model.render exactly once when dirty and budget met');
        self::assertFalse($reflection->getProperty('frames')->getValue($program)->isDirty(), 'After render, isDirty must clear to false');
    }

    #[Test]
    public function tickDoesNotRenderWhenFrameBudgetIsNotMet(): void
    {
        $renderCount = 0;
        $countingModel = new class($renderCount) implements Model {
            public function __construct(public int &$renderCount)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                ++$this->renderCount;
            }
        };

        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);
        $reflection->getProperty('frames')->getValue($program)->drawn($reflection->getProperty('clock')->getValue($program)->now() + 10.0, null);
        $reflection->getProperty('frames')->getValue($program)->markDirty();

        $program->tick();

        self::assertSame(0, $renderCount, 'tick must NOT render when fresh lastRender means frame budget (1/fps) is not yet elapsed');
        self::assertTrue($reflection->getProperty('frames')->getValue($program)->isDirty(), 'isDirty must stay true when render is skipped');
    }

    #[Test]
    public function tickFrameBudgetUsesPositiveOneDividedByFpsNotZero(): void
    {
        $renderCount = 0;
        $countingModel = new class($renderCount) implements Model {
            public function __construct(public int &$renderCount)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                ++$this->renderCount;
            }
        };

        $program = $this->buildTickableProgram(framesPerSecond: 1.0);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);
        $reflection->getProperty('frames')->getValue($program)->drawn($reflection->getProperty('clock')->getValue($program)->now(), null);
        $reflection->getProperty('frames')->getValue($program)->markDirty();

        $program->tick();

        // Original: budget=1.0s. now ≈ lastRender (microsec later). now < lastRender+1.0 → no render.
        // OneZeroFloat mutant: budget=0.0. now >= lastRender (true after any time passes) → render.
        // Plus mutant: now >= lastRender - 1.0 (always true) → render.
        self::assertSame(0, $renderCount, '1.0/fps must produce a real budget; mutations to 0.0 or to subtraction would cause render every tick');
    }

    #[Test]
    public function tickRendersAtExactFrameBudgetBoundary(): void
    {
        $renderCount = 0;
        $countingModel = new class($renderCount) implements Model {
            public function __construct(public int &$renderCount)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
                ++$this->renderCount;
            }
        };

        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);
        $reflection->getProperty('frames')->getValue($program)->drawn($reflection->getProperty('clock')->getValue($program)->now() - 1.0, null);
        $reflection->getProperty('frames')->getValue($program)->markDirty();

        $program->tick();

        self::assertSame(1, $renderCount, 'tick must render when frame budget has elapsed; `>` mutant would require strictly-after lastRender+budget');
    }

    #[Test]
    public function tickHandlesPendingResizeAtStartOfTick(): void
    {
        $resizeReceived = false;
        $observingModel = new class($resizeReceived) implements Model {
            public function __construct(public bool &$resizeReceived)
            {
            }

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof ResizeMsg) {
                    $this->resizeReceived = true;
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);
        $reflection->getProperty('resizePending')->setValue($program, true);

        $program->tick();

        self::assertTrue($resizeReceived, 'handlePendingResize at top of tick must enqueue a ResizeMsg consumed by the model');
    }

    #[Test]
    public function tickProcessesQueuedMessagesEnqueuedDuringFirstDrain(): void
    {
        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);

        $enqueuingModel = new class implements Model {
            public bool $seenSecond = false;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof TickMsg && !$this->seenSecond) {
                    return new UpdateResult($this, Cmd::message(new TestMessage('second-pass-message')));
                }
                if ($message instanceof TestMessage && $message->value === 'second-pass-message') {
                    $this->seenSecond = true;
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $reflection->getProperty('model')->setValue($program, $enqueuingModel);

        $queue = $reflection->getProperty('messageQueue')->getValue($program);
        $queue->enqueue(new TickMsg(microtime(true)));

        $program->tick();

        self::assertTrue($enqueuingModel->seenSecond, 'tick must drain queue twice so messages enqueued during the first drain are processed in the same tick');
    }

    #[Test]
    public function handlePendingResizeClearsPendingFlagSetsScreenClearAndMarksDirty(): void
    {
        $terminal = new VirtualTerminal(120, 40);
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('resizePending')->setValue($program, true);
        $reflection->getProperty('resizeNeedsScreenClear')->setValue($program, false);
        $reflection->getProperty('frames')->getValue($program)->drawn(0.0, null);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('handlePendingResize')->invoke($program);

        self::assertFalse($reflection->getProperty('resizePending')->getValue($program), 'resizePending must clear to false after handling');
        self::assertTrue($reflection->getProperty('resizeNeedsScreenClear')->getValue($program), 'resizeNeedsScreenClear must be set so the next renderFrame clears the screen');
        self::assertTrue($reflection->getProperty('frames')->getValue($program)->isDirty(), 'Resize must mark renderer dirty');
    }

    #[Test]
    public function renderFrameClearsScreenWhenResizeRequestsIt(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $screen = new Screen($terminal);
        $reflection->getProperty('screen')->setValue($program, $screen);
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 24));
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        // First render to establish a stable front buffer in the Screen
        $reflection->getMethod('renderFrame')->invoke($program);
        $terminal->clearOutput();

        $reflection->getProperty('resizeNeedsScreenClear')->setValue($program, true);
        $reflection->getMethod('renderFrame')->invoke($program);

        self::assertFalse($reflection->getProperty('resizeNeedsScreenClear')->getValue($program), 'Flag must clear to false after consuming it (FalseValue mutant flips it to true → would re-clear forever)');
        self::assertStringContainsString(Ansi::CURSOR_HOME, $terminal->output(), 'a resize redraws the unchanged content whole, which a diff would not');
    }

    #[Test]
    public function renderFrameSkipsBackgroundFillWhenThemeBaseHasNoBackground(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $frame = new Frame(80, 24);
        $frame->backgrounds[5] = 12345; // pre-existing background that should NOT be wiped

        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 24));
        $reflection->getProperty('backBuffer')->setValue($program, $frame);
        $reflection->getProperty('model')->setValue($program, new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        });
        $themeNoBackground = Theme::dark()->withBase(
            new Style(foreground: Color::rgb(200, 200, 200)),
        );
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new($themeNoBackground));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('renderFrame')->invoke($program);

        self::assertSame(0, $frame->backgrounds[5], 'After frame reset with no theme background, cells must be background 0 (not filled with anything)');
    }

    #[Test]
    public function teardownStopsAllProcesses(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('dispatchCommand')->invoke($program, Cmd::process(['sleep', '30']));
        $effects = $reflection->getProperty('effects')->getValue($program);
        self::assertInstanceOf(EffectRunner::class, $effects);
        self::assertTrue($effects->hasRunningProcesses(), 'Precondition: something is running');

        $reflection->getMethod('teardown')->invoke($program);

        self::assertFalse($effects->hasRunningProcesses(), 'nothing may outlive the program that started it');
    }

    #[Test]
    public function setupPropagatesTheDetectedTerminalHeightToTheSession(): void
    {
        $terminal = new VirtualTerminal(80, 30);
        $program = Program::new($this->createMockModel());

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));

        $reflection->getMethod('setup')->invoke($program);
        $session = $reflection->getProperty('terminalSession')->getValue($program);
        $session->close();
        $reflection->getMethod('cancelEventLoopWatchers')->invoke($program);

        self::assertStringContainsString(
            Ansi::cursorToRowStart(29) . "\n",
            $terminal->output(),
            'setup() must propagate the detected height to the session so a close before teardown parks the cursor on the last row of the 30-row terminal',
        );
    }

    #[Test]
    public function resumePropagatesTheReReadTerminalHeightToTheSession(): void
    {
        $terminal = new VirtualTerminal(80, 40);
        $program = Program::new($this->createMockModel());
        $session = new TerminalSession($terminal, false, false);
        $session->start();
        $session->suspend();

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('terminalSession')->setValue($program, $session);

        $reflection->getMethod('resume')->invoke($program);
        $reflection->getMethod('cancelEventLoopWatchers')->invoke($program);
        $session->close();

        self::assertStringContainsString(
            Ansi::cursorToRowStart(39) . "\n",
            $terminal->output(),
            'resume() must push the re-read terminal height into the session so the final cursor lands on the last row of the 40-row terminal',
        );
    }

    #[Test]
    public function teardownPushesTheCurrentHeightIntoTheSessionBeforeClosing(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $session = new TerminalSession($terminal, false, false);
        $session->start();

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminalSession')->setValue($program, $session);
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 40));

        $reflection->getMethod('teardown')->invoke($program);

        self::assertStringContainsString(
            Ansi::cursorToRowStart(39) . "\n",
            $terminal->output(),
            'teardown() must hand the latest height to the session so the shell prompt continues below the UI, not inside it',
        );
    }

    #[Test]
    public function handlePendingResizePushesTheNewHeightIntoTheSession(): void
    {
        $terminal = new VirtualTerminal(80, 35);
        $program = Program::new($this->createMockModel());
        $session = new TerminalSession($terminal, false, false);
        $session->start();

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('terminalSession')->setValue($program, $session);
        $reflection->getProperty('resizePending')->setValue($program, true);

        $reflection->getMethod('handlePendingResize')->invoke($program);
        $session->close();

        self::assertStringContainsString(
            Ansi::cursorToRowStart(34) . "\n",
            $terminal->output(),
            'A resize must propagate the new height to the session so the final cursor row matches the resized terminal',
        );
    }

    #[Test]
    public function secondTeardownLeavesTheSessionHeightUntouched(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $session = new TerminalSession($terminal, false, false);
        $session->start();

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminalSession')->setValue($program, $session);

        $teardown = $reflection->getMethod('teardown');
        $teardown->invoke($program);

        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 99));
        $teardown->invoke($program);

        $sessionHeight = (new ReflectionProperty(TerminalSession::class, 'height'))->getValue($session);
        self::assertSame(24, $sessionHeight, 'teardown() must return before its finally block once already stopped — a second call must not push a new height into the closed session');
    }

    #[Test]
    public function teardownMarksTheProgramAsNotRunning(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $reflection->getMethod('teardown')->invoke($program);

        self::assertNotSame(ProgramState::Running, $program->state(), 'teardown() must flip running to false so the loop cannot continue after cleanup');
    }

    #[Test]
    public function teardownRestoresTheTerminalEvenWhenCleanupThrows(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $session = new TerminalSession($terminal, false, false);
        $session->start();

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminalSession')->setValue($program, $session);
        Closure::bind(function (): void {
            unset($this->effects);
        }, $program, Program::class)();

        try {
            $reflection->getMethod('teardown')->invoke($program);
            self::fail('Teardown was expected to throw when its process-cleanup loop hits the uninitialized property');
        } catch (Error) {
        }

        $output = $terminal->output();
        self::assertStringContainsString(Ansi::RESET_STYLE, $output, 'The finally block must still close the session when watcher/process cleanup throws');
        self::assertStringContainsString(Ansi::SHOW_CURSOR, $output, 'The cursor must be restored even when cleanup throws');
    }

    #[Test]
    public function drainMessageQueueDoesNotDeliverTheQuitMsgToTheModel(): void
    {
        $countingModel = new class implements Model {
            public int $updates = 0;

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                ++$this->updates;

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($countingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $queue = new SplQueue();
        $queue->enqueue(QuitMsg::Quit);
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertNotSame(ProgramState::Running, $program->state(), 'QuitMsg must stop the program');
        self::assertSame(0, $countingModel->updates, 'QuitMsg is a runtime control message — the drain must return immediately instead of falling through to model->update()');
    }

    #[Test]
    public function drainMessageQueueDoesNotEnqueueAnErrorForTheFailureThatTripsTheThreshold(): void
    {
        $throwingModel = new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                throw new RuntimeException('update boom');
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $program = Program::new($throwingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $throwingModel);
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);

        $queue = new SplQueue();
        for ($messageIndex = 0; $messageIndex < 10; ++$messageIndex) {
            $queue->enqueue(new TickMsg(0.0));
        }
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertNotSame(ProgramState::Running, $program->state(), 'Ten consecutive failures must trip the circuit breaker');
        self::assertSame(9, $queue->count(), 'Failures 1-9 each enqueue an ErrorMsg; the tenth trips the breaker and must return WITHOUT enqueueing another ErrorMsg');
    }

    #[Test]
    public function kittyResponseSequenceIsIgnoredWhenNoTerminalSessionIsActive(): void
    {
        $quitModel = new class implements Model {
            public function init(): ?Cmd
            {
                return Cmd::quit();
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
        $program = Program::new($quitModel)->withTerminal(new VirtualTerminal(80, 24));
        $program->start();

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminalSession')->setValue($program, null);
        $queue = new SplQueue();
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $stdinBuffer = $reflection->getProperty('stdinBuffer')->getValue($program);
        $stdinBuffer->process("\x1b[?1u");

        self::assertTrue($queue->isEmpty(), 'A kitty protocol response arriving without an active terminal session must be swallowed, not crash on a null session');
    }

    #[Test]
    public function renderFrameRejectsAMissingBackBufferEvenWhenTheRenderContextExists(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('backBuffer')->setValue($program, null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('renderer is not initialized');

        $reflection->getMethod('renderFrame')->invoke($program);
    }

    #[Test]
    public function callsOnShutdownOnTheFinalModelWhenTheProgramStops(): void
    {
        $generations = new ArrayObject();
        $model = new class($generations) implements Model, ShutdownAware {
            public function __construct(private ArrayObject $generations, private readonly int $generation = 0)
            {
            }

            public function init(): ?Cmd
            {
                return Cmd::message(new TestMessage('go'));
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return $message instanceof TestMessage
                    ? new UpdateResult(new self($this->generations, $this->generation + 1), Cmd::quit())
                    : new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }

            public function onShutdown(): void
            {
                $this->generations[] = $this->generation;
            }
        };

        Program::new($model)->withTerminal(new VirtualTerminal(80, 24))->start();

        self::assertSame([1], $generations->getArrayCopy(), 'The model that survived the loop gets the last word, once');
    }

    #[Test]
    public function callsOnShutdownEvenWhenTheLoopNeverRuns(): void
    {
        $calls = new ArrayObject();
        $model = new class($calls) implements Model, ShutdownAware {
            public function __construct(private ArrayObject $calls)
            {
            }

            public function init(): ?Cmd
            {
                return Cmd::quit();
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }

            public function onShutdown(): void
            {
                $this->calls[] = 'shutdown';
            }
        };

        Program::new($model)->withTerminal(new VirtualTerminal(80, 24))->start();

        self::assertSame(['shutdown'], $calls->getArrayCopy());
    }

    #[Test]
    public function restoresTheTerminalWhenOnShutdownThrows(): void
    {
        $model = new class implements Model, ShutdownAware {
            public function init(): ?Cmd
            {
                return Cmd::quit();
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }

            public function onShutdown(): void
            {
                throw new RuntimeException('flush failed');
            }
        };
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($model)->withTerminal($terminal);

        try {
            $program->start();
            self::fail('Expected the shutdown hook to surface its failure');
        } catch (RuntimeException $error) {
            self::assertSame('flush failed', $error->getMessage());
        }

        self::assertSame(ProgramState::Stopped, $program->state());
        self::assertFalse($terminal->isRawModeEnabled());
        self::assertTrue($terminal->isCursorVisible());
    }

    #[Test]
    public function skipsOnShutdownWhenStartupFails(): void
    {
        $calls = new ArrayObject();
        $model = new class($calls) implements Model, ShutdownAware {
            public function __construct(private ArrayObject $calls)
            {
            }

            public function init(): ?Cmd
            {
                throw new RuntimeException('init failed');
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }

            public function onShutdown(): void
            {
                $this->calls[] = 'shutdown';
            }
        };

        try {
            Program::new($model)->withTerminal(new VirtualTerminal(80, 24))->start();
        } catch (RuntimeException) {
        }

        self::assertSame([], $calls->getArrayCopy(), 'A model that never ran has no state worth keeping');
    }

    #[Test]
    public function aClosedTerminalReachesTheShutdownPath(): void
    {
        $source = (string)file_get_contents((string)(new ReflectionClass(Program::class))->getFileName());

        self::assertStringContainsString(
            'SIGHUP',
            $source,
            'Closing the terminal window must quit through QuitMsg, not kill the process before it can shut down',
        );
        self::assertStringContainsString(
            'EventLoopWatcher::Hangup',
            $source,
            'The SIGHUP watcher must be held like the others, or teardown cannot cancel it',
        );
    }

    /**
     * Cancels its own subscription and closes the stream on the first message,
     * which is the only order that is safe: a watcher left on a closed stream
     * takes the driver down.
     *
     * @param resource $read
     * @param resource $write
     */
    private function closingStreamModel(mixed $read, mixed $write, EffectId $id): Model
    {
        return new class($read, $write, $id) implements Model {
            /** @param resource $read
             *  @param resource $write */
            public function __construct(
                private readonly mixed $read,
                private readonly mixed $write,
                private readonly EffectId $id,
            ) {
            }

            public function init(): ?Cmd
            {
                return Cmd::batch(
                    Cmd::listen($this->read, static fn (mixed $stream): iterable => [new TestMessage((string)fread($stream, 64))], $this->id),
                    Cmd::after(0.3, static fn (): Cmd => Cmd::quit()),
                );
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if (!$message instanceof TestMessage) {
                    return new UpdateResult($this);
                }

                return new UpdateResult($this, Cmd::batch(
                    Cmd::cancel($this->id),
                    Cmd::call(function (): ?Cmd {
                        fclose($this->write);
                        fclose($this->read);

                        return Cmd::quit();
                    }),
                ));
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
    }

    /**
     * @param resource $stream
     */
    private function countingStreamModel(mixed $stream, ArrayObject $reads): Model
    {
        return new class($stream, $reads) implements Model {
            /**
             * @param resource $stream
             */
            public function __construct(private readonly mixed $stream, private readonly ArrayObject $reads)
            {
            }

            public function init(): ?Cmd
            {
                $reads = $this->reads;

                return Cmd::batch(
                    Cmd::listen($this->stream, static function (mixed $stream) use ($reads): iterable {
                        $reads['count'] = $reads['count'] + 1;
                        fread($stream, 1024);

                        return [];
                    }),
                    Cmd::after(0.1, static fn (): Cmd => Cmd::quit()),
                );
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
    }

    /**
     * Collects what the subscription yields and quits on a deadline, so a
     * subscription that never fires fails an assertion instead of hanging.
     * With an id, it cancels the subscription after the first message.
     *
     * @param resource $stream
     */
    private function streamModel(mixed $stream, ?EffectId $id = null): Model
    {
        return new class($stream, $id) implements Model {
            /**
             * @var list<string>
             */
            public array $received = [];

            /**
             * @param resource $stream
             */
            public function __construct(private readonly mixed $stream, private readonly ?EffectId $id = null)
            {
            }

            public function init(): ?Cmd
            {
                return Cmd::batch(
                    Cmd::listen($this->stream, static function (mixed $stream): iterable {
                        $chunk = fread($stream, 1024);
                        if ($chunk === false || $chunk === '') {
                            return [];
                        }

                        return [new TestMessage($chunk)];
                    }, $this->id),
                    Cmd::after(0.25, static fn (): Cmd => Cmd::quit()),
                );
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if (!$message instanceof TestMessage) {
                    return new UpdateResult($this);
                }
                $next = clone $this;
                $next->received = [...$this->received, $message->value];

                return new UpdateResult($next, $this->id === null ? null : Cmd::cancel($this->id));
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
    }

    /**
     * @return array{resource, resource}
     */
    private static function streamPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        return [$pair[0], $pair[1]];
    }

    private function createRenderablePrgram(Model $model): Program
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($model);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 24));
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $model);
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);
        $reflection->getProperty('frames')->getValue($program)->markDirty();

        return $program;
    }

    private function buildTickableProgram(?Clock $clock = null, float $framesPerSecond = 30.0): Program
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel())->withFps($framesPerSecond);
        if ($clock !== null) {
            $program = $program->withClock($clock);
        }
        $reflection = new ReflectionClass($program);

        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));
        $reflection->getProperty('screenRect')->setValue($program, Rect::fromSize(80, 24));
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('renderPass')->setValue($program, RenderPass::new(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('state')->setValue($program, ProgramState::Running);
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('adaptiveTicker')->setValue($program, new AdaptiveTicker(fn () => null));

        return $program;
    }

    private function createHttpCapturingProgram(?array &$capturedOptions): Program
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, []);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('httpClient')->setValue(
            $program,
            new MockHttpClient(
                function (string $method, string $url, array $options) use (&$capturedOptions) {
                    $capturedOptions = $options;

                    return new MockResponse('ok');
                },
            ),
        );

        return $program;
    }

    private function createMockModel(): Model
    {
        return new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
    }
}

final readonly class UnknownTestCmd extends Cmd
{
}

final class InputLoggingModel implements Model
{
    /**
     * @var list<string>
     */
    public array $log = [];

    public int $renders = 0;

    public function __construct(
        private readonly ?TestClock $clock = null,
        private readonly bool $quitOnWheelUp = false,
        private readonly ?string $failOn = null,
        private readonly ?Cmd $init = null,
    ) {
    }

    public function init(): ?Cmd
    {
        return $this->init;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof ErrorMsg) {
            $this->log[] = 'error:' . $message->source->name;
        }
        if ($message instanceof MouseMsg) {
            $this->log[] = strtolower($message->action->name) . '×' . $message->repeat . ($this->clock === null ? '' : '@' . $this->clock->now());

            return new UpdateResult($this, $this->quitOnWheelUp && $message->action === MouseAction::WheelUp ? Cmd::quit() : null);
        }
        if (!$message instanceof KeyMsg) {
            return new UpdateResult($this);
        }
        $name = ($message->alt ? 'alt+' : '') . KeyPattern::nameOf($message->key);
        if ($name === $this->failOn) {
            throw new RuntimeException($name . ' failed');
        }
        $this->log[] = $name;

        return new UpdateResult($this, $name === 'q' ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        ++$this->renders;
    }
}

final class ScriptedRenderModel implements Model
{
    public int $attempts = 0;

    /**
     * @param non-empty-list<Throwable|null> $outcomes what each frame does in turn, null drawing it; the last one repeats
     */
    public function __construct(private readonly array $outcomes)
    {
    }

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this, $message instanceof KeyMsg && $message->key === 'q' ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        $outcome = $this->outcomes[min($this->attempts, count($this->outcomes) - 1)];
        ++$this->attempts;
        $pass->context->requestRepaint(0.0);
        if ($outcome !== null) {
            throw $outcome;
        }
    }
}

final readonly class SlowPaintModel implements Model
{
    public function __construct(private TestClock $clock, private float $paintSeconds)
    {
    }

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this, $message instanceof KeyMsg && $message->key === 'q' ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        $this->clock->advance($this->paintSeconds);
    }
}
