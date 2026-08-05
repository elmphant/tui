<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\AfterCmd;
use Elmphant\Tui\Core\BatchCmd;
use Elmphant\Tui\Core\CallCmd;
use Elmphant\Tui\Core\CancelCmd;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\ExecCmd;
use Elmphant\Tui\Core\HttpCmd;
use Elmphant\Tui\Core\MappedCmd;
use Elmphant\Tui\Core\MessageCmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\ProcessCmd;
use Elmphant\Tui\Core\QuitCmd;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\RenderRevisioned;
use Elmphant\Tui\Core\ShutdownAware;
use Elmphant\Tui\Core\TickCmd;
use Elmphant\Tui\Input\ColorScheme;
use Elmphant\Tui\Input\InputParser;
use Elmphant\Tui\Input\ReportsOutputStats;
use Elmphant\Tui\Input\StdinBuffer;
use Elmphant\Tui\Input\Terminal;
use Elmphant\Tui\Input\TerminalInterface;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Message\QuitMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Runtime\Trace\Tracer;
use LogicException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Revolt\EventLoop\UnsupportedFeatureException;
use SplQueue;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;
use UnexpectedValueException;

/**
 * @template TModel of Model
 */
final class Program
{
    private const ESCAPE_TIMEOUT = 0.05;
    private const MAX_MESSAGES_PER_DRAIN = 1_024;

    /**
     * A count alone is not enough: one slow update times a thousand queued
     * messages freezes the program, with ctrl+c stuck behind it.
     */
    private const MAX_DRAIN_SECONDS = 0.05;

    private bool $useAltScreen = false;
    private bool $useMouseTracking = false;
    private float $framesPerSecond = 60.0;
    /**
     * @var (Closure(ErrorMsg): (Cmd|null))|null
     */
    private ?Closure $errorHandler = null;
    private ?Tracer $tracer = null;

    private TerminalInterface $terminal;
    private Screen $screen;
    /**
     * @var SplQueue<Message>
     */
    private SplQueue $messageQueue;
    private Model $model;
    private ?Frame $backBuffer = null;
    private bool $running = false;
    private bool $isDirty = true;
    private bool $resizePending = false;
    private bool $resizeNeedsScreenClear = false;
    private int $width = 80;
    private int $height = 24;
    private Rect $screenRect;
    private ProgramState $state = ProgramState::New;
    private ?TerminalSession $terminalSession = null;

    /**
     * @var list<RunningProcess>
     */
    private array $processes = [];

    private ?HttpClientInterface $httpClient = null;

    /**
     * @var list<TickerRecord>
     */
    private array $tickers = [];

    /**
     * @var list<TimerRecord>
     */
    private array $timers = [];

    private ?TerminalInterface $injectedTerminal = null;
    private ?AdaptiveTicker $adaptiveTicker = null;
    private ?float $animationRepaintAt = null;
    private StdinBuffer $stdinBuffer;
    private ?string $escFlushTimerId = null;
    private ?string $sigtstpCallbackId = null;
    private ?string $sigwinchCallbackId = null;
    private ?string $sigintCallbackId = null;
    private ?string $sigtermCallbackId = null;
    private ?string $sighupCallbackId = null;
    private ?string $sigcontCallbackId = null;
    private ?string $readableCallbackId = null;
    /**
     * @var Suspension<mixed>|null
     */
    private ?Suspension $runSuspension = null;
    private bool $ticking = false;
    private ?Theme $theme = null;
    private ?RenderContext $renderContext = null;
    private float $lastRender = 0.0;
    private float $paintedAt = 0.0;
    private Clock $clock;

    /**
     * @param TModel $initialModel
     */
    private function __construct(private readonly Model $initialModel)
    {
        $this->clock = new MonotonicClock();
        $this->screenRect = Rect::fromSize($this->width, $this->height);
    }

    /**
     * @template T of Model
     *
     * @param T $model
     *
     * @return T
     */
    public static function run(Model $model): Model
    {
        /** @var T */
        return self::new($model)->start();
    }

    /**
     * @template T of Model
     *
     * @param T $model
     *
     * @return self<T>
     */
    public static function new(Model $model): self
    {
        return new self($model);
    }

    public function state(): ProgramState
    {
        return $this->state;
    }

    /**
     * @return self<TModel>
     */
    public function altScreen(): self
    {
        $this->assertConfigurable();
        $this->useAltScreen = true;

        return $this;
    }

