<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use ArrayObject;
use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\ExecCmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\ProcessCmd;
use Elmphant\Tui\Core\RenderRevisioned;
use Elmphant\Tui\Core\ShutdownAware;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\ColorProfile;
use Elmphant\Tui\Input\ColorScheme;
use Elmphant\Tui\Input\StdinBuffer;
use Elmphant\Tui\Input\TerminalInterface;
use Elmphant\Tui\Input\TerminalOutputInterface;
use Elmphant\Tui\Input\TerminalWriteException;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\FloatMouseMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\QuitMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Ansi;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\AdaptiveTicker;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ProgramState;
use Elmphant\Tui\Runtime\RunningProcess;
use Elmphant\Tui\Runtime\TerminalSession;
use Elmphant\Tui\Runtime\TickerRecord;
use Elmphant\Tui\Runtime\TimerRecord;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Support\FloatingModel;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Tests\Support\TestPopup;
use Error;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionObject;
use ReflectionProperty;
use ReflectionUnionType;
use RuntimeException;
use SplQueue;

final class ProgramTest extends TestCase
{
    #[Test]
    public function runAndStartHaveANativeModelReturnContract(): void
    {
        self::assertSame(Model::class, (string)(new ReflectionMethod(Program::class, 'run'))->getReturnType());
        self::assertSame(Model::class, (string)(new ReflectionMethod(Program::class, 'start'))->getReturnType());
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

            public function render(Frame $frame, RenderContext $context): void
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };
        $program = Program::new($model)->withTerminal(new VirtualTerminal(80, 24));
        $program->start();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('state Stopped');
        $program->fps(30);
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

            public function render(Frame $frame, RenderContext $context): void
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
    public function builderMethodsReturnSelf(): void
    {
        $program = Program::new($this->createMockModel());
        self::assertSame($program, $program->altScreen());
        self::assertSame($program, $program->mouseTracking());
        self::assertSame($program, $program->fps(30));
        self::assertSame($program, $program->onError(fn () => Cmd::quit()));
    }

    #[Test]
    public function altScreenEnablesFlag(): void
    {
        $program = Program::new($this->createMockModel());
        $program->altScreen();

        $reflection = new ReflectionProperty(Program::class, 'useAltScreen');
        self::assertTrue($reflection->getValue($program));
    }

    #[Test]
    public function mouseTrackingEnablesFlag(): void
    {
        $program = Program::new($this->createMockModel());
        $program->mouseTracking();

        $reflection = new ReflectionProperty(Program::class, 'useMouseTracking');
        self::assertTrue($reflection->getValue($program));
    }

    #[Test]
    public function fpsClampsToMinimumOnePointZero(): void
    {
        $program = Program::new($this->createMockModel());
        $program->fps(0.5);

        $reflection = new ReflectionProperty(Program::class, 'framesPerSecond');
        self::assertSame(1.0, $reflection->getValue($program));
    }

    #[Test]
    public function fpsAcceptsValueAboveMinimum(): void
    {
        $program = Program::new($this->createMockModel());
        $program->fps(30.0);

        $reflection = new ReflectionProperty(Program::class, 'framesPerSecond');
        self::assertSame(30.0, $reflection->getValue($program));
    }

    #[Test]
    public function fpsClampedToMinimumOne(): void
    {
        $program = Program::new($this->createMockModel());
        $result = $program->fps(0.5);
        self::assertSame($program, $result);
    }

    #[Test]
    public function builderMethodsChainable(): void
    {
        $program = Program::new($this->createMockModel())
            ->altScreen()
            ->mouseTracking()
            ->fps(30)
            ->onError(fn (ErrorMsg $error) => Cmd::quit());

        self::assertInstanceOf(Program::class, $program);
    }

    #[Test]
    public function registerTickerDoesNotEnqueueTickMsgImmediately(): void
    {
        $program = Program::new($this->createMockModel());

        $queueReflection = new ReflectionProperty(Program::class, 'messageQueue');
        $queueReflection->setValue($program, new SplQueue());

        $registerTicker = new ReflectionMethod(Program::class, 'registerTicker');
        $registerTicker->invoke($program, 1.0);

        $queue = $queueReflection->getValue($program);
        while (!$queue->isEmpty()) {
            $message = $queue->dequeue();
            self::assertNotInstanceOf(TickMsg::class, $message, 'registerTicker should not enqueue a TickMsg immediately');
        }

        $tickersReflection = new ReflectionProperty(Program::class, 'tickers');
        $tickers = $tickersReflection->getValue($program);
        $clock = (new ReflectionProperty(Program::class, 'clock'))->getValue($program);
        self::assertCount(1, $tickers, 'registerTicker should add one ticker entry');
        self::assertGreaterThan($clock->now(), $tickers[0]->fireAt, 'Next fire time should be in the future');
        self::assertSame(1.0, $tickers[0]->interval, 'Ticker interval should match the registered interval');
    }

    #[Test]
    public function dispatchCommandWithNullIsNoOp(): void
    {
        $program = Program::new($this->createMockModel());

        $runningProperty = new ReflectionProperty(Program::class, 'running');
        $runningProperty->setValue($program, true);

        $method = new ReflectionMethod(Program::class, 'dispatchCommand');
        $method->invoke($program, null);

        self::assertTrue($runningProperty->getValue($program), 'null Cmd must not change running state');
    }

    #[Test]
    public function dispatchCommandQuitCmdStopsRunning(): void
    {
        $program = Program::new($this->createMockModel());

        $runningProperty = new ReflectionProperty(Program::class, 'running');
        $runningProperty->setValue($program, true);

        $method = new ReflectionMethod(Program::class, 'dispatchCommand');
        $method->invoke($program, Cmd::quit());

        self::assertFalse($runningProperty->getValue($program), 'QuitCmd must set running=false');
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
        self::assertSame('call', $message->source);
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($throwingModel);

        $modelProperty = new ReflectionProperty(Program::class, 'model');
        $modelProperty->setValue($program, $throwingModel);

        $runningProperty = new ReflectionProperty(Program::class, 'running');
        $runningProperty->setValue($program, true);

        $queueProperty = new ReflectionProperty(Program::class, 'messageQueue');
        $queue = new SplQueue();
        for ($i = 0; $i < 15; ++$i) {
            $queue->enqueue(new TickMsg(0.0));
        }
        $queueProperty->setValue($program, $queue);

        $method = new ReflectionMethod(Program::class, 'drainMessageQueue');
        $method->invoke($program);

        self::assertFalse($runningProperty->getValue($program), 'running must be false after 10 consecutive update errors');
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

        $firstOutput = $terminal->getOutput();

        $method->invoke($program);

        self::assertSame($firstOutput, $terminal->getOutput(), 'teardown() called twice must not produce extra output');
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

        $output = $terminal->getOutput();
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

        $output = $terminal->getOutput();
        self::assertStringContainsString(Ansi::cursorToRowStart(23) . "\n", $output, 'inline mode must position cursor at height-1 row');
    }

    #[Test]
    public function teardownAltScreenModeDoesNotMoveCursor(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel())->altScreen();
        $session = new TerminalSession($terminal, true, false);
        $session->start();

        (new ReflectionProperty(Program::class, 'terminalSession'))->setValue($program, $session);

        $method = new ReflectionMethod(Program::class, 'teardown');
        $method->invoke($program);

        $output = $terminal->getOutput();
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($quitOnFirstMessageModel)->withTerminal($terminal);

        $programFinished = false;
        $hostTimerFired = false;

        \Revolt\EventLoop::queue(function () use ($program, &$programFinished): void {
            $program->start();
            $programFinished = true;
        });
        // The host timer doubles as the run() terminator so watchers leaked by
        // unrelated tests cannot keep the loop alive; if the program had stopped
        // the shared driver, run() would return before this ever fires.
        \Revolt\EventLoop::delay(0.2, function () use (&$hostTimerFired): void {
            $hostTimerFired = true;
            \Revolt\EventLoop::getDriver()->stop();
        });

        \Revolt\EventLoop::run();

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

