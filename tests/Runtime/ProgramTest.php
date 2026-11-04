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
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\AdaptiveTicker;
use Elmphant\Tui\Runtime\CommandHandler;
use Elmphant\Tui\Runtime\FullScreenSurface;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ProgramState;
use Elmphant\Tui\Runtime\ScreenMode;
use Elmphant\Tui\Runtime\ShutdownAware;
use Elmphant\Tui\Runtime\TerminalSession;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\ColorProfile;
use Elmphant\Tui\Terminal\ColorScheme;
use Elmphant\Tui\Terminal\Terminal;
use Elmphant\Tui\Terminal\TerminalWriteException;
use Elmphant\Tui\Testing\InMemoryTracer;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Support\PlaySoundCmd;
use Elmphant\Tui\Tests\Support\TestMessage;
use Error;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Revolt\EventLoop;
use RuntimeException;
use SplQueue;
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
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function aContinueSignalTheProgramDidNotCauseWatchesNoSignalTwice(): void
    {
        $signalWatchers = static fn (): int => count(array_filter(
            EventLoop::getIdentifiers(),
            static fn (string $id): bool => EventLoop::getType($id) === EventLoop\CallbackType::Signal,
        ));
        $counts = [];
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static function () use (&$counts, $signalWatchers): void {
            $counts[] = $signalWatchers();
            posix_kill(posix_getpid(), SIGCONT);
        });
        EventLoop::delay(0.15, static function (): void {
            posix_kill(posix_getpid(), SIGCONT);
        });
        EventLoop::delay(0.25, static function () use (&$counts, $signalWatchers, $terminal): void {
            $counts[] = $signalWatchers();
            $terminal->simulateInput('q');
        });

        Program::new(new InputLoggingModel())->withTerminal($terminal)->start();

        self::assertSame($counts[0], $counts[1]);
    }

    #[Test]
    public function aKeyAfterATerminalReplyThatNeverEndedArrives(): void
    {
        $model = new InputLoggingModel();
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput("\x1b]11;rgb:0000/0000/0000"));
        EventLoop::delay(0.4, static fn () => $terminal->simulateInput('q'));

        Program::new($model)->withTerminal($terminal)->start();

        self::assertSame(['q'], $model->log);
    }

    #[Test]
    public function anEscapeSequenceSplitAcrossReadsIsStillOneKey(): void
    {
        $model = new InputLoggingModel();
        $terminal = new VirtualTerminal(20, 5);
        $program = Program::new($model)->withTerminal($terminal);
        EventLoop::defer(static function () use ($terminal, $program): void {
            foreach (["\x1b", '[', 'A'] as $read) {
                $terminal->simulateInput($read);
                $program->tick();
            }
            $terminal->simulateInput('q');
        });

        $program->start();

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
        self::assertEqualsWithDelta(0.0, $render['diffMs'] ?? null, 0.0001, 'the clock stood still while the frame was compared');
    }

    #[Test]
    public function aTerminalThatCountsNothingReportsNoBytesAndNoWait(): void
    {
        $tracer = new InMemoryTracer();
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput('q'));

        Program::new(new SlowPaintModel(new TestClock(100.0), 0.0))->withTerminal($terminal)->withTracer($tracer)->start();

        $render = $tracer->fieldsOf(TraceEvent::Render)[0] ?? [];
        self::assertSame(0, $render['bytes'] ?? null);
        self::assertSame(0.0, $render['blockedMs'] ?? null);
    }

    #[Test]
    public function anErrorHandlerThatAnswersEveryErrorWithAnotherIsStoppedAtTheTenthInTheChain(): void
    {
        $model = new class implements Model {
            public function init(): ?Cmd
            {
                return Cmd::message(new ErrorMsg(new RuntimeException('first'), ErrorSource::Update));
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };
        $answered = 0;

        $program = Program::new($model)
            ->withTerminal(new VirtualTerminal(80, 24))
            ->onError(static function () use (&$answered): Cmd {
                ++$answered;

                return Cmd::message(new ErrorMsg(new RuntimeException('again'), ErrorSource::Update));
            });
        $thrown = null;
        try {
            $program->start();
        } catch (RuntimeException $error) {
            $thrown = $error;
        }

        self::assertSame('again', $thrown?->getMessage());
        self::assertSame(9, $answered);
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
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($model)->withTerminal($terminal);

        EventLoop::delay(0.02, static function () use ($write): void {
            fwrite($write, 'last word');
        });

        $program->start();

        self::assertFalse($terminal->isRawModeEnabled(), 'the program ended and gave the terminal back');
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
        self::assertInstanceOf(Program::class, $program->withFps(30.0), 'a program not started yet still takes configuration');
    }

    #[Test]
    public function startTransitionsRunningProgramToStoppedAndIsOneShot(): void
    {
        $model = new class implements Model {
            public ?Program $program = null;
            public ?bool $configurableDuringInit = null;

            public function init(): ?Cmd
            {
                try {
                    $this->program?->withFps(30.0);
                    $this->configurableDuringInit = true;
                } catch (LogicException) {
                    $this->configurableDuringInit = false;
                }

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
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($model)->withTerminal($terminal);
        $model->program = $program;

        self::assertSame($model, $program->start());
        self::assertFalse($model->configurableDuringInit, 'while it runs, the program refuses configuration');
        self::assertFalse($terminal->isRawModeEnabled(), 'and once it stopped, the terminal is given back');

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

        self::assertFalse($terminal->isRawModeEnabled());
        self::assertTrue($terminal->isCursorVisible());
    }

    #[Test]
    public function builderMethodsChainable(): void
    {
        $program = Program::new($this->createMockModel())
            ->withScreenMode(ScreenMode::alternate(mouse: true))
            ->withFps(30)
            ->onError(fn (ErrorMsg $error) => Cmd::quit());

        self::assertInstanceOf(Program::class, $program);
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
    public function suspensionRestoreDisablesAndResumeReEnablesBracketedPaste(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $session = new TerminalSession($terminal, false, false);
        $session->start();

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('surface')->setValue($program, new FullScreenSurface($terminal, new Screen($terminal), false));
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
        $reflection->getProperty('surface')->setValue($program, new FullScreenSurface($terminal, $screen, false));
        $reflection->getProperty('frames')->getValue($program)->drawn(0.0, null);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $queue = new SplQueue();
        $reflection->getProperty('inputQueue')->setValue($program, $queue);
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
    public function runTickStopsTickerWhenFullyIdle(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('surface')->setValue($program, new FullScreenSurface($terminal, new Screen($terminal), false));
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
        yield 'screenMode' => [fn (Program $program) => $program->withScreenMode(ScreenMode::alternate())];
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
        $reflection->getProperty('surface')->setValue($program, new FullScreenSurface($terminal, new Screen($terminal), false));
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