    /**
     * @return self<TModel>
     */
    public function mouseTracking(): self
    {
        $this->assertConfigurable();
        $this->useMouseTracking = true;

        return $this;
    }

    /**
     * @return self<TModel>
     */
    public function fps(float $framesPerSecond): self
    {
        $this->assertConfigurable();
        $this->framesPerSecond = max(1.0, $framesPerSecond);

        return $this;
    }

    /**
     * @param Closure(ErrorMsg): (Cmd|null) $handler
     *
     * @return self<TModel>
     */
    public function onError(Closure $handler): self
    {
        $this->assertConfigurable();
        $this->errorHandler = $handler;

        return $this;
    }

    /**
     * @return self<TModel>
     */
    public function withTerminal(TerminalInterface $terminal): self
    {
        $this->assertConfigurable();
        $this->injectedTerminal = $terminal;

        return $this;
    }

    /**
     * @return self<TModel>
     */
    public function withTheme(Theme $theme): self
    {
        $this->assertConfigurable();
        $this->theme = $theme;

        return $this;
    }

    /**
     * @return self<TModel>
     */
    public function withClock(Clock $clock): self
    {
        $this->assertConfigurable();
        $this->clock = $clock;

        return $this;
    }

    /**
     * @return self<TModel>
     */
    public function withTracer(Tracer $tracer): self
    {
        $this->assertConfigurable();
        $this->tracer = $tracer;

        return $this;
    }

    /**
     * @return TModel
     */
    public function start(): Model
    {
        if ($this->state !== ProgramState::New) {
            throw new LogicException(sprintf('Program can only be started once; current state is %s', $this->state->name));
        }
        $this->state = ProgramState::Running;

        try {
            $this->terminal = $this->injectedTerminal ?? new Terminal();
            $this->screen = new Screen($this->terminal, $this->terminal->colorProfile());
            $this->messageQueue = new SplQueue();
            $this->model = $this->initialModel;
            $this->stdinBuffer = new StdinBuffer(
                onSequence: $this->handleInputSequence(...),
                onPaste: function (string $content): void {
                    $this->messageQueue->enqueue(new PasteMsg($content));
                },
            );
            $this->setup();
            $this->renderContext = new RenderContext($this->resolveTheme(), $this->terminal->colorProfile());
            $this->running = true;
            $this->dispatchCommand($this->model->init());
            if ($this->running) {
                $this->loop();
            }
            if ($this->model instanceof ShutdownAware) {
                $this->model->onShutdown();
            }
        } finally {
            try {
                if ($this->adaptiveTicker !== null) {
                    $this->adaptiveTicker->stop();
                    $this->adaptiveTicker = null;
                }
            } finally {
                $this->teardown();
            }
        }

        /** @var TModel */
        return $this->model;
    }

    public function tick(): void
    {
        if ($this->ticking) {
            return;
        }
        $this->ticking = true;
        try {
            $this->runTick();
        } finally {
            $this->ticking = false;
        }
    }

    private function assertConfigurable(): void
    {
        if ($this->state !== ProgramState::New) {
            throw new LogicException(sprintf('Program cannot be configured in state %s', $this->state->name));
        }
    }

    private function setup(): void
    {
        $this->terminalSession = new TerminalSession($this->terminal, $this->useAltScreen, $this->useMouseTracking);
        $this->terminalSession->start();
        $this->terminal->queryKittyProtocol();

        // Size queries which ask the terminal for a cursor-position report race
        // application input. Runtime startup therefore uses only the local
        // stty/environment path; probeSize() remains an explicit low-level API.
        [$this->width, $this->height] = $this->terminal->size();
        $this->terminalSession->updateHeight($this->height);
        $this->backBuffer = new Frame($this->width, $this->height);

        $this->registerSignalHandlers();

        $this->messageQueue->enqueue(new ResizeMsg($this->width, $this->height));
    }