            public function render(Frame $frame, RenderContext $context): void
            {
                $this->hasRendered = true;
                $frame->putStyled(0, 0, 'R', new Style(foreground: Color::rgb(255, 0, 0)));
            }
        };

        $terminal = new VirtualTerminal(80, 24, ColorProfile::Ansi);
        Program::new($renderThenQuitModel)->withTerminal($terminal)->start();

        self::assertStringContainsString('R', $terminal->getOutput(), 'Precondition: at least one frame must have rendered');
        self::assertStringNotContainsString('38;2;255;0;0', $terminal->getOutput(), 'Program must construct the Screen with the detected terminal color profile so truecolor degrades on 16-color terminals');
    }

    #[Test]
    public function drainMessageQueueStopsOnQuitMsg(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('running')->setValue($program, true);

        $queue = new SplQueue();
        $queue->enqueue(QuitMsg::Quit);
        $tickMsg = new TickMsg(0.0);
        $queue->enqueue($tickMsg);
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertFalse($reflection->getProperty('running')->getValue($program), 'QuitMsg must stop the drain');
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($model);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $model);
        $reflection->getProperty('running')->setValue($program, true);

        $queue = new SplQueue();
        $queue->enqueue(new TestMessage('first'));
        $queue->enqueue(new TestMessage('second'));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertFalse($reflection->getProperty('running')->getValue($program), 'Cmd::quit must stop the program');
        self::assertSame(1, $model->updates, 'No further messages may be delivered once the model requested quit');
    }

    #[Test]
    public function dispatchCommandTickCmdRegistersTicker(): void
    {
        $program = Program::new($this->createMockModel());
        $tickersProperty = new ReflectionProperty(Program::class, 'tickers');
        $tickersProperty->setValue($program, []);

        (new ReflectionMethod(Program::class, 'dispatchCommand'))->invoke($program, Cmd::tick(0.5));

        $tickers = $tickersProperty->getValue($program);
        self::assertCount(1, $tickers, 'TickCmd dispatch must add a ticker entry');
        self::assertSame(0.5, $tickers[0]->interval);
    }

    #[Test]
    public function dispatchCommandAfterCmdRegistersTimer(): void
    {
        $program = Program::new($this->createMockModel());
        $timersProperty = new ReflectionProperty(Program::class, 'timers');
        $timersProperty->setValue($program, []);

        (new ReflectionMethod(Program::class, 'dispatchCommand'))->invoke($program, Cmd::after(1.0, fn () => null));

        self::assertCount(1, $timersProperty->getValue($program), 'AfterCmd dispatch must add a timer entry');
    }

    #[Test]
    public function dispatchCommandCancelCmdRemovesMatchingTickerAndTimer(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $tickersProperty = $reflection->getProperty('tickers');
        $timersProperty = $reflection->getProperty('timers');
        $tickersProperty->setValue($program, []);
        $timersProperty->setValue($program, []);

        $dispatch = $reflection->getMethod('dispatchCommand');
        $dispatch->invoke($program, Cmd::tick(1.0, id: EffectId::named('clock')));
        $dispatch->invoke($program, Cmd::tick(0.5, id: EffectId::named('spinner')));
        $dispatch->invoke($program, Cmd::after(9.0, fn () => null, id: EffectId::named('clock')));
        $dispatch->invoke($program, Cmd::after(9.0, fn () => null));

        $dispatch->invoke($program, Cmd::cancel(EffectId::named('clock')));

        $tickers = $tickersProperty->getValue($program);
        self::assertSame([0], array_keys($tickers), 'Ticker list must be reindexed after cancellation');
        self::assertSame(0.5, $tickers[0]->interval, 'The surviving ticker is the spinner');
        self::assertSame('spinner', $tickers[0]->id?->value);

        $timers = $timersProperty->getValue($program);
        self::assertSame([0], array_keys($timers), 'Timer list must be reindexed after cancellation');
        self::assertNull($timers[0]->id, 'The id-less timer must survive; the id-matched one is cancelled');
    }

    #[Test]
    public function dispatchCommandCancelCmdWithUnknownIdIsANoOp(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $tickersProperty = $reflection->getProperty('tickers');
        $tickersProperty->setValue($program, []);

        $dispatch = $reflection->getMethod('dispatchCommand');
        $dispatch->invoke($program, Cmd::tick(1.0, id: EffectId::named('clock')));
        $dispatch->invoke($program, Cmd::cancel(EffectId::named('nonexistent')));

        self::assertCount(1, $tickersProperty->getValue($program), 'Cancelling an unknown id must leave registered effects untouched');
    }

    #[Test]
    public function dispatchCommandCancelStopsAndRemovesMatchingRunningEffects(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $id = EffectId::named('download');
        $process = new class($id) implements RunningProcess {
            public bool $stopped = false;

            public function __construct(private readonly EffectId $effectId)
            {
            }

            public function pump(): array
            {
                return [];
            }

            public function map(Message $message): Message
            {
                return $message;
            }

            public function isRunning(): bool
            {
                return !$this->stopped;
            }

            public function stop(): void
            {
                $this->stopped = true;
            }

            public function id(): ?EffectId
            {
                return $this->effectId;
            }
        };
        $reflection->getProperty('processes')->setValue($program, [$process]);

        $reflection->getMethod('dispatchCommand')->invoke(
            $program,
            Cmd::cancel(EffectId::named('download')),
        );

        self::assertTrue($process->stopped);
        self::assertSame([], $reflection->getProperty('processes')->getValue($program));
    }

    #[Test]
    public function anHttpRequestThatCannotBeMadeBecomesAnErrorMessage(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, []);
        $queue = new SplQueue();
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        (new ReflectionMethod(Program::class, 'dispatchCommand'))->invoke(
            $program,
            Cmd::http('not-a-url'),
        );

        self::assertSame([], $reflection->getProperty('processes')->getValue($program), 'Nothing is left in flight');
        self::assertCount(1, $queue, 'The failure is reported rather than thrown');

        $message = $queue->dequeue();
        self::assertInstanceOf(ErrorMsg::class, $message);
        self::assertSame('http', $message->source);
    }

    #[Test]
    public function aProcessThatCannotStartBecomesAnErrorMessage(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, []);
        $queue = new SplQueue();
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        (new ReflectionMethod(Program::class, 'dispatchCommand'))->invoke(
            $program,
            Cmd::exec(['true'])->withWorkingDirectory('/no/such/directory')->withId(EffectId::named('doomed')),
        );

        self::assertSame([], $reflection->getProperty('processes')->getValue($program), 'Nothing is left running');
        self::assertCount(1, $queue, 'The failure is reported rather than thrown');

        $message = $queue->dequeue();
        self::assertInstanceOf(ErrorMsg::class, $message);
        self::assertSame('process', $message->source);
    }

    #[Test]
    public function anEffectIdNamesOneRunningEffect(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('tickers')->setValue($program, []);
        $reflection->getProperty('processes')->setValue($program, []);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $dispatch = new ReflectionMethod(Program::class, 'dispatchCommand');

        $dispatch->invoke($program, Cmd::tick(1.0, EffectId::named('poll')));
        $dispatch->invoke($program, Cmd::tick(1.0, EffectId::named('poll')));
        $dispatch->invoke($program, Cmd::exec(['true'])->withId(EffectId::named('fetch')));
        $dispatch->invoke($program, Cmd::exec(['true'])->withId(EffectId::named('fetch')));

        self::assertCount(1, $reflection->getProperty('tickers')->getValue($program), 'One ticker for the id');
        self::assertCount(1, $reflection->getProperty('processes')->getValue($program), 'One process for the id');
    }

    #[Test]
    public function anEffectIdNamesOneEffectAcrossKinds(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('tickers')->setValue($program, []);
        $reflection->getProperty('processes')->setValue($program, []);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $dispatch = new ReflectionMethod(Program::class, 'dispatchCommand');
        $refresh = EffectId::named('refresh');

        $dispatch->invoke($program, Cmd::tick(1.0, $refresh));
        $dispatch->invoke($program, Cmd::exec(['true'])->withId($refresh));

        self::assertCount(0, $reflection->getProperty('tickers')->getValue($program), 'The ticker gave up the name');
        self::assertCount(1, $reflection->getProperty('processes')->getValue($program));
    }

    #[Test]
    public function dispatchCommandExecCmdSpawnsProcess(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, []);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        (new ReflectionMethod(Program::class, 'dispatchCommand'))
            ->invoke($program, Cmd::exec(['true']));

        $processes = $reflection->getProperty('processes')->getValue($program);
        self::assertCount(1, $processes, 'ExecCmd dispatch must add a running process');
    }

    #[Test]
    public function dispatchCommandProcessCmdSpawnsStreamingProcess(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, []);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        (new ReflectionMethod(Program::class, 'dispatchCommand'))
            ->invoke($program, Cmd::process(['true']));

        $processes = $reflection->getProperty('processes')->getValue($program);
        self::assertCount(1, $processes, 'ProcessCmd dispatch must add a streaming process');
    }

    #[Test]
    public function aProcessStartedUnderAnIdAlreadyRunningReplacesIt(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, []);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $dispatch = new ReflectionMethod(Program::class, 'dispatchCommand');
        $id = EffectId::named('run');
        $dispatch->invoke($program, Cmd::process(['sleep', '5'])->withId($id));
        $dispatch->invoke($program, Cmd::process(['true'])->withId($id));

        self::assertCount(
            1,
            $reflection->getProperty('processes')->getValue($program),
            'The process left over from the first start no longer writes into the second',
        );
    }

    #[Test]
    public function dispatchCommandHttpCmdAddsRequestToProcesses(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, []);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getProperty('httpClient')->setValue(
            $program,
            new \Symfony\Component\HttpClient\MockHttpClient(
                fn () => new \Symfony\Component\HttpClient\Response\MockResponse('ok'),
            ),
        );

        (new ReflectionMethod(Program::class, 'dispatchCommand'))
            ->invoke($program, Cmd::http('http://example.com/'));

        $processes = $reflection->getProperty('processes')->getValue($program);
        self::assertCount(1, $processes, 'HttpCmd dispatch must add a request to processes');
    }

    #[Test]
    public function dispatchCommandUnknownCmdThrowsLogicException(): void
    {
        $program = Program::new($this->createMockModel());

        $unknownCmd = new UnknownTestCmd();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(UnknownTestCmd::class);

        try {
            (new ReflectionMethod(Program::class, 'dispatchCommand'))->invoke($program, $unknownCmd);
        } catch (ReflectionException $reflectionException) {
            throw $reflectionException->getPrevious() ?? $reflectionException;
        }
    }

    #[Test]
    public function dispatchCommandBatchCmdDispatchesAllChildren(): void
    {
        $program = Program::new($this->createMockModel());

        $queueProperty = new ReflectionProperty(Program::class, 'messageQueue');
        $queue = new SplQueue();
        $queueProperty->setValue($program, $queue);
        $runningProperty = new ReflectionProperty(Program::class, 'running');
        $runningProperty->setValue($program, true);

        $method = new ReflectionMethod(Program::class, 'dispatchCommand');
        $method->invoke($program, Cmd::batch(Cmd::message(new TestMessage('a')), Cmd::message(new TestMessage('b')), Cmd::quit()));

        self::assertSame('a', $queue->dequeue()->value, 'First batch child dispatched');
        self::assertSame('b', $queue->dequeue()->value, 'Second batch child dispatched');
        self::assertFalse($runningProperty->getValue($program), 'Third batch child (quit) dispatched');
    }

    #[Test]
    public function fireTimersWrapsExceptionInErrorMsg(): void
    {
        $program = Program::new($this->createMockModel());
        $effectId = EffectId::named('timer');

        $timersProperty = new ReflectionProperty(Program::class, 'timers');
        $timersProperty->setValue($program, [
            new TimerRecord(0.0, fn () => throw new RuntimeException('timer boom'), $effectId),
        ]);

        $queueProperty = new ReflectionProperty(Program::class, 'messageQueue');
        $queue = new SplQueue();
        $queueProperty->setValue($program, $queue);

        $method = new ReflectionMethod(Program::class, 'fireTimers');
        $method->invoke($program, microtime(true));

        self::assertFalse($queue->isEmpty());
        $message = $queue->dequeue();
        self::assertInstanceOf(ErrorMsg::class, $message);
        self::assertSame('timer boom', $message->error->getMessage());
        self::assertSame($effectId, $message->effectId);
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
    public function startExecProcessConstructsProcessInsideTryBlock(): void
    {
        $source = (new ReflectionMethod(Program::class, 'startExecProcess'))
            ->getDeclaringClass()
            ->getMethod('startExecProcess');
        $startLine = $source->getStartLine();
        $endLine = $source->getEndLine();
        $lines = file($source->getFileName());
        $body = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        $tryPos = strpos($body, 'try {');
        $createPos = strpos($body, '$this->createProcess(');
        self::assertNotFalse($tryPos);
        self::assertNotFalse($createPos);
        self::assertLessThan($createPos, $tryPos, 'createProcess() must be inside the try block to convert errors to ErrorMsg');
    }

    #[Test]
    public function startStreamingProcessConstructsProcessInsideTryBlock(): void
    {
        $source = (new ReflectionMethod(Program::class, 'startStreamingProcess'))
            ->getDeclaringClass()
            ->getMethod('startStreamingProcess');
        $startLine = $source->getStartLine();
        $endLine = $source->getEndLine();
        $lines = file($source->getFileName());
        $body = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

        $tryPos = strpos($body, 'try {');
        $createPos = strpos($body, '$this->createProcess(');
        self::assertNotFalse($tryPos);
        self::assertNotFalse($createPos);
        self::assertLessThan($createPos, $tryPos, 'createProcess() must be inside the try block to convert errors to ErrorMsg');
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
    public function createProcessOnlyAcceptsArray(): void
    {
        $program = Program::new($this->createMockModel());
        $method = new ReflectionMethod(Program::class, 'createProcess');
        $parameter = $method->getParameters()[0];

        self::assertFalse($parameter->getType() instanceof ReflectionUnionType);
    }

    #[Test]
    public function fireTimersFiresWhenNowExactlyEqualsFireAt(): void
    {
        $program = Program::new($this->createMockModel());
        $exactTime = 1000.0;

        $timersProperty = new ReflectionProperty(Program::class, 'timers');
        $timersProperty->setValue($program, [
            new TimerRecord($exactTime, fn () => new TestMessage('timer-result')),
        ]);

        $queueProperty = new ReflectionProperty(Program::class, 'messageQueue');
        $queue = new SplQueue();
        $queueProperty->setValue($program, $queue);

        $method = new ReflectionMethod(Program::class, 'fireTimers');
        $method->invoke($program, $exactTime);

        self::assertFalse($queue->isEmpty());
        self::assertSame('timer-result', $queue->dequeue()->value);
    }

    #[Test]
    public function fireTimersErrorDoesNotDispatchResult(): void
    {
        $program = Program::new($this->createMockModel());

        $timersProperty = new ReflectionProperty(Program::class, 'timers');
        $timersProperty->setValue($program, [
            new TimerRecord(0.0, fn () => throw new RuntimeException('boom')),
            new TimerRecord(0.0, fn () => new TestMessage('second-timer-result')),
        ]);

        $queueProperty = new ReflectionProperty(Program::class, 'messageQueue');
        $queue = new SplQueue();
        $queueProperty->setValue($program, $queue);

        $method = new ReflectionMethod(Program::class, 'fireTimers');
        $method->invoke($program, microtime(true));

        $messages = [];
        while (!$queue->isEmpty()) {
            $messages[] = $queue->dequeue();
        }

        self::assertCount(2, $messages);
        self::assertInstanceOf(ErrorMsg::class, $messages[0]);
        self::assertSame('second-timer-result', $messages[1]->value);
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
    public function fireTickersFiresOnceAfterLongSuspension(): void
    {
        $program = Program::new($this->createMockModel());

        $reflection = new ReflectionClass($program);
        $tickersProperty = $reflection->getProperty('tickers');
        $queueProperty = $reflection->getProperty('messageQueue');
        $fireMethod = $reflection->getMethod('fireTickers');

        $queueProperty->setValue($program, new SplQueue());

        $now = microtime(true);
        $tickersProperty->setValue($program, [new TickerRecord($now - 1.0, 0.1)]);

        $fireMethod->invoke($program, $now);

        $queue = $queueProperty->getValue($program);
        self::assertSame(1, $queue->count(), 'Should fire exactly once, not 10 times for catch-up');
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($model);
        $reflection = new ReflectionClass($program);

        $queueProperty = $reflection->getProperty('messageQueue');
        $modelProperty = $reflection->getProperty('model');
        $runningProperty = $reflection->getProperty('running');
        $drainMethod = $reflection->getMethod('drainMessageQueue');

        $queue = new SplQueue();
        $queue->enqueue('trigger');
        $queueProperty->setValue($program, $queue);
        $modelProperty->setValue($program, $model);
        $runningProperty->setValue($program, true);

        $drainMethod->invoke($program);

        self::assertFalse(
            $runningProperty->getValue($program),
            'Program should force-quit after consecutive errors instead of looping forever'
        );
    }

    #[Test]
    public function nextTimerDelayReturnsDelayWhenOnlyTickersRegistered(): void
    {
        $program = Program::new($this->createMockModel());
        $tickersProperty = new ReflectionProperty(Program::class, 'tickers');
        $tickersProperty->setValue($program, [new TickerRecord(microtime(true) + 1.0, 1.0)]);

        $method = new ReflectionMethod(Program::class, 'nextTimerDelay');
        $delay = $method->invoke($program);

        self::assertNotNull($delay, 'tickers must contribute to the next-timer delay');
        self::assertGreaterThan(0.0, $delay);
    }

    #[Test]
    public function nextTimerDelayReturnsDelayWhenOnlyTimersRegistered(): void
    {
        $program = Program::new($this->createMockModel());
        $timersProperty = new ReflectionProperty(Program::class, 'timers');
        $timersProperty->setValue($program, [new TimerRecord(microtime(true) + 1.0, fn () => null)]);

        $method = new ReflectionMethod(Program::class, 'nextTimerDelay');
        $delay = $method->invoke($program);

        self::assertNotNull($delay, 'timers must contribute to the next-timer delay');
        self::assertGreaterThan(0.0, $delay);
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
        $reflection->getProperty('isDirty')->setValue($program, false);
        $reflection->getProperty('resizeNeedsScreenClear')->setValue($program, false);

        $method = $reflection->getMethod('handlePendingResize');
        $method->invoke($program);

        self::assertTrue($queue->isEmpty(), 'No ResizeMsg should be enqueued when no resize is pending');
        self::assertFalse($reflection->getProperty('isDirty')->getValue($program));
        self::assertFalse($reflection->getProperty('resizeNeedsScreenClear')->getValue($program));
    }

    #[Test]
    public function pumpProcessesPumpsPendingMessages(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);

        $queueProperty = $reflection->getProperty('messageQueue');
        $queue = new SplQueue();
        $queueProperty->setValue($program, $queue);

        $process = new class implements RunningProcess {
            public function pump(): array
            {
                return [new TestMessage('pumped-message')];
            }

            public function map(Message $message): Message
            {
                return $message;
            }

            public function isRunning(): bool
            {
                return false;
            }

            public function stop(): void
            {
            }

            public function id(): ?EffectId
            {
                return null;
            }
        };

        $reflection->getProperty('processes')->setValue($program, [$process]);

        $reflection->getMethod('pumpProcesses')->invoke($program);

        self::assertFalse($queue->isEmpty(), 'Pumped messages must be enqueued');
        self::assertSame('pumped-message', $queue->dequeue()->value);
    }

    #[Test]
    public function fireTickersAdvancesNextFireTimeIntoTheFuture(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);

        $queueProperty = $reflection->getProperty('messageQueue');
        $queueProperty->setValue($program, new SplQueue());

        $now = microtime(true);
        $tickersProperty = $reflection->getProperty('tickers');
        $tickersProperty->setValue($program, [new TickerRecord($now - 0.1, 1.0)]);

        $reflection->getMethod('fireTickers')->invoke($program, $now);

        $tickers = $tickersProperty->getValue($program);
        self::assertGreaterThan($now, $tickers[0]->fireAt, 'Ticker next-fire time must advance into the future, not the past');
    }

    #[Test]
    public function fireTickersFiresExactlyAtBoundaryWhenFireAtEqualsNow(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);

        $queue = new SplQueue();
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $now = 100.0;
        $reflection->getProperty('tickers')->setValue($program, [new TickerRecord($now, 0.5)]);

        $reflection->getMethod('fireTickers')->invoke($program, $now);

        self::assertFalse($queue->isEmpty(), 'Ticker with fireAt == now must fire (the comparison is <=, not <)');
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

        $signalIdProperties = ['sigwinchCallbackId', 'sigintCallbackId', 'sigtermCallbackId', 'sigcontCallbackId', 'sigtstpCallbackId'];

        $setupMethod = $reflection->getMethod('setup');
        $setupMethod->invoke($program);

        foreach ($signalIdProperties as $name) {
            $value = $reflection->getProperty($name)->getValue($program);
            self::assertNotNull($value, "$name must be populated after setup() so teardown can cancel it");
        }

        $registeredIds = [];
        foreach ($signalIdProperties as $name) {
            $registeredIds[] = $reflection->getProperty($name)->getValue($program);
        }

        $teardownMethod = $reflection->getMethod('teardown');
        $teardownMethod->invoke($program);

        foreach ($signalIdProperties as $name) {
            $value = $reflection->getProperty($name)->getValue($program);
            self::assertNull($value, "$name must be cancelled and nulled out by teardown()");
        }

        $activeIds = \Revolt\EventLoop::getIdentifiers();
        foreach ($registeredIds as $id) {
            self::assertNotContains($id, $activeIds, 'Watcher must be cancelled in EventLoop, not just nulled in the property');
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

        $output = $terminal->getOutput();
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
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('isDirty')->setValue($program, false);
        $queueProperty = $reflection->getProperty('messageQueue');
        $queue = new SplQueue();
        $queueProperty->setValue($program, $queue);

        $reflection->getMethod('resume')->invoke($program);
        $reflection->getMethod('cancelEventLoopWatchers')->invoke($program);

        self::assertTrue($reflection->getProperty('isDirty')->getValue($program), 'resume must mark renderer dirty so the next frame is drawn');

        $found = false;
        while (!$queue->isEmpty()) {
            if ($queue->dequeue() instanceof ResizeMsg) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'resume must enqueue ResizeMsg so the model re-runs layout');

        self::assertStringContainsString(Ansi::ERASE_DISPLAY, $terminal->getOutput(), 'resume must clear the screen so leftover content from another app does not show through');
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($countingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);
        $reflection->getProperty('running')->setValue($program, true);

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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($alternatingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $alternatingModel);
        $reflection->getProperty('running')->setValue($program, true);

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
        $reflection->getProperty('running')->setValue($program, true);

        $queue = new SplQueue();
        $queue->enqueue(new ErrorMsg(new RuntimeException('test'), 'test'));
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($observingModel)->onError(fn () => null);

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);
        $reflection->getProperty('running')->setValue($program, true);

        $queue = new SplQueue();
        $queue->enqueue(new ErrorMsg(new RuntimeException('test'), 'test'));
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($observingModel)
            ->onError(fn (ErrorMsg $error) => throw new RuntimeException('handler boom'));

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);
        $reflection->getProperty('running')->setValue($program, true);

        $queue = new SplQueue();
        $queue->enqueue(new ErrorMsg(new RuntimeException('original'), 'render'));
        $queue->enqueue(new TickMsg(0.0));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertTrue($reflection->getProperty('running')->getValue($program), 'A single throwing errorHandler call must not stop the program');
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($observingModel)
            ->onError(fn (ErrorMsg $e) => Cmd::message(new TestMessage('from-handler')));

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);
        $reflection->getProperty('running')->setValue($program, true);

        $queue = new SplQueue();
        $queue->enqueue(new ErrorMsg(new RuntimeException('test'), 'test'));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertSame('from-handler', $messageDelivered->value, 'Cmd returned by errorHandler must be dispatched (instanceof Cmd check + dispatchCommand)');
    }

    #[Test]
    public function drainMessageQueueErrorMsgThresholdQuitsAt10WithHandler(): void
    {
        $errorCount = 0;
        $program = Program::new($this->createMockModel())
            ->onError(function () use (&$errorCount) {
                ++$errorCount;

                return null;
            });

        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('running')->setValue($program, true);

        $queue = new SplQueue();
        for ($i = 0; $i < 15; ++$i) {
            $queue->enqueue(new ErrorMsg(new RuntimeException("e$i"), 'test'));
        }
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertSame(9, $errorCount, 'Counter increments BEFORE handler call. With threshold `>= 10`: handler runs for counts 1..9; iteration 10 increments to 10, returns without calling handler. Mutant `>` would let count reach 10 → handler called 10 times.');
        self::assertFalse($reflection->getProperty('running')->getValue($program), 'Threshold must set running=false; FalseValue mutant flips this to true so threshold becomes a no-op');
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($constantRevisionModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $constantRevisionModel);
        $reflection->getProperty('running')->setValue($program, true);
        $reflection->getProperty('isDirty')->setValue($program, false);

        $queue = new SplQueue();
        $queue->enqueue(new TickMsg(0.0));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertNotSame($constantRevisionModel, $reflection->getProperty('model')->getValue($program), 'Model instance must have swapped (update returned new self())');
        self::assertFalse($reflection->getProperty('isDirty')->getValue($program), 'When the model implements RenderRevisioned and renderRevision() is unchanged, isDirty must stay false even though the model instance differs');
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($changingRevisionModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $changingRevisionModel);
        $reflection->getProperty('running')->setValue($program, true);
        $reflection->getProperty('isDirty')->setValue($program, false);

        $queue = new SplQueue();
        $queue->enqueue(new TickMsg(0.0));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertTrue($reflection->getProperty('isDirty')->getValue($program), 'renderRevision actually changed (0 → 1) → isDirty must flip to true');
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($changingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $changingModel);
        $reflection->getProperty('running')->setValue($program, true);
        $reflection->getProperty('isDirty')->setValue($program, false);

        $queue = new SplQueue();
        $queue->enqueue(new TickMsg(0.0));
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertTrue(
            $reflection->getProperty('isDirty')->getValue($program),
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($observingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);
        $reflection->getProperty('running')->setValue($program, true);
        $reflection->getProperty('width')->setValue($program, 120);
        $reflection->getProperty('height')->setValue($program, 40);

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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($observingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);
        $reflection->getProperty('running')->setValue($program, true);
        $reflection->getProperty('width')->setValue($program, 120);
        $reflection->getProperty('height')->setValue($program, 40);

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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($observingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);
        $reflection->getProperty('running')->setValue($program, true);
        $reflection->getProperty('width')->setValue($program, 120);
        $reflection->getProperty('height')->setValue($program, 40);

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

            public function render(Frame $frame, RenderContext $context): void
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
        self::assertSame($popup, $model->received->float()->float);
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
    public function screenRectCacheInvalidatesForEachDimensionIndependently(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $screenRect = $reflection->getMethod('screenRect');

        $initial = $screenRect->invoke($program);
        self::assertInstanceOf(Rect::class, $initial);
        self::assertSame($initial, $screenRect->invoke($program));

        $reflection->getProperty('width')->setValue($program, 120);
        $wider = $screenRect->invoke($program);
        self::assertInstanceOf(Rect::class, $wider);
        self::assertNotSame($initial, $wider);
        self::assertSame([120, 24], [$wider->width, $wider->height]);

        $reflection->getProperty('height')->setValue($program, 40);
        $taller = $screenRect->invoke($program);
        self::assertInstanceOf(Rect::class, $taller);
        self::assertNotSame($wider, $taller);
        self::assertSame([120, 40], [$taller->width, $taller->height]);
    }

    #[Test]
    public function nextRenderDelayTracksTheExactFrameDeadline(): void
    {
        $clock = new TestClock(10.25);
        $program = Program::new($this->createMockModel())
            ->withClock($clock)
            ->fps(2.0);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('lastRender')->setValue($program, 10.0);
        $nextRenderDelay = $reflection->getMethod('nextRenderDelay');

        self::assertEqualsWithDelta(0.25, $nextRenderDelay->invoke($program), 0.000_001);

        $clock->advance(1.0);
        self::assertSame(0.0, $nextRenderDelay->invoke($program));

        $reflection->getProperty('isDirty')->setValue($program, false);
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($countingModel)->withClock(new TestClock(100.0));
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);
        $reflection->getProperty('running')->setValue($program, true);

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

            public function render(Frame $frame, RenderContext $context): void
            {
                throw new RuntimeException('render boom');
            }
        };

        $program = Program::new($throwingRenderModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('width')->setValue($program, 80);
        $reflection->getProperty('height')->setValue($program, 24);
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $throwingRenderModel);
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));
        $reflection->getProperty('running')->setValue($program, true);
        $reflection->getProperty('isDirty')->setValue($program, true);
        $reflection->getProperty('lastRender')->setValue($program, 0.0);

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

            public function render(Frame $frame, RenderContext $context): void
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

            public function render(Frame $frame, RenderContext $context): void
            {
                $this->seenNow = $context->now();
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

            public function render(Frame $frame, RenderContext $context): void
            {
                ++$this->renders;
                $context->requestRepaint(0.05);
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
        self::assertFalse($reflection->getProperty('isDirty')->getValue($program));

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

            public function render(Frame $frame, RenderContext $context): void
            {
                ++$this->renders;
                $context->requestRepaint(0.5);
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

            public function render(Frame $frame, RenderContext $context): void
            {
                throw new RuntimeException('render boom');
            }
        };

        $program = Program::new($throwingRenderModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('width')->setValue($program, 80);
        $reflection->getProperty('height')->setValue($program, 24);
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $throwingRenderModel);
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));
        $reflection->getProperty('running')->setValue($program, true);
        $reflection->getProperty('isDirty')->setValue($program, true);
        $reflection->getProperty('lastRender')->setValue($program, 0.0);

        $ticker = new AdaptiveTicker(fn () => null);
        $reflection->getProperty('adaptiveTicker')->setValue($program, $ticker);

        $reflection->getMethod('runTick')->invoke($program);
        $ticker->stop();

        self::assertTrue(
            $reflection->getProperty('isDirty')->getValue($program),
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
        $reflection->getProperty('width')->setValue($program, 80);
        $reflection->getProperty('height')->setValue($program, 24);
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));
        $reflection->getProperty('running')->setValue($program, true);
        $reflection->getProperty('isDirty')->setValue($program, false);
        $reflection->getProperty('lastRender')->setValue($program, microtime(true));

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
        yield 'altScreen' => [fn (Program $program) => $program->altScreen()];
        yield 'mouseTracking' => [fn (Program $program) => $program->mouseTracking()];
        yield 'fps' => [fn (Program $program) => $program->fps(30.0)];
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
    public function injectedClockDrivesTimerSchedulingDeterministically(): void
    {
        $clock = new TestClock(100.0);
        $program = Program::new($this->createMockModel())->withClock($clock);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $fired = false;
        $reflection->getMethod('registerTimer')->invoke($program, 5.0, function () use (&$fired) {
            $fired = true;

            return null;
        });

        $clock->advance(4.9);
        $reflection->getMethod('fireTimers')->invoke($program, $clock->now());
        self::assertFalse($fired, 'Timer must not fire before its delay elapsed');

        $clock->advance(0.1);
        $reflection->getMethod('fireTimers')->invoke($program, $clock->now());
        self::assertTrue($fired, 'Timer must fire exactly when the injected clock reaches the deadline');
    }

    #[Test]
    public function injectedClockDrivesTickerFiringDeterministically(): void
    {
        $clock = new TestClock(50.0);
        $program = Program::new($this->createMockModel())->withClock($clock);
        $reflection = new ReflectionClass($program);
        $queue = new SplQueue();
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('registerTicker')->invoke($program, 2.0);

        $clock->advance(1.9);
        $reflection->getMethod('fireTickers')->invoke($program, $clock->now());
        self::assertTrue($queue->isEmpty(), 'Ticker must not fire before its interval elapsed');

        $clock->advance(0.1);
        $reflection->getMethod('fireTickers')->invoke($program, $clock->now());
        self::assertCount(1, $queue, 'Ticker must fire when the injected clock reaches the interval');
    }

    #[Test]
    public function runTickKeepsTickerScheduledWhileProcessesActive(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('width')->setValue($program, 80);
        $reflection->getProperty('height')->setValue($program, 24);
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));
        $reflection->getProperty('running')->setValue($program, true);
        $reflection->getProperty('isDirty')->setValue($program, false);
        $reflection->getProperty('lastRender')->setValue($program, microtime(true));

        $stillRunningProcess = new class implements RunningProcess {
            public function pump(): array
            {
                return [];
            }

            public function map(Message $message): Message
            {
                return $message;
            }

            public function isRunning(): bool
            {
                return true;
            }

            public function stop(): void
            {
            }

            public function id(): ?EffectId
            {
                return null;
            }
        };
        $reflection->getProperty('processes')->setValue($program, [$stillRunningProcess]);

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

            public function render(Frame $frame, RenderContext $context): void
            {
                $this->rendered = true;
                $frame->putString(0, 0, 'HELLO');
            }
        };

        $program = Program::new($model);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('width')->setValue($program, 80);
        $reflection->getProperty('height')->setValue($program, 24);
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $model);
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('renderFrame')->invoke($program);

        self::assertTrue($model->rendered, 'renderFrame must call model->render()');
        self::assertStringContainsString('HELLO', $terminal->getOutput(), 'renderFrame must flush buffer content to the terminal');
    }

    #[Test]
    public function renderFrameReallocatesBackBufferWhenOnlyWidthChanges(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('width')->setValue($program, 100);
        $reflection->getProperty('height')->setValue($program, 24);
        $oldBuffer = new Frame(80, 24);
        $reflection->getProperty('backBuffer')->setValue($program, $oldBuffer);
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
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

            public function render(Frame $frame, RenderContext $context): void
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
        $reflection->getProperty('width')->setValue($program, 80);
        $reflection->getProperty('height')->setValue($program, 24);
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $sequenceModel);
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
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

            public function render(Frame $frame, RenderContext $context): void
            {
                $this->dirtyRowsAtRenderStart[] = array_keys($frame->dirtyRows);
            }
        };

        $program = Program::new($model);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('width')->setValue($program, 20);
        $reflection->getProperty('height')->setValue($program, 5);
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(20, 5));
        $reflection->getProperty('model')->setValue($program, $model);
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
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

            public function render(Frame $frame, RenderContext $context): void
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
        $reflection->getProperty('width')->setValue($program, 20);
        $reflection->getProperty('height')->setValue($program, 5);
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(20, 5));
        $reflection->getProperty('model')->setValue($program, $model);
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
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

            public function render(Frame $frame, RenderContext $context): void
            {
                $frame->putString(0, 0, 'PARTIAL-CONTENT');
                throw new RuntimeException('boom mid-render');
            }
        };

        $program = Program::new($partialThrowingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('width')->setValue($program, 80);
        $reflection->getProperty('height')->setValue($program, 24);
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $partialThrowingModel);
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('renderFrame')->invoke($program);

        self::assertStringNotContainsString('PARTIAL-CONTENT', $terminal->getOutput(), 'Render error must return before screen->flush() so half-rendered content is NOT emitted');
    }

    #[Test]
    public function renderFrameAllocatesNewBackBufferOnSizeMismatch(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('width')->setValue($program, 100);
        $reflection->getProperty('height')->setValue($program, 30);
        $oldBuffer = new Frame(80, 24);
        $reflection->getProperty('backBuffer')->setValue($program, $oldBuffer);
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
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
        $reflection->getProperty('width')->setValue($program, 80);
        $reflection->getProperty('height')->setValue($program, 24);
        $reflection->getProperty('backBuffer')->setValue($program, $frame);
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext($themeWithBg));
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

            public function render(Frame $frame, RenderContext $context): void
            {
                throw new RuntimeException('render boom');
            }
        };

        $program = Program::new($throwingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('width')->setValue($program, 80);
        $reflection->getProperty('height')->setValue($program, 24);
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $throwingModel);
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
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

            public function render(Frame $frame, RenderContext $context): void
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
        $running = $reflection->getProperty('running');

        for ($tick = 0; $tick < 100 && $running->getValue($program) === true; ++$tick) {
            $clock->advance(1.0);
            $runTick->invoke($program);
        }
        $ticker->stop();

        self::assertFalse($running->getValue($program), 'A render that fails every frame must end the program, not repaint nothing forever');
        self::assertLessThanOrEqual(10, $alwaysThrowingModel->renderAttempts, 'The consecutive-failure threshold bounds the retries');
    }

    #[Test]
    public function aStalledTerminalWriteReachesTheModelAsAnError(): void
    {
        $stalledOutput = new class implements TerminalOutputInterface {
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
             * @var list<string>
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

            public function render(Frame $frame, RenderContext $context): void
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

        self::assertSame(['render'], $errorObservingModel->errorSources, 'A write that stalls must become an ErrorMsg the app can act on, not unwind the loop');
    }

    #[Test]
    public function resolveThemeReturnsLightForLightColorScheme(): void
    {
        $lightTerminal = new class implements TerminalInterface {
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

            public function probeSize(): ?array
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

            public function waitForInput(int $timeoutMicroseconds): bool
            {
                return false;
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

            public function __construct(private Program $program)
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

            public function render(Frame $frame, RenderContext $context): void
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);

        $now = $reflection->getProperty('clock')->getValue($program)->now();
        $reflection->getProperty('tickers')->setValue($program, [new TickerRecord($now - 0.5, 0.1)]);

        $program->tick();

        self::assertGreaterThan(0, $observingModel->tickCount, 'fireTickers (called by tick) must deliver TickMsg to the model');
    }

    #[Test]
    public function tickFiresTimersAndDispatchesResult(): void
    {
        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);

        $timerCalled = false;
        $reflection->getProperty('timers')->setValue($program, [
            new TimerRecord($reflection->getProperty('clock')->getValue($program)->now() - 0.1, function () use (&$timerCalled) {
                $timerCalled = true;

                return null;
            }),
        ]);

        $program->tick();

        self::assertTrue($timerCalled, 'tick must call fireTimers which invokes the timer callback');
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $observingModel);

        $process = new class implements RunningProcess {
            private bool $pumped = false;

            public function pump(): array
            {
                if ($this->pumped) {
                    return [];
                }
                $this->pumped = true;

                return [new TestMessage('from-process')];
            }

            public function map(Message $message): Message
            {
                return $message;
            }

            public function isRunning(): bool
            {
                return false;
            }

            public function stop(): void
            {
            }

            public function id(): ?EffectId
            {
                return null;
            }
        };

        $reflection->getProperty('processes')->setValue($program, [$process]);

        $program->tick();

        self::assertSame('from-process', $observingModel->receivedMessage->value, 'pumpProcesses (called by tick) must enqueue process messages and drainMessageQueue must deliver them to the model');
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

            public function render(Frame $frame, RenderContext $context): void
            {
                ++$this->renderCount;
            }
        };

        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);

        $queue = $reflection->getProperty('messageQueue')->getValue($program);
        $queue->enqueue(QuitMsg::Quit);

        $reflection->getProperty('isDirty')->setValue($program, true);
        $reflection->getProperty('lastRender')->setValue($program, 0.0);

        $program->tick();

        self::assertFalse($reflection->getProperty('running')->getValue($program), 'tick must propagate QuitMsg → running=false');
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

            public function render(Frame $frame, RenderContext $context): void
            {
                ++$this->renderCount;
            }
        };

        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);
        $reflection->getProperty('isDirty')->setValue($program, true);
        $reflection->getProperty('lastRender')->setValue($program, 0.0);

        $program->tick();

        self::assertSame(1, $renderCount, 'tick must call renderFrame → model.render exactly once when dirty and budget met');
        self::assertFalse($reflection->getProperty('isDirty')->getValue($program), 'After render, isDirty must clear to false');
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

            public function render(Frame $frame, RenderContext $context): void
            {
                ++$this->renderCount;
            }
        };

        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);
        $reflection->getProperty('isDirty')->setValue($program, true);
        $reflection->getProperty('lastRender')->setValue($program, $reflection->getProperty('clock')->getValue($program)->now() + 10.0);

        $program->tick();

        self::assertSame(0, $renderCount, 'tick must NOT render when fresh lastRender means frame budget (1/fps) is not yet elapsed');
        self::assertTrue($reflection->getProperty('isDirty')->getValue($program), 'isDirty must stay true when render is skipped');
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

            public function render(Frame $frame, RenderContext $context): void
            {
                ++$this->renderCount;
            }
        };

        $program = $this->buildTickableProgram()->fps(1.0);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);
        $reflection->getProperty('isDirty')->setValue($program, true);
        $reflection->getProperty('lastRender')->setValue($program, $reflection->getProperty('clock')->getValue($program)->now());

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

            public function render(Frame $frame, RenderContext $context): void
            {
                ++$this->renderCount;
            }
        };

        $program = $this->buildTickableProgram();
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);
        $reflection->getProperty('isDirty')->setValue($program, true);
        // Set lastRender far enough in the past that now >= lastRender + frameBudget holds
        $reflection->getProperty('lastRender')->setValue($program, $reflection->getProperty('clock')->getValue($program)->now() - 1.0);

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

            public function render(Frame $frame, RenderContext $context): void
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

            public function render(Frame $frame, RenderContext $context): void
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
    public function configureProcessAppliesTimeoutEnvAndInput(): void
    {
        $program = Program::new($this->createMockModel());
        $process = new \Symfony\Component\Process\Process(['true']);

        $configureMethod = new ReflectionMethod(Program::class, 'configureProcess');
        $configureMethod->invoke($program, $process, '/tmp', 7.5, ['MY_VAR' => 'hello'], 'piped-input');

        self::assertSame(7.5, $process->getTimeout(), 'timeout must be applied when not null');
        self::assertSame('hello', $process->getEnv()['MY_VAR'] ?? null, 'env must be applied when not empty');
        self::assertSame('piped-input', $process->getInput(), 'input must be applied when not null');
        self::assertSame('/tmp', $process->getWorkingDirectory(), 'working dir must be applied when not null');
    }

    #[Test]
    public function configureProcessSkipsTimeoutEnvAndInputWhenNullOrEmpty(): void
    {
        $program = Program::new($this->createMockModel());
        $process = new \Symfony\Component\Process\Process(['true']);
        $process->setTimeout(99.0);
        $defaultTimeout = $process->getTimeout();

        $configureMethod = new ReflectionMethod(Program::class, 'configureProcess');
        $configureMethod->invoke($program, $process, null, null, [], null);

        self::assertSame($defaultTimeout, $process->getTimeout(), 'Null timeout must not clobber pre-set value');
        self::assertNull($process->getInput(), 'Null input must remain null');
    }

    #[Test]
    public function startExecProcessActuallyStartsTheProcess(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, []);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        (new ReflectionMethod(Program::class, 'startExecProcess'))
            ->invoke($program, Cmd::exec(['sleep', '5']));

        $processes = $reflection->getProperty('processes')->getValue($program);
        self::assertCount(1, $processes);

        $runningProcess = $processes[0];
        $procReflection = new ReflectionObject($runningProcess);
        $innerProcess = $procReflection->getProperty('process')->getValue($runningProcess);
        self::assertTrue($innerProcess->isStarted(), 'startExecProcess must actually call $process->start()');
        $innerProcess->stop(0);
    }

    #[Test]
    public function startStreamingProcessActuallyStartsTheProcess(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, []);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        (new ReflectionMethod(Program::class, 'startStreamingProcess'))
            ->invoke($program, Cmd::process(['sleep', '5']));

        $processes = $reflection->getProperty('processes')->getValue($program);
        self::assertCount(1, $processes);

        $runningProcess = $processes[0];
        $procReflection = new ReflectionObject($runningProcess);
        $innerProcess = $procReflection->getProperty('process')->getValue($runningProcess);
        self::assertTrue($innerProcess->isStarted(), 'startStreamingProcess must actually call $process->start()');
        $innerProcess->stop(0);
    }

    #[Test]
    public function startHttpRequestPassesHeadersAndBody(): void
    {
        $capturedOptions = null;
        $mockClient = new \Symfony\Component\HttpClient\MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedOptions): \Symfony\Component\HttpClient\Response\MockResponse {
                $capturedOptions = $options;

                return new \Symfony\Component\HttpClient\Response\MockResponse('');
            },
        );

        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, []);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('httpClient')->setValue($program, $mockClient);

        (new ReflectionMethod(Program::class, 'startHttpRequest'))
            ->invoke($program, Cmd::http('http://example.com/', 'POST', ['X-Custom' => 'v'], 'body-data'));

        $rawHeaders = $capturedOptions['headers'] ?? [];
        $headerLine = is_array($rawHeaders) ? implode("\n", array_map(fn ($k, $v) => is_int($k) ? $v : "$k: $v", array_keys($rawHeaders), $rawHeaders)) : (string)$rawHeaders;
        self::assertStringContainsString('X-Custom', $headerLine, 'startHttpRequest must include headers when HttpCmd->headers !== []');
        self::assertStringContainsString('v', $headerLine);
        self::assertSame('body-data', $capturedOptions['body'] ?? null, 'startHttpRequest must include body when HttpCmd->body !== null');
    }

    #[Test]
    public function nextTimerDelayClampsNegativeFireTimesToZero(): void
    {
        $program = Program::new($this->createMockModel())->withClock(new TestClock(100.0));
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('tickers')->setValue($program, [new TickerRecord(95.0, 1.0)]);
        $reflection->getProperty('timers')->setValue($program, []);

        $delay = (new ReflectionMethod(Program::class, 'nextTimerDelay'))->invoke($program);

        self::assertSame(0.0, $delay, 'Past-due tickers must clamp to 0.0 (max(0.0, negative) = 0.0). max(1.0, ...) mutant would clamp to 1.0 instead');
    }

    #[Test]
    public function nextTimerDelayClampsNegativeTimerFireTimesToZero(): void
    {
        $program = Program::new($this->createMockModel())->withClock(new TestClock(100.0));
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('tickers')->setValue($program, []);
        $reflection->getProperty('timers')->setValue($program, [new TimerRecord(95.0, fn () => null)]);

        $delay = (new ReflectionMethod(Program::class, 'nextTimerDelay'))->invoke($program);

        self::assertSame(0.0, $delay, 'Past-due timers must clamp to 0.0 (max(0.0, negative)). max(1.0, ...) mutant would return 1.0 instead');
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
        $reflection->getProperty('isDirty')->setValue($program, false);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('handlePendingResize')->invoke($program);

        self::assertFalse($reflection->getProperty('resizePending')->getValue($program), 'resizePending must clear to false after handling');
        self::assertTrue($reflection->getProperty('resizeNeedsScreenClear')->getValue($program), 'resizeNeedsScreenClear must be set so the next renderFrame clears the screen');
        self::assertTrue($reflection->getProperty('isDirty')->getValue($program), 'Resize must mark renderer dirty');
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
        $reflection->getProperty('width')->setValue($program, 80);
        $reflection->getProperty('height')->setValue($program, 24);
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $this->createMockModel());
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        // First render to establish a stable front buffer in the Screen
        $reflection->getMethod('renderFrame')->invoke($program);
        $terminal->clearOutput();

        // Set the resize flag; second render must explicitly call screen->clear() which writes ERASE_DISPLAY again
        $reflection->getProperty('resizeNeedsScreenClear')->setValue($program, true);
        $reflection->getMethod('renderFrame')->invoke($program);

        self::assertFalse($reflection->getProperty('resizeNeedsScreenClear')->getValue($program), 'Flag must clear to false after consuming it (FalseValue mutant flips it to true → would re-clear forever)');
        self::assertStringContainsString(Ansi::ERASE_DISPLAY, $terminal->getOutput(), 'Second render with resizeNeedsScreenClear=true must call screen->clear() which writes ERASE_DISPLAY; without it, renderDiff would not emit ERASE_DISPLAY for the unchanged content');
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
        $reflection->getProperty('width')->setValue($program, 80);
        $reflection->getProperty('height')->setValue($program, 24);
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        });
        $themeNoBackground = Theme::dark()->withBase(
            new Style(foreground: Color::rgb(200, 200, 200)),
        );
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext($themeNoBackground));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $reflection->getMethod('renderFrame')->invoke($program);

        self::assertSame(0, $frame->backgrounds[5], 'After frame reset with no theme background, cells must be background 0 (not filled with anything)');
    }

    #[Test]
    public function startExecProcessAppliesWorkingDirectory(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, []);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $cmd = new ExecCmd(['sleep', '5'], workingDirectory: '/tmp');
        (new ReflectionMethod(Program::class, 'startExecProcess'))->invoke($program, $cmd);

        $processes = $reflection->getProperty('processes')->getValue($program);
        $innerProcess = (new ReflectionObject($processes[0]))->getProperty('process')->getValue($processes[0]);

        self::assertSame('/tmp', $innerProcess->getWorkingDirectory(), 'startExecProcess must call configureProcess so workingDirectory is applied');
        $innerProcess->stop(0);
    }

    #[Test]
    public function startStreamingProcessAppliesWorkingDirectory(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, []);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $cmd = new ProcessCmd(['sleep', '5'], workingDirectory: '/tmp');
        (new ReflectionMethod(Program::class, 'startStreamingProcess'))->invoke($program, $cmd);

        $processes = $reflection->getProperty('processes')->getValue($program);
        $innerProcess = (new ReflectionObject($processes[0]))->getProperty('process')->getValue($processes[0]);

        self::assertSame('/tmp', $innerProcess->getWorkingDirectory(), 'startStreamingProcess must call configureProcess so workingDirectory is applied');
        $innerProcess->stop(0);
    }

    #[Test]
    public function startHttpRequestOmitsBodyWhenHttpCmdBodyIsNull(): void
    {
        $capturedOptions = null;
        $mockClient = new \Symfony\Component\HttpClient\MockHttpClient(
            function (string $method, string $url, array $options) use (&$capturedOptions): \Symfony\Component\HttpClient\Response\MockResponse {
                $capturedOptions = $options;

                return new \Symfony\Component\HttpClient\Response\MockResponse('');
            },
        );

        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('processes')->setValue($program, []);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('httpClient')->setValue($program, $mockClient);

        (new ReflectionMethod(Program::class, 'startHttpRequest'))
            ->invoke($program, Cmd::http('http://example.com/'));

        self::assertArrayNotHasKey('body', $capturedOptions, 'startHttpRequest must NOT pass body when HttpCmd->body is null');
    }

    #[Test]
    public function fireTickersComparesFireTimeIndexZeroNotIntervalIndexOne(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $queue = new SplQueue();
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        // ticker[0] (fireAt) is just past, ticker[1] (interval) is far in the future
        // Original: $ticker[0] <= $now (0.9 <= 1.0) → fires
        // Mutant (index 0 → 1): $ticker[1] <= $now (2.5 <= 1.0) → does NOT fire
        $now = 1.0;
        $reflection->getProperty('tickers')->setValue($program, [new TickerRecord(0.9, 2.5)]);

        $reflection->getMethod('fireTickers')->invoke($program, $now);

        self::assertSame(1, $queue->count(), 'Comparison must be against ticker[0] (fireAt), not ticker[1] (interval)');
    }

    #[Test]
    public function fireTickersUsesIntervalIndexToComputeNextFireTime(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $now = 1000.0;
        $interval = 2.5;
        $reflection->getProperty('tickers')->setValue($program, [new TickerRecord($now - 0.1, $interval)]);

        $reflection->getMethod('fireTickers')->invoke($program, $now);

        $tickers = $reflection->getProperty('tickers')->getValue($program);
        self::assertSame($now + $interval, $tickers[0]->fireAt, 'Next fire time must be now + the ticker interval');
    }

    #[Test]
    public function pumpProcessesRetainsRunningProcessesAndDropsStoppedOnes(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());

        $stoppedProcess = new class implements RunningProcess {
            public function pump(): array
            {
                return [];
            }

            public function map(Message $message): Message
            {
                return $message;
            }

            public function isRunning(): bool
            {
                return false;
            }

            public function stop(): void
            {
            }

            public function id(): ?EffectId
            {
                return null;
            }
        };
        $runningProcess = new class implements RunningProcess {
            public function pump(): array
            {
                return [];
            }

            public function map(Message $message): Message
            {
                return $message;
            }

            public function isRunning(): bool
            {
                return true;
            }

            public function stop(): void
            {
            }

            public function id(): ?EffectId
            {
                return null;
            }
        };

        $reflection->getProperty('processes')->setValue($program, [$stoppedProcess, $runningProcess]);

        (new ReflectionMethod(Program::class, 'pumpProcesses'))->invoke($program);

        $remaining = $reflection->getProperty('processes')->getValue($program);
        self::assertCount(1, $remaining, 'Only running processes must remain');
        self::assertSame($runningProcess, $remaining[0], 'IfNegation mutant would invert and retain the stopped process instead');
    }

    #[Test]
    public function registerTimerSchedulesFireAtInTheFuture(): void
    {
        $program = Program::new($this->createMockModel())->withClock(new TestClock(100.0));
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('timers')->setValue($program, []);

        $reflection->getMethod('registerTimer')->invoke($program, 1.0, fn () => null);

        $timers = $reflection->getProperty('timers')->getValue($program);
        self::assertCount(1, $timers);
        self::assertSame(101.0, $timers[0]->fireAt, 'Timer fireAt must be clock now + delay');
    }

    #[Test]
    public function teardownStopsAllProcesses(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);

        $processesProperty = $reflection->getProperty('processes');
        $teardownMethod = $reflection->getMethod('teardown');

        $mockProcess = new class implements RunningProcess {
            public bool $stopped = false;

            public function pump(): array
            {
                return [];
            }

            public function map(Message $message): Message
            {
                return $message;
            }

            public function isRunning(): bool
            {
                return !$this->stopped;
            }

            public function stop(): void
            {
                $this->stopped = true;
            }

            public function id(): ?EffectId
            {
                return null;
            }
        };

        $processesProperty->setValue($program, [$mockProcess]);

        $teardownMethod->invoke($program);

        self::assertTrue($mockProcess->stopped, 'Process should be stopped during teardown');
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
            $terminal->getOutput(),
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
            $terminal->getOutput(),
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
        $reflection->getProperty('height')->setValue($program, 40);

        $reflection->getMethod('teardown')->invoke($program);

        self::assertStringContainsString(
            Ansi::cursorToRowStart(39) . "\n",
            $terminal->getOutput(),
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
            $terminal->getOutput(),
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

        $reflection->getProperty('height')->setValue($program, 99);
        $teardown->invoke($program);

        $sessionHeight = (new ReflectionProperty(TerminalSession::class, 'height'))->getValue($session);
        self::assertSame(24, $sessionHeight, 'teardown() must return before its finally block once already stopped — a second call must not push a new height into the closed session');
    }

    #[Test]
    public function teardownMarksTheProgramAsNotRunning(): void
    {
        $program = Program::new($this->createMockModel());
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('running')->setValue($program, true);

        $reflection->getMethod('teardown')->invoke($program);

        self::assertFalse($reflection->getProperty('running')->getValue($program), 'teardown() must flip running to false so the loop cannot continue after cleanup');
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
            unset($this->processes);
        }, $program, Program::class)();

        try {
            $reflection->getMethod('teardown')->invoke($program);
            self::fail('Teardown was expected to throw when its process-cleanup loop hits the uninitialized property');
        } catch (Error) {
        }

        $output = $terminal->getOutput();
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($countingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $countingModel);
        $reflection->getProperty('running')->setValue($program, true);

        $queue = new SplQueue();
        $queue->enqueue(QuitMsg::Quit);
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertFalse($reflection->getProperty('running')->getValue($program), 'QuitMsg must stop the program');
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $program = Program::new($throwingModel);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('model')->setValue($program, $throwingModel);
        $reflection->getProperty('running')->setValue($program, true);

        $queue = new SplQueue();
        for ($messageIndex = 0; $messageIndex < 10; ++$messageIndex) {
            $queue->enqueue(new TickMsg(0.0));
        }
        $reflection->getProperty('messageQueue')->setValue($program, $queue);

        $reflection->getMethod('drainMessageQueue')->invoke($program);

        self::assertFalse($reflection->getProperty('running')->getValue($program), 'Ten consecutive failures must trip the circuit breaker');
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

            public function render(Frame $frame, RenderContext $context): void
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
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('backBuffer')->setValue($program, null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('renderer is not initialized');

        $reflection->getMethod('renderFrame')->invoke($program);
    }

    #[Test]
    public function httpRequestsAreUnbufferedByDefault(): void
    {
        $capturedOptions = null;
        $program = $this->createHttpCapturingProgram($capturedOptions);

        (new ReflectionMethod(Program::class, 'dispatchCommand'))
            ->invoke($program, Cmd::http('http://example.com/'));

        self::assertIsArray($capturedOptions);
        self::assertFalse($capturedOptions['buffer'], 'Without an explicit option the request must stream unbuffered — RunningHttpRequest pumps chunks itself');
    }

    #[Test]
    public function httpRequestKeepsAnExplicitBufferOption(): void
    {
        $capturedOptions = null;
        $program = $this->createHttpCapturingProgram($capturedOptions);

        (new ReflectionMethod(Program::class, 'dispatchCommand'))
            ->invoke($program, Cmd::http('http://example.com/', options: ['buffer' => true]));

        self::assertIsArray($capturedOptions);
        self::assertTrue($capturedOptions['buffer'], 'An explicit buffer option on the command must not be overwritten by the unbuffered default');
    }

    #[Test]
    public function callsOnShutdownOnTheFinalModelWhenTheProgramStops(): void
    {
        $generations = new ArrayObject();
        $model = new class($generations) implements Model, ShutdownAware {
            public function __construct(private ArrayObject $generations, private int $generation = 0)
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

            public function render(Frame $frame, RenderContext $context): void
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

            public function render(Frame $frame, RenderContext $context): void
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

            public function render(Frame $frame, RenderContext $context): void
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

            public function render(Frame $frame, RenderContext $context): void
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
            'sighupCallbackId',
            $source,
            'The SIGHUP watcher must be cancelled with the others, or a resumed program leaks it',
        );
    }

    private function createRenderablePrgram(Model $model): Program
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($model);
        $reflection = new ReflectionClass($program);
        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('width')->setValue($program, 80);
        $reflection->getProperty('height')->setValue($program, 24);
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('model')->setValue($program, $model);
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));
        $reflection->getProperty('running')->setValue($program, true);
        $reflection->getProperty('isDirty')->setValue($program, true);
        $reflection->getProperty('lastRender')->setValue($program, 0.0);

        return $program;
    }

    private function buildTickableProgram(): Program
    {
        $terminal = new VirtualTerminal(80, 24);
        $program = Program::new($this->createMockModel())->fps(30.0);
        $reflection = new ReflectionClass($program);

        $reflection->getProperty('terminal')->setValue($program, $terminal);
        $reflection->getProperty('screen')->setValue($program, new Screen($terminal));
        $reflection->getProperty('stdinBuffer')->setValue($program, new StdinBuffer(fn () => null));
        $reflection->getProperty('width')->setValue($program, 80);
        $reflection->getProperty('height')->setValue($program, 24);
        $reflection->getProperty('backBuffer')->setValue($program, new Frame(80, 24));
        $reflection->getProperty('renderContext')->setValue($program, new RenderContext(Theme::dark()));
        $reflection->getProperty('messageQueue')->setValue($program, new SplQueue());
        $reflection->getProperty('running')->setValue($program, true);
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
            new \Symfony\Component\HttpClient\MockHttpClient(
                function (string $method, string $url, array $options) use (&$capturedOptions) {
                    $capturedOptions = $options;

                    return new \Symfony\Component\HttpClient\Response\MockResponse('ok');
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

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };
    }
}

final readonly class UnknownTestCmd extends Cmd
{
}