    private function registerSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            // SIGWINCH etc. are pcntl constants; without the extension the app
            // still runs — resize detection and Ctrl-Z handling are degraded.
            return;
        }

        try {
            $this->sigwinchCallbackId = EventLoop::onSignal(SIGWINCH, function () {
                $this->resizePending = true;
                EventLoop::defer(fn () => $this->tick());
            });

            $this->sigintCallbackId = EventLoop::onSignal(SIGINT, function () {
                $this->messageQueue->enqueue(QuitMsg::Quit);
                EventLoop::defer(fn () => $this->tick());
            });
            $this->sigtermCallbackId = EventLoop::onSignal(SIGTERM, function () {
                $this->messageQueue->enqueue(QuitMsg::Quit);
                EventLoop::defer(fn () => $this->tick());
            });
            // Closing the terminal window would otherwise kill the process
            // outright, skipping both the shutdown hook and terminal restore.
            $this->sighupCallbackId = EventLoop::onSignal(SIGHUP, function () {
                $this->messageQueue->enqueue(QuitMsg::Quit);
                EventLoop::defer(fn () => $this->tick());
            });

            if (function_exists('posix_kill')) {
                $this->sigtstpCallbackId = EventLoop::onSignal(SIGTSTP, function () {
                    $this->suspend();
                });
            }

            $this->sigcontCallbackId = EventLoop::onSignal(SIGCONT, function () {
                $this->resume();
                EventLoop::defer(fn () => $this->tick());
            });
        } catch (UnsupportedFeatureException) {
            // The active EventLoop driver cannot watch signals; keep running
            // with whatever watchers were registered before the throw.
        }
    }

    private function suspend(): void
    {
        if ($this->sigtstpCallbackId !== null) {
            EventLoop::cancel($this->sigtstpCallbackId);
            $this->sigtstpCallbackId = null;
        }

        $this->restoreTerminalForSuspension();

        if (function_exists('posix_kill') && function_exists('pcntl_signal')) {
            pcntl_signal(SIGTSTP, SIG_DFL);
            // SIGTSTP can be discarded for an orphaned process group (for
            // example under a non-interactive supervisor). The terminal has
            // already been restored, so use the uncatchable stop signal to
            // guarantee the process actually suspends until SIGCONT.
            posix_kill(posix_getpid(), SIGSTOP);
        }
    }

    private function restoreTerminalForSuspension(): void
    {
        $this->terminalSession?->suspend();
    }

    private function resume(): void
    {
        $this->terminalSession?->resume();

        $this->sigtstpCallbackId = EventLoop::onSignal(SIGTSTP, fn () => $this->suspend());

        [$this->width, $this->height] = $this->terminal->size();
        $this->terminalSession?->updateHeight($this->height);
        $this->backBuffer = new Frame($this->width, $this->height);
        $this->screen->clear();
        $this->isDirty = true;
        $this->messageQueue->enqueue(new ResizeMsg($this->width, $this->height));
    }

    private function cancelEventLoopWatchers(): void
    {
        foreach (['sigwinchCallbackId', 'sigintCallbackId', 'sigtermCallbackId', 'sighupCallbackId', 'sigcontCallbackId', 'sigtstpCallbackId', 'readableCallbackId', 'escFlushTimerId'] as $property) {
            if ($this->$property !== null) {
                $id = $this->$property;
                $this->$property = null;
                try {
                    EventLoop::cancel($id);
                } catch (Throwable) {
                    // A stale watcher must not prevent process or terminal cleanup.
                }
            }
        }
    }

    private function teardown(): void
    {
        if ($this->state === ProgramState::Stopped) {
            return;
        }
        $this->state = ProgramState::Stopped;
        $this->running = false;

        try {
            $this->cancelEventLoopWatchers();

            foreach ($this->processes as $process) {
                try {
                    $process->stop();
                } catch (Throwable) {
                }
            }
            $this->processes = [];
        } finally {
            $this->terminalSession?->updateHeight($this->height);
            $this->terminalSession?->close();
        }
    }

    private function resolveTheme(): Theme
    {
        if ($this->theme !== null) {
            return $this->theme;
        }

        $colorScheme = $this->terminal->detectColorScheme();

        return match ($colorScheme) {
            ColorScheme::Light => Theme::light(),
            default => Theme::dark(),
        };
    }

    private function loop(): void
    {
        $this->adaptiveTicker = new AdaptiveTicker($this->tick(...));
        $this->adaptiveTicker->refresh(
            nextRenderDelay: $this->nextRenderDelay(),
            nextTimerDelay: $this->nextTimerDelay(),
            processesActive: $this->processes !== [],
            queuePending: !$this->messageQueue->isEmpty(),
        );

        $inputStream = $this->terminal->inputStream();
        if ($inputStream !== null) {
            $this->readableCallbackId = EventLoop::onReadable($inputStream, function () {
                $this->tick();
            });
        }

        // Park on a Suspension instead of EventLoop::run(): this composes with
        // a host application that already drives the Revolt loop (nested run()
        // is fatal) and quitting resumes only this fiber instead of stopping
        // the shared driver out from under the host's other watchers.
        $this->runSuspension = EventLoop::getSuspension();
        try {
            $this->runSuspension->suspend();
        } finally {
            $this->runSuspension = null;
        }
    }

    private function resumeRunSuspension(): void
    {
        if ($this->runSuspension === null) {
            return;
        }

        $suspension = $this->runSuspension;
        $this->runSuspension = null;
        $suspension->resume();
    }

    /**
     * @internal event-loop callback; applications drive the program with start()
     */
    private function handleInputSequence(string $sequence): void
    {
        if (str_starts_with($sequence, "\x1b[?") && str_ends_with($sequence, 'u')) {
            $this->terminalSession?->enableKittyProtocol();

            return;
        }

        $message = InputParser::parseSequence($sequence);
        if ($message !== null) {
            $this->enqueueInputMessage($message);
        }
    }

    /**
     * Scrolling the other way cancels whatever is still queued: applying it
     * would move the view further from where the user just asked it to go.
     */
    private function enqueueInputMessage(Message $message): void
    {
        if (!$message instanceof MouseMsg || self::wheelDirection($message) === null) {
            $this->messageQueue->enqueue($message);

            return;
        }

        $this->dropQueuedWheeling(self::opposite($message));

        $last = $this->messageQueue->count() - 1;
        $tail = $last < 0 ? null : $this->messageQueue[$last];

        if ($tail instanceof MouseMsg && $tail->action === $message->action) {
            $this->messageQueue[$last] = $tail->mergedWith($message);

            return;
        }

        $this->messageQueue->enqueue($message);
    }

    private function dropQueuedWheeling(MouseAction $stale): void
    {
        $queued = $this->messageQueue->count();

        for ($position = 0; $position < $queued; ++$position) {
            $message = $this->messageQueue->dequeue();

            if ($message instanceof MouseMsg && $message->action === $stale) {
                continue;
            }

            $this->messageQueue->enqueue($message);
        }
    }

    private static function wheelDirection(MouseMsg $message): ?MouseAction
    {
        return match ($message->action) {
            MouseAction::WheelUp, MouseAction::WheelDown => $message->action,
            default => null,
        };
    }

    private static function opposite(MouseMsg $message): MouseAction
    {
        return $message->action === MouseAction::WheelUp
            ? MouseAction::WheelDown
            : MouseAction::WheelUp;
    }

    /**
     * Deliver input held back for escape-sequence disambiguation.
     *
     * The flush happens on a timer, with the loop otherwise idle, so it has to
     * tick as well: without that the keystroke waits in the queue until the
     * next one arrives, and every lone Escape takes effect one keypress late.
     */
    private function flushPendingInput(): void
    {
        $this->escFlushTimerId = null;
        $this->stdinBuffer->flush();
        $this->tick();
    }

    private function runTick(): void
    {
        $this->handlePendingResize();
        $newInput = $this->terminal->readInput();
        if ($newInput !== '' && $this->tracer !== null) {
            $this->tracer->record(TraceEvent::InputRead, [
                'bytes' => strlen($newInput),
                'queued' => $this->messageQueue->count(),
            ]);
        }
        if ($newInput !== '') {
            if ($this->escFlushTimerId !== null) {
                EventLoop::cancel($this->escFlushTimerId);
                $this->escFlushTimerId = null;
            }
            $this->stdinBuffer->process($newInput);
            $this->escFlushTimerId = EventLoop::delay(self::ESCAPE_TIMEOUT, $this->flushPendingInput(...));
        }

        $now = $this->clock->now();
        $this->fireTickers($now);
        $this->fireTimers($now);
        $this->pumpProcesses();

        $this->drainMessageQueue();
        if (!$this->running) {
            $this->resumeRunSuspension();

            return;
        }

        $this->handlePendingResize();
        if (!$this->messageQueue->isEmpty()) {
            $this->drainMessageQueue();
            if (!$this->running) {
                $this->resumeRunSuspension();

                return;
            }
        }

        $frameBudget = 1.0 / $this->framesPerSecond;
        $now = $this->clock->now();
        $animationDue = $this->animationRepaintAt !== null && $now >= $this->animationRepaintAt;
        if (($this->isDirty || $animationDue) && $now >= $this->lastRender + $frameBudget) {
            $this->tracedRenderFrame();
            $this->lastRender = $this->clock->now();
        } elseif ($this->tracer !== null && ($this->isDirty || $animationDue)) {
            $this->tracer->record(TraceEvent::FrameSkipped, [
                'waitMs' => ($this->lastRender + $frameBudget - $now) * 1000,
            ]);
        }

        $adaptiveTicker = $this->adaptiveTicker;
        if ($adaptiveTicker === null) {
            throw new LogicException('Program ticker is not initialized');
        }
        $adaptiveTicker->refresh(
            nextRenderDelay: $this->nextRenderDelay(),
            nextTimerDelay: $this->nextTimerDelay(),
            processesActive: $this->processes !== [],
            queuePending: !$this->messageQueue->isEmpty(),
        );
    }

    private function nextTimerDelay(): ?float
    {
        $now = $this->clock->now();
        $earliest = null;

        foreach ($this->tickers as $ticker) {
            $delay = max(0.0, $ticker->fireAt - $now);
            $earliest = $earliest === null ? $delay : min($earliest, $delay);
        }

        foreach ($this->timers as $timer) {
            $delay = max(0.0, $timer->fireAt - $now);
            $earliest = $earliest === null ? $delay : min($earliest, $delay);
        }

        return $earliest;
    }

    private function nextRenderDelay(): ?float
    {
        $budgetReadyAt = $this->lastRender + (1.0 / $this->framesPerSecond);

        $nextRenderAt = null;
        if ($this->isDirty) {
            $nextRenderAt = $budgetReadyAt;
        }
        if ($this->animationRepaintAt !== null) {
            $animationReadyAt = max($this->animationRepaintAt, $budgetReadyAt);
            $nextRenderAt = $nextRenderAt === null ? $animationReadyAt : min($nextRenderAt, $animationReadyAt);
        }

        if ($nextRenderAt === null) {
            return null;
        }

        return max(0.0, $nextRenderAt - $this->clock->now());
    }

    private function handlePendingResize(): void
    {
        if (!$this->resizePending) {
            return;
        }
        $this->resizePending = false;
        $this->resizeNeedsScreenClear = true;
        [$this->width, $this->height] = $this->terminal->size();
        $this->terminalSession?->updateHeight($this->height);
        $this->backBuffer = new Frame($this->width, $this->height);
        $this->messageQueue->enqueue(new ResizeMsg($this->width, $this->height));
        $this->isDirty = true;
    }

    private function tracedRenderFrame(): void
    {
        if ($this->tracer === null) {
            if ($this->renderFrame()) {
                $this->isDirty = false;
            }

            return;
        }

        $stats = $this->terminal instanceof ReportsOutputStats ? $this->terminal : null;
        $bytesBefore = $stats?->bytesWritten() ?? 0;
        $waitBefore = $stats?->writeWaitSeconds() ?? 0.0;
        $start = $this->clock->now();
        $this->paintedAt = $start;

        $rendered = $this->renderFrame();
        if ($rendered) {
            $this->isDirty = false;
        }

        $finished = $this->clock->now();

        $this->tracer->record(TraceEvent::Render, [
            'ms' => ($finished - $start) * 1000,
            'paintMs' => ($this->paintedAt - $start) * 1000,
            'diffMs' => ($finished - $this->paintedAt) * 1000,
            'bytes' => ($stats?->bytesWritten() ?? 0) - $bytesBefore,
            'blockedMs' => (($stats?->writeWaitSeconds() ?? 0.0) - $waitBefore) * 1000,
            'drawn' => $rendered,
        ]);
    }

    private function drainMessageQueue(): void
    {
        $consecutiveErrors = 0;
        $screenRect = $this->screenRect();
        $processed = 0;
        $deadline = $this->clock->now() + self::MAX_DRAIN_SECONDS;

        while ($this->running && $processed < self::MAX_MESSAGES_PER_DRAIN && !$this->messageQueue->isEmpty()) {
            if ($processed > 0 && $this->clock->now() >= $deadline) {
                return;
            }

            ++$processed;
            $message = $this->messageQueue->dequeue();

            if ($message === QuitMsg::Quit) {
                $this->running = false;

                return;
            }

            if ($message instanceof ErrorMsg && $this->errorHandler !== null) {
                ++$consecutiveErrors;
                if ($consecutiveErrors >= 10) {
                    $this->running = false;

                    return;
                }
                try {
                    $errorCommand = ($this->errorHandler)($message);
                    if ($errorCommand instanceof Cmd) {
                        $this->dispatchCommand($errorCommand);
                    }
                } catch (Throwable) {
                    // A broken error handler must not take down the loop; the
                    // consecutive-error counter above still bounds repeated failures.
                }
                continue;
            }

            try {
                $updateStart = $this->tracer === null ? 0 : hrtime(true);
                $stamped = InputRouter::route($message, $screenRect, $this->renderContext?->floatPlacements() ?? []);
                $result = $this->model->update($stamped, $screenRect);
                $nextModel = self::requireModel($result->model);
                if ($this->tracer !== null) {
                    $this->tracer->record(TraceEvent::Update, [
                        'message' => $stamped::class,
                        'ms' => (hrtime(true) - $updateStart) / 1e6,
                        'changed' => $nextModel !== $this->model,
                    ]);
                }
            } catch (Throwable $error) {
                ++$consecutiveErrors;
                if ($consecutiveErrors >= 10) {
                    $this->running = false;

                    return;
                }
                $this->messageQueue->enqueue(new ErrorMsg($error, 'update'));
                continue;
            }

            $consecutiveErrors = 0;
            if ($nextModel !== $this->model) {
                $previousRevision = $this->model instanceof RenderRevisioned ? $this->model->renderRevision() : null;
                $this->model = $nextModel;
                $newRevision = $this->model instanceof RenderRevisioned ? $this->model->renderRevision() : null;
                $bothRevisioned = $previousRevision !== null && $newRevision !== null;
                $revisionUnchanged = $bothRevisioned && $previousRevision === $newRevision;
                if (!$revisionUnchanged) {
                    $this->isDirty = true;
                }
            }
            $this->dispatchCommand($result->command);
        }
    }

    private function screenRect(): Rect
    {
        if ($this->screenRect->width !== $this->width || $this->screenRect->height !== $this->height) {
            $this->screenRect = Rect::fromSize($this->width, $this->height);
        }

        return $this->screenRect;
    }

    private static function requireModel(Reducer $model): Model
    {
        if (!$model instanceof Model) {
            throw new UnexpectedValueException('Model::update() must return a Model in UpdateResult');
        }

        return $model;
    }

    private function renderFrame(): bool
    {
        $frame = $this->backBuffer;
        $renderContext = $this->renderContext;
        if ($frame === null || $renderContext === null) {
            throw new LogicException('Program renderer is not initialized');
        }

        if ($frame->width !== $this->width || $frame->height !== $this->height) {
            $frame = $this->backBuffer = new Frame($this->width, $this->height);
        }

        if ($this->resizeNeedsScreenClear) {
            $this->resizeNeedsScreenClear = false;
            $this->screen->clear();
        }

        $baseBackground = $renderContext->theme->base->backgroundValue();
        $frame->clear($baseBackground);

        $renderContext->beginFrame($this->clock->now());

        try {
            $this->model->render($frame, $renderContext);
        } catch (Throwable $error) {
            $this->animationRepaintAt = null;
            $this->messageQueue->enqueue(new ErrorMsg($error, 'render'));

            return false;
        }

        $this->paintedAt = $this->clock->now();
        $this->screen->flush($frame);
        $renderContext->commitFloatPlacements();

        $repaintDelay = $renderContext->repaintDelay();
        $this->animationRepaintAt = $repaintDelay === null
            ? null
            : $this->clock->now() + $repaintDelay;

        return true;
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    private function dispatchCommand(?Cmd $command, ?Closure $wrap = null): void
    {
        if ($command === null) {
            return;
        }

        if ($this->tracer !== null) {
            $this->tracer->record(TraceEvent::Command, ['command' => $command::class]);
        }

        if ($command instanceof MappedCmd) {
            $this->dispatchCommand($command->inner, self::composeWrap($command->wrap, $wrap));

            return;
        }

        if ($command instanceof BatchCmd) {
            foreach ($command->commands as $child) {
                $this->dispatchCommand($child, $wrap);
            }

            return;
        }

        $this->makeRoomFor($command, $wrap);

        match (true) {
            $command instanceof QuitCmd => $this->running = false,
            $command instanceof TickCmd => $this->registerTicker($command->interval, $command->id, $wrap),
            $command instanceof AfterCmd => $this->registerTimer($command->delay, $command->then, $command->id, $wrap),
            $command instanceof CancelCmd => $this->cancelEffect($command->id, $wrap),
            $command instanceof MessageCmd => $this->messageQueue->enqueue(self::mapMessage($command->message, $wrap)),
            $command instanceof CallCmd => $this->executeCall($command, $wrap),
            $command instanceof HttpCmd => $this->startHttpRequest($command, $wrap),
            $command instanceof ExecCmd => $this->startExecProcess($command, $wrap),
            $command instanceof ProcessCmd => $this->startStreamingProcess($command, $wrap),
            default => throw new LogicException(sprintf('Unknown command type "%s" — the runtime has no dispatcher for it', $command::class)),
        };
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    private function executeCall(CallCmd $command, ?Closure $wrap = null): void
    {
        try {
            $result = ($command->callback)();
        } catch (Throwable $error) {
            $this->messageQueue->enqueue(self::mapMessage(new ErrorMsg($error, 'call'), $wrap));

            return;
        }

        if ($result instanceof Cmd) {
            $this->dispatchCommand($result, $wrap);
        } elseif ($result instanceof Message) {
            $this->messageQueue->enqueue(self::mapMessage($result, $wrap));
        } elseif ($result !== null) {
            $this->messageQueue->enqueue(self::mapMessage(new ErrorMsg(
                new UnexpectedValueException('Cmd::call() callback must return Message, Cmd, or null'),
                'call',
            ), $wrap));
        }
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    private function registerTicker(float $interval, ?EffectId $id = null, ?Closure $wrap = null): void
    {
        $now = $this->clock->now();
        $this->tickers[] = new TickerRecord($now + $interval, $interval, $id, $wrap);
    }

    /**
     * @param Closure(): (Message|Cmd|null)  $then
     * @param Closure(Message): Message|null $wrap
     */
    private function registerTimer(float $delay, Closure $then, ?EffectId $id = null, ?Closure $wrap = null): void
    {
        $this->timers[] = new TimerRecord($this->clock->now() + $delay, $then, $id, $wrap);
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    /**
     * An id is one slot: whatever still holds it goes before the replacement
     * starts. Two effects answering to the same name would send messages no
     * application can tell apart, and a ticker re-registered on every config
     * change would silently multiply.
     *
     * @param Closure(Message): Message|null $wrap
     */
    private function makeRoomFor(Cmd $command, ?Closure $wrap): void
    {
        $id = match (true) {
            $command instanceof CancelCmd => null,
            $command instanceof TickCmd,
            $command instanceof AfterCmd,
            $command instanceof HttpCmd,
            $command instanceof ExecCmd,
            $command instanceof ProcessCmd => $command->id,
            default => null,
        };

        if ($id !== null) {
            $this->cancelEffect($id, $wrap);
        }
    }

    private function cancelEffect(EffectId $id, ?Closure $wrap = null): void
    {
        $this->tickers = array_values(array_filter(
            $this->tickers,
            static fn (TickerRecord $ticker): bool => $ticker->id === null || !$ticker->id->equals($id),
        ));
        $this->timers = array_values(array_filter(
            $this->timers,
            static fn (TimerRecord $timer): bool => $timer->id === null || !$timer->id->equals($id),
        ));

        $remaining = [];
        foreach ($this->processes as $process) {
            $processId = $process->id();
            if ($processId !== null && $processId->equals($id)) {
                try {
                    $process->stop();
                } catch (Throwable $error) {
                    $this->messageQueue->enqueue(self::mapMessage(new ErrorMsg($error, 'cancel', $id), $wrap));
                }
                continue;
            }
            $remaining[] = $process;
        }
        $this->processes = $remaining;
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    private function startHttpRequest(HttpCmd $command, ?Closure $wrap = null): void
    {
        $this->httpClient ??= HttpClient::create();

        $options = $command->options;
        $options['buffer'] ??= false;
        if ($command->headers !== []) {
            $options['headers'] = $command->headers;
        }
        if ($command->body !== null) {
            $options['body'] = $command->body;
        }

        try {
            $response = $this->httpClient->request($command->method, $command->url, $options);
        } catch (Throwable $error) {
            $this->messageQueue->enqueue(self::mapMessage(new ErrorMsg($error, 'http', $command->id), $wrap));

            return;
        }

        $this->processes[] = new RunningHttpRequest(
            $this->httpClient,
            $response,
            $command->id,
            $command->maxBodyBytes,
            $wrap,
        );
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    private function startExecProcess(ExecCmd $command, ?Closure $wrap = null): void
    {
        try {
            $process = $this->createProcess($command->command);
            $this->configureProcess($process, $command->workingDirectory, $command->timeout, $command->env, $command->input);
            $process->start();
        } catch (Throwable $error) {
            $this->messageQueue->enqueue(self::mapMessage(new ErrorMsg($error, 'process', $command->id), $wrap));

            return;
        }

        $this->processes[] = new RunningExecProcess($process, $command->id, $command->maxOutputBytes, $wrap);
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    private function startStreamingProcess(ProcessCmd $command, ?Closure $wrap = null): void
    {
        try {
            $process = $this->createProcess($command->command);
            $this->configureProcess($process, $command->workingDirectory, $command->timeout, $command->env, $command->input);
            $process->start();
        } catch (Throwable $error) {
            $this->messageQueue->enqueue(self::mapMessage(new ErrorMsg($error, 'process', $command->id), $wrap));

            return;
        }

        $this->processes[] = new RunningStreamingProcess($process, $command->id, $command->outputChunkBytes, $wrap);
    }

    /**
     * @param list<string> $command
     */
    private function createProcess(array $command): Process
    {
        return new Process($command);
    }

    /**
     * @param array<string, string> $env
     */
    private function configureProcess(
        Process $process,
        ?string $workingDirectory,
        ?float $timeout,
        array $env,
        ?string $input,
    ): void {
        if ($workingDirectory !== null) {
            $process->setWorkingDirectory($workingDirectory);
        }
        if ($timeout !== null) {
            $process->setTimeout($timeout);
        }
        if ($env !== []) {
            $process->setEnv($env);
        }
        if ($input !== null) {
            $process->setInput($input);
        }
    }

    private function pumpProcesses(): void
    {
        if ($this->processes === []) {
            return;
        }

        $remaining = [];
        foreach ($this->processes as $process) {
            try {
                foreach ($process->pump() as $message) {
                    $this->messageQueue->enqueue($message);
                }
            } catch (Throwable $error) {
                $this->messageQueue->enqueue($process->map(new ErrorMsg($error, 'effect', $process->id())));
                try {
                    $process->stop();
                } catch (Throwable) {
                }
                continue;
            }
            if ($process->isRunning()) {
                $remaining[] = $process;
            }
        }
        $this->processes = $remaining;
    }

    private function fireTickers(float $now): void
    {
        foreach ($this->tickers as $index => $ticker) {
            if ($ticker->fireAt <= $now) {
                $this->messageQueue->enqueue(self::mapMessage(new TickMsg($now), $ticker->wrap));
                $this->tickers[$index] = $ticker->rescheduled($now);
            }
        }
    }

    private function fireTimers(float $now): void
    {
        if ($this->timers === []) {
            return;
        }

        $remaining = [];
        foreach ($this->timers as $timer) {
            if ($now >= $timer->fireAt) {
                try {
                    $result = ($timer->callback)();
                } catch (Throwable $error) {
                    $this->messageQueue->enqueue(self::mapMessage(new ErrorMsg($error, 'timer', $timer->id), $timer->wrap));
                    continue;
                }
                if ($result instanceof Cmd) {
                    $this->dispatchCommand($result, $timer->wrap);
                } elseif ($result instanceof Message) {
                    $this->messageQueue->enqueue(self::mapMessage($result, $timer->wrap));
                } elseif ($result !== null) {
                    $this->messageQueue->enqueue(self::mapMessage(new ErrorMsg(
                        new UnexpectedValueException('Cmd::after() callback must return Message, Cmd, or null'),
                        'timer',
                        $timer->id,
                    ), $timer->wrap));
                }
            } else {
                $remaining[] = $timer;
            }
        }
        $this->timers = $remaining;
    }

    /**
     * Compose an inner map with an optional outer map.
     *
     * @param Closure(Message): Message      $inner
     * @param Closure(Message): Message|null $outer
     *
     * @return Closure(Message): Message
     */
    private static function composeWrap(Closure $inner, ?Closure $outer): Closure
    {
        if ($outer === null) {
            return $inner;
        }

        return static fn (Message $message): Message => $outer($inner($message));
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    private static function mapMessage(Message $message, ?Closure $wrap): Message
    {
        return $wrap === null ? $message : $wrap($message);
    }
}
