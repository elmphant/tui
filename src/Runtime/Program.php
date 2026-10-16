<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Cmd\CallCmd;
use Elmphant\Tui\Core\Cmd\ClipboardCmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\Cmd\LogCmd;
use Elmphant\Tui\Core\Cmd\MappedCmd;
use Elmphant\Tui\Core\Cmd\MessageCmd;
use Elmphant\Tui\Core\Cmd\QuitCmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Input\InputParser;
use Elmphant\Tui\Input\KeyPattern;
use Elmphant\Tui\Input\StdinBuffer;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\FocusMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Message\QuitMsg;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\Trace\JsonlTracer;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Runtime\Trace\Tracer;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\ColorProfile;
use Elmphant\Tui\Terminal\ColorScheme;
use Elmphant\Tui\Terminal\ReportsOutputStats;
use Elmphant\Tui\Terminal\SystemTerminal;
use Elmphant\Tui\Terminal\Terminal;
use Elmphant\Tui\Terminal\TerminalWriteException;
use LogicException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Revolt\EventLoop\UnsupportedFeatureException;
use SplQueue;
use Throwable;
use UnexpectedValueException;
use WeakMap;
use WeakReference;

/**
 * @template TModel of \Elmphant\Tui\Runtime\Model
 */
final class Program
{
    private const MAX_MESSAGES_PER_DRAIN = 1_024;
    private const MAX_CONSECUTIVE_ERRORS = 10;

    /**
     * A count alone is not enough: one slow update times a thousand queued
     * messages freezes the program, with ctrl+c stuck behind it.
     */
    private const MAX_DRAIN_SECONDS = 0.05;

    private bool $useAltScreen = false;
    private bool $useMouseTracking = false;
    private FrameScheduler $frames;
    /**
     * @var (Closure(ErrorMsg): (Cmd|null))|null
     */
    private ?Closure $errorHandler = null;
    private ?Tracer $tracer = null;

    /**
     * @var list<CommandHandler>
     */
    private array $commandHandlers = [];

    private Terminal $terminal;
    private Screen $screen;
    /**
     * @var SplQueue<Message>
     */
    private SplQueue $messageQueue;
    /**
     * @var WeakMap<Message, int>
     */
    private WeakMap $errorChain;
    private PendingWheel $pendingWheel;
    private float $lastWheelFlush = 0.0;
    private Model $model;
    private ?Frame $backBuffer = null;
    private bool $inputClosed = false;
    private int $consecutiveRenderFailures = 0;
    private ?Throwable $fatalError = null;
    private ?float $drainDeadline = null;
    private bool $resizePending = false;
    private bool $resizeNeedsScreenClear = false;
    private Rect $screenRect;
    private ProgramState $state = ProgramState::New;
    private ?TerminalSession $terminalSession = null;

    /**
     * Built on first use: an effect can only start once something dispatched a
     * command, and a program that never does keeps none.
     */
    private ?EffectRunner $effects = null;

    private ?Terminal $injectedTerminal = null;
    private ?AdaptiveTicker $adaptiveTicker = null;
    private StdinBuffer $stdinBuffer;
    /**
     * @var array<string, string> loop watcher ids, keyed by EventLoopWatcher name
     */
    private array $watchers = [];
    /**
     * @var Suspension<mixed>|null
     */
    private ?Suspension $runSuspension = null;
    private bool $ticking = false;
    private ?Theme $theme = null;
    private ?ColorProfile $colorProfile = null;
    private ?RenderPass $renderPass = null;
    private float $paintedAt = 0.0;
    private Clock $clock;
    private FocusState $focus;

    /**
     * @param TModel $initialModel
     */
    private function __construct(private readonly Model $initialModel)
    {
        $this->focus = new FocusState();
        $this->frames = new FrameScheduler();
        $this->clock = new MonotonicClock();
        $this->screenRect = Rect::fromSize(80, 24);
        $this->pendingWheel = new PendingWheel();
        $this->errorChain = new WeakMap();
    }

    /**
     * @template T of \Elmphant\Tui\Runtime\Model
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
     * @template T of \Elmphant\Tui\Runtime\Model
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
    public function withAltScreen(bool $enabled = true): self
    {
        $program = $this->configured();
        $program->useAltScreen = $enabled;

        return $program;
    }

    /**
     * @return self<TModel>
     */
    public function withMouseTracking(bool $enabled = true): self
    {
        $program = $this->configured();
        $program->useMouseTracking = $enabled;

        return $program;
    }

    /**
     * @return self<TModel>
     */
    public function withFps(float $framesPerSecond): self
    {
        $program = $this->configured();
        $program->frames = new FrameScheduler($framesPerSecond);

        return $program;
    }

    /**
     * @param Closure(ErrorMsg): (Cmd|null) $handler
     *
     * @return self<TModel>
     */
    public function onError(Closure $handler): self
    {
        $program = $this->configured();
        $program->errorHandler = $handler;

        return $program;
    }

    /**
     * @return self<TModel>
     */
    public function withTerminal(Terminal $terminal): self
    {
        $program = $this->configured();
        $program->injectedTerminal = $terminal;

        return $program;
    }

    /**
     * The colour depth to draw for, where the environment cannot be asked. A
     * terminal reached over a shell that drops COLORTERM reports far less than
     * it can show, and every colour is quantised to match what it admitted to.
     *
     * @return self<TModel>
     */
    public function withColorProfile(ColorProfile $profile): self
    {
        $program = $this->configured();
        $program->colorProfile = $profile;

        return $program;
    }

    /**
     * @return self<TModel>
     */
    public function withTheme(Theme $theme): self
    {
        $program = $this->configured();
        $program->theme = $theme;

        return $program;
    }

    /**
     * @return self<TModel>
     */
    public function withClock(Clock $clock): self
    {
        $program = $this->configured();
        $program->clock = $clock;

        return $program;
    }

    /**
     * @return self<TModel>
     */
    public function withTracer(Tracer $tracer): self
    {
        $program = $this->configured();
        $program->tracer = $tracer;

        return $program;
    }

    /**
     * Runs a command of the application's own. Handlers are asked in the order
     * they were added, and only for a command the runtime has no effect for.
     *
     * @return self<TModel>
     */
    public function withCommandHandler(CommandHandler $handler): self
    {
        $program = $this->configured();
        $program->commandHandlers[] = $handler;

        return $program;
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

        $tracePath = getenv('ELMPHANT_TRACE');
        if ($this->tracer === null && is_string($tracePath) && $tracePath !== '') {
            $this->tracer = JsonlTracer::live($tracePath);
        }

        try {
            $this->terminal = $this->injectedTerminal ?? new SystemTerminal();
            $this->screen = new Screen($this->terminal, $this->resolveColorProfile());
            $this->messageQueue = new SplQueue();
            $this->pendingWheel = new PendingWheel();
            $this->model = $this->initialModel;
            $this->stdinBuffer = new StdinBuffer(
                onSequence: $this->handleInputSequence(...),
                onPaste: function (string $content): void {
                    $this->messageQueue->enqueue(new PasteMsg($content));
                },
                remote: getenv('SSH_CONNECTION') !== false || getenv('SSH_TTY') !== false,
            );
            $this->setup();
            $this->renderPass = RenderPass::new($this->resolveTheme(), $this->resolveColorProfile());
            $this->dispatchCommand($this->model->init());
            if ($this->isRunning()) {
                $this->loop();
            }
            if ($this->model instanceof ShutdownAware) {
                $this->model->onShutdown();
            }
        } finally {
            $this->adaptiveTicker?->stop();
            $this->adaptiveTicker = null;
            $this->teardown();
        }

        if ($this->fatalError !== null) {
            throw $this->fatalError;
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

    /**
     * @return self<TModel>
     */
    private function configured(): self
    {
        if ($this->state !== ProgramState::New) {
            throw new LogicException(sprintf('Program cannot be configured in state %s', $this->state->name));
        }

        $program = clone $this;
        $program->frames = clone $this->frames;

        return $program;
    }

    private function setup(): void
    {
        // A fatal error skips the finally in start(), and the processes this program started would outlive it.
        $program = WeakReference::create($this);
        register_shutdown_function(static function () use ($program): void {
            $program->get()?->teardown();
        });

        $this->terminalSession = new TerminalSession($this->terminal, $this->useAltScreen, $this->useMouseTracking);
        $this->terminalSession->start();
        $this->terminal->queryKittyProtocol();

        // A cursor-position report would race application input, so startup asks
        // stty and the environment only; SystemTerminal::probeSize() stays explicit.
        $this->adoptTerminalSize();

        $this->registerSignalHandlers();
    }

    private function registerSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            // SIGWINCH etc. are pcntl constants; without the extension the app
            // still runs — resize detection and Ctrl-Z handling are degraded.
            return;
        }

        try {
            $this->watch(EventLoopWatcher::Resize, EventLoop::onSignal(SIGWINCH, function () {
                $this->resizePending = true;
                EventLoop::defer(fn () => $this->tick());
            }));

            // SIGHUP: closing the terminal window would otherwise kill the process
            // outright, skipping both the shutdown hook and terminal restore.
            foreach ([[EventLoopWatcher::Interrupt, SIGINT], [EventLoopWatcher::Terminate, SIGTERM], [EventLoopWatcher::Hangup, SIGHUP]] as [$watcher, $signal]) {
                $this->watch($watcher, EventLoop::onSignal($signal, function (): void {
                    $this->messageQueue->enqueue(QuitMsg::Quit);
                    EventLoop::defer(fn () => $this->tick());
                }));
            }

            if (function_exists('posix_kill')) {
                $this->watch(EventLoopWatcher::Suspend, EventLoop::onSignal(SIGTSTP, function () {
                    $this->suspend();
                }));
            }

            $this->watch(EventLoopWatcher::Resumed, EventLoop::onSignal(SIGCONT, function () {
                $this->resume();
                EventLoop::defer(fn () => $this->tick());
            }));

            // Revolt installs the handlers on the loop's first tick; a resize before
            // that is dropped, so the size is asked once more after it.
            EventLoop::defer(function (): void {
                if ($this->state === ProgramState::Running && $this->terminal->size() !== [$this->screenRect->width, $this->screenRect->height]) {
                    $this->resizePending = true;
                    $this->tick();
                }
            });
        } catch (UnsupportedFeatureException) {
            // The active EventLoop driver cannot watch signals; keep running
            // with whatever watchers were registered before the throw.
        }
    }

    private function suspend(): void
    {
        $this->cancelWatcher(EventLoopWatcher::Suspend);

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

        $this->watch(EventLoopWatcher::Suspend, EventLoop::onSignal(SIGTSTP, fn () => $this->suspend()));

        $this->screen->forget();
        $this->adoptTerminalSize();
    }

    private function adoptTerminalSize(): void
    {
        [$width, $height] = $this->terminal->size();
        $this->screenRect = Rect::fromSize($width, $height);
        $this->terminalSession?->updateHeight($height);
        $this->backBuffer = new Frame($width, $height);
        $this->messageQueue->enqueue(new ResizeMsg($width, $height));
        $this->frames->markDirty();
    }

    private function watch(EventLoopWatcher $watcher, string $id): void
    {
        $this->watchers[$watcher->name] = $id;
    }

    private function cancelWatcher(EventLoopWatcher $watcher): void
    {
        $id = $this->watchers[$watcher->name] ?? null;
        if ($id === null) {
            return;
        }

        unset($this->watchers[$watcher->name]);

        try {
            EventLoop::cancel($id);
        } catch (Throwable) {
            // A stale watcher must not prevent process or terminal cleanup.
        }
    }

    private function cancelEventLoopWatchers(): void
    {
        foreach (EventLoopWatcher::cases() as $watcher) {
            $this->cancelWatcher($watcher);
        }
    }

    private function teardown(): void
    {
        if ($this->state === ProgramState::Stopped) {
            return;
        }
        $this->state = ProgramState::Stopped;

        try {
            $this->cancelEventLoopWatchers();

            $this->effects?->shutdown();
        } finally {
            $this->terminalSession?->updateHeight($this->screenRect->height);
            $this->terminalSession?->close();
        }
    }

    private function resolveColorProfile(): ColorProfile
    {
        return $this->colorProfile ?? $this->terminal->colorProfile();
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
        $this->refreshTicker();

        $inputStream = $this->terminal->inputStream();
        if ($inputStream !== null) {
            $this->watch(EventLoopWatcher::Input, EventLoop::onReadable($inputStream, function () {
                $this->readInput();
                $this->tick();
            }));
        }

        // Park on a Suspension instead of EventLoop::run(): this composes with
        // a host that already drives the Revolt loop (nested run()
        // is fatal) and quitting resumes only this fiber instead of stopping
        // the shared driver out from under the host's other watchers.
        $this->runSuspension = EventLoop::getSuspension();
        $this->runSuspension->suspend();
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
     * Wheeling waits for the next frame and is applied as one total, so notches
     * the trackpad reports against the gesture cancel instead of moving the view.
     */
    private function enqueueInputMessage(Message $message): void
    {
        if ($message instanceof MouseMsg && self::wheelDirection($message) !== null) {
            $this->pendingWheel->add($message);

            return;
        }

        $this->messageQueue->enqueue($message);
    }

    private function flushWheelWhenDue(float $now): void
    {
        if ($this->pendingWheel->isEmpty() || $now < $this->lastWheelFlush + $this->frames->budget()) {
            return;
        }

        $this->lastWheelFlush = $now;
        $this->flushWheel();
    }

    private function flushWheel(): void
    {
        $wheeled = $this->pendingWheel->take();

        if ($wheeled !== null) {
            $this->messageQueue->enqueue($wheeled);
        }
    }

    private static function wheelDirection(MouseMsg $message): ?MouseAction
    {
        return match ($message->action) {
            MouseAction::WheelUp, MouseAction::WheelDown => $message->action,
            default => null,
        };
    }

    /**
     * Deliver input held back for escape-sequence disambiguation.
     *
     * The flush happens on a timer, with the loop otherwise idle, so it has to
     * tick as well: without that the keystroke waits in the queue until the
     * next one arrives, and every lone Escape takes effect one keystroke late.
     */
    private function flushPendingInput(): void
    {
        unset($this->watchers[EventLoopWatcher::EscapeFlush->name]);
        $this->stdinBuffer->flush();
        $this->tick();
    }

    private function readInput(): void
    {
        if ($this->inputClosed) {
            return;
        }

        $newInput = $this->terminal->readInput();
        if ($newInput === null) {
            $this->closeInput();

            return;
        }
        if ($newInput === '') {
            return;
        }

        $this->tracer?->record(TraceEvent::InputRead, [
            'bytes' => strlen($newInput),
            'queued' => $this->messageQueue->count(),
        ]);
        $this->cancelWatcher(EventLoopWatcher::EscapeFlush);
        $this->stdinBuffer->process($newInput);
        $flushDelay = $this->stdinBuffer->flushDelay();
        if ($flushDelay !== null) {
            $this->watch(EventLoopWatcher::EscapeFlush, EventLoop::delay($flushDelay, $this->flushPendingInput(...)));
        }
    }

    private function closeInput(): void
    {
        $this->inputClosed = true;
        $this->cancelWatcher(EventLoopWatcher::Input);
        $this->cancelWatcher(EventLoopWatcher::EscapeFlush);
        $this->stdinBuffer->flush();
        $this->messageQueue->enqueue(QuitMsg::Quit);
    }

    private function runTick(): void
    {
        $this->drainDeadline = null;
        $this->handlePendingResize();
        $this->readInput();

        $now = $this->clock->now();
        $this->effects?->fire($now);
        // Leaving a flooding child's output in its pipe blocks the child, and keeps keys from queueing behind it.
        if ($this->messageQueue->isEmpty()) {
            $this->effects?->pump();
        }
        $this->flushWheelWhenDue($now);

        $this->drainMessageQueue();

        if ($this->isRunning()) {
            $this->handlePendingResize();
            if (!$this->messageQueue->isEmpty()) {
                $this->drainMessageQueue();
            }
        }

        if ($this->isRunning()) {
            $this->renderWhenDue();
        }

        if (!$this->isRunning()) {
            $this->resumeRunSuspension();

            return;
        }

        // A command handler may emit while init() is still being dispatched, before
        // loop() has a ticker; loop() refreshes it from this same state when it starts.
        $this->refreshTicker();
    }

    private function renderWhenDue(): void
    {
        $now = $this->clock->now();

        if ($this->frames->isDue($now)) {
            $this->tracedRenderFrame();

            return;
        }

        if ($this->frames->wantsFrame($now)) {
            $this->tracer?->record(TraceEvent::FrameSkipped, [
                'waitMs' => $this->frames->heldFor($now) * 1000,
            ]);
        }
    }

    private function refreshTicker(): void
    {
        $this->adaptiveTicker?->refresh(
            nextRenderDelay: $this->nextRenderDelay(),
            nextTimerDelay: $this->nextTimerDelay(),
            processesActive: $this->effects?->hasRunningProcesses() ?? false,
            queuePending: !$this->messageQueue->isEmpty() || !$this->pendingWheel->isEmpty(),
        );
    }

    private function nextTimerDelay(): ?float
    {
        return $this->effects?->nextDelay();
    }

    private function nextRenderDelay(): ?float
    {
        return $this->frames->nextDelay($this->clock->now());
    }

    private function handlePendingResize(): void
    {
        if (!$this->resizePending) {
            return;
        }
        $this->resizePending = false;
        $this->resizeNeedsScreenClear = true;
        $this->adoptTerminalSize();
    }

    private function tracedRenderFrame(): void
    {
        if ($this->tracer === null) {
            $this->renderFrame();

            return;
        }

        $stats = $this->terminal instanceof ReportsOutputStats ? $this->terminal : null;
        $bytesBefore = $stats?->bytesWritten() ?? 0;
        $waitBefore = $stats?->writeWaitSeconds() ?? 0.0;
        $start = $this->clock->now();
        $this->paintedAt = $start;

        $rendered = $this->renderFrame();
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
        $screenRect = $this->screenRect;
        $processed = 0;
        // A resize re-drain inside the same tick continues this deadline rather
        // than buying a fresh budget, or one tick could spend twice the cap.
        $this->drainDeadline ??= $this->clock->now() + self::MAX_DRAIN_SECONDS;
        $deadline = $this->drainDeadline;

        while ($this->isRunning() && $processed < self::MAX_MESSAGES_PER_DRAIN && !$this->messageQueue->isEmpty()) {
            if ($processed > 0 && $this->clock->now() >= $deadline) {
                return;
            }

            ++$processed;
            $message = $this->messageQueue->dequeue();

            if ($message === QuitMsg::Quit) {
                $this->stopRunning();

                return;
            }

            if ($message instanceof ErrorMsg) {
                $this->traceError($message->error, $message->source->value);
            }

            $handledByTheErrorHandler = $message instanceof ErrorMsg && $this->errorHandler !== null;

            try {
                $delivered = $handledByTheErrorHandler
                    ? $this->handleError($message)
                    : $this->deliver($message, $screenRect);
            } catch (Throwable $error) {
                if (++$consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS) {
                    $this->giveUp($error);

                    return;
                }
                if (!$handledByTheErrorHandler) {
                    $failure = new ErrorMsg($error, ErrorSource::Update);
                    $this->errorChain[$failure] = $this->errorChain[$message] ?? 0;
                    $this->messageQueue->unshift($failure);
                }
                continue;
            }

            if ($delivered) {
                $consecutiveErrors = 0;
            }
        }
    }

    /**
     * An answer that only leads to the same failure again would loop forever, so
     * the messages it queues carry how many answers in a row led to them.
     */
    private function handleError(ErrorMsg $message): bool
    {
        $depth = ($this->errorChain[$message] ?? 0) + 1;
        if ($depth >= self::MAX_CONSECUTIVE_ERRORS) {
            $this->giveUp($message->error);

            return true;
        }

        $queued = $this->messageQueue->count();
        if ($this->errorHandler !== null) {
            $this->dispatchCommand(($this->errorHandler)($message));
        }

        for ($index = $queued; $index < $this->messageQueue->count(); ++$index) {
            $this->errorChain[$this->messageQueue[$index]] = $depth;
        }

        return true;
    }

    /**
     * False when the message went nowhere yet: routed away, or put back behind a focus announcement.
     */
    private function deliver(Message $message, Rect $screenRect): bool
    {
        $updateStart = hrtime(true);
        $stamped = InputRouter::route($message, $screenRect, $this->renderPass?->floats->placements() ?? []);
        if ($stamped === null) {
            return false;
        }
        $resolved = $this->focus->resolvedAgainst($this->focusableIds(), $this->reachableIds());

        // The waiting message goes back behind the announcement, or the first
        // key an application ever sees arrives before its pane is seated.
        if (!$resolved->sameAs($this->focus) && $resolved->focused() !== null) {
            $this->focus = $resolved;
            $this->messageQueue->unshift($stamped);
            $this->messageQueue->unshift(new FocusMsg());

            return false;
        }

        $this->focus = $resolved;
        $result = $this->model instanceof RoutesFocus
            ? $this->model->update($stamped, $screenRect, $this->focus)
            : $this->model->update($stamped, $screenRect);
        $nextModel = self::requireModel($result->model);
        $this->tracer?->record(TraceEvent::Update, [
            'message' => $stamped::class,
            'key' => $stamped instanceof KeyMsg ? self::describeKey($stamped) : null,
            'focused' => $this->focus->focused()?->value,
            'ring' => array_map(
                static fn (ComponentId $id): string => $id->value,
                $this->focusableIds(),
            ),
            'ms' => (hrtime(true) - $updateStart) / 1e6,
            'changed' => $nextModel !== $this->model,
        ]);

        $this->adopt($nextModel);
        $this->dispatchCommand($result->command);

        return true;
    }

    private function adopt(Model $next): void
    {
        if ($next === $this->model) {
            return;
        }

        $previousRevision = $this->model instanceof RenderRevisioned ? $this->model->renderRevision() : null;
        $this->model = $next;
        $newRevision = $next instanceof RenderRevisioned ? $next->renderRevision() : null;

        if ($previousRevision === null || $previousRevision !== $newRevision) {
            $this->frames->markDirty();
        }
    }

    private function giveUp(Throwable $error): void
    {
        $this->traceError($error, 'fatal');
        $this->stopRunning();
        $this->fatalError = $error;
    }

    private function traceError(Throwable $error, string $source): void
    {
        $this->tracer?->record(TraceEvent::Error, [
            'source' => $source,
            'class' => $error::class,
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
            'trace' => array_slice(explode("\n", $error->getTraceAsString()), 0, 12),
        ]);
    }

    private static function requireModel(Reducer $model): Model
    {
        if (!$model instanceof Model) {
            throw new LogicException(sprintf('Model::update() must return a Model in UpdateResult, got %s', get_debug_type($model)));
        }

        return $model;
    }

    private function renderFrame(): bool
    {
        $frame = $this->backBuffer;
        $pass = $this->renderPass;
        if ($frame === null || $pass === null) {
            throw new LogicException('Program renderer is not initialized');
        }

        if ($frame->width !== $this->screenRect->width || $frame->height !== $this->screenRect->height) {
            $frame = $this->backBuffer = new Frame($this->screenRect->width, $this->screenRect->height);
        }

        if ($this->resizeNeedsScreenClear) {
            $this->resizeNeedsScreenClear = false;
            $this->screen->forget();
        }

        $baseBackground = $pass->context->theme->base->backgroundValue();
        $frame->clear($baseBackground);

        $pass->begin($this->clock->now());
        $this->settleFocusBeforeDrawing();
        $pass->focus->focusOn($this->focus);

        try {
            $this->model->render($frame, $pass);
            $pass->floats->drainOnto($frame, $pass->context);
            $this->paintedAt = $this->clock->now();
            $this->screen->flush($frame);
        } catch (Throwable $error) {
            $this->frames->dropped($this->clock->now());
            // A slow ssh link or a laptop waking up stalls output for seconds; that frame is dropped, not fatal.
            $stalled = $error instanceof TerminalWriteException && $error->stalled;
            $this->consecutiveRenderFailures = $stalled ? 0 : $this->consecutiveRenderFailures + 1;
            if ($this->consecutiveRenderFailures >= self::MAX_CONSECUTIVE_ERRORS) {
                $this->giveUp($error);
            }
            $this->messageQueue->enqueue(new ErrorMsg($error, ErrorSource::Render));

            return false;
        }

        $this->consecutiveRenderFailures = 0;
        $pass->floats->commit();

        $this->frames->drawn($this->clock->now(), $pass->clock->repaintDelay());

        return true;
    }

    /**
     * An update can take away the component the keyboard was on (a modal opening
     * over it), and the next message is too late to say so: the frame is drawn now.
     */
    private function settleFocusBeforeDrawing(): void
    {
        $resolved = $this->focus->resolvedAgainst($this->focusableIds(), $this->reachableIds());
        if ($resolved->sameAs($this->focus)) {
            return;
        }

        $this->focus = $resolved;
        if ($resolved->focused() !== null) {
            $this->messageQueue->unshift(new FocusMsg());
        }
    }

    /**
     * Where the keyboard is gets drawn, so moving it makes the screen stale even
     * though the model is untouched.
     */
    private function moveFocus(FocusCmd $command): void
    {
        $moved = $command->applyTo($this->focus, $this->focusableIds());

        if (!$moved->sameAs($this->focus)) {
            $this->tracer?->record(TraceEvent::Focus, [
                'from' => $this->focus->focused()?->value,
                'to' => $moved->focused()?->value,
            ]);

            $this->focus = $moved;
            $this->frames->markDirty();
        }

        // Announced even when the move lands back where it started: a ring of one
        // still means "put the keyboard here". Unshifted, because one read can queue
        // a whole burst of keystrokes and an arrival behind them seats too late.
        if ($moved->focused() !== null) {
            $this->messageQueue->unshift(new FocusMsg($command->move));
        }
    }

    /**
     * The key as a reader of the trace would name it, modifiers included, so what was
     * pressed can be matched against what a binding asks for.
     */
    private static function describeKey(KeyMsg $message): string
    {
        return ($message->ctrl ? 'ctrl+' : '')
            . ($message->alt ? 'alt+' : '')
            . ($message->shift ? 'shift+' : '')
            . KeyPattern::nameOf($message->key);
    }

    /**
     * @return list<ComponentId>
     */
    private function focusableIds(): array
    {
        return $this->model instanceof RoutesFocus ? $this->model->focusableIds($this->focus) : [];
    }

    /**
     * @return list<ComponentId>
     */
    private function reachableIds(): array
    {
        return $this->model instanceof RoutesFocus ? $this->model->reachableIds() : [];
    }

    private function dispatchCommand(?Cmd $command, ?Closure $wrap = null): void
    {
        if ($command === null || $this->isQuitting()) {
            return;
        }

        $this->tracer?->record(TraceEvent::Command, ['command' => $command::class]);

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

        try {
            match (true) {
                $command instanceof QuitCmd => $this->stopRunning(),
                $command instanceof ClipboardCmd => $this->terminal->write(Ansi::clipboard($command->text)),
                $command instanceof FocusCmd => $this->moveFocus($command),
                $command instanceof MessageCmd => $this->messageQueue->enqueue(self::mapMessage($command->message, $wrap)),
                $command instanceof CallCmd => $this->executeCall($command, $wrap),
                $command instanceof LogCmd => $this->tracer?->record(TraceEvent::Log, [...$command->context, 'message' => $command->message]),
                default => $this->effects()->run($command, $wrap),
            };
        } catch (Throwable $error) {
            $this->messageQueue->enqueue(self::mapMessage(new ErrorMsg($error, ErrorSource::Command), $wrap));
        }
    }

    private function isRunning(): bool
    {
        return $this->state === ProgramState::Running;
    }

    private function isQuitting(): bool
    {
        return $this->state === ProgramState::Stopping;
    }

    private function stopRunning(): void
    {
        if ($this->state === ProgramState::Running) {
            $this->state = ProgramState::Stopping;
        }
        $this->effects?->forgetTimers();
    }

    private function effects(): EffectRunner
    {
        return $this->effects ??= new EffectRunner(
            $this->messageQueue,
            $this->clock,
            $this->dispatchCommand(...),
            $this->tick(...),
            handlers: $this->commandHandlers,
        );
    }

    /**
     * @param Closure(Message):Message|null $wrap
     */
    private function executeCall(CallCmd $command, ?Closure $wrap = null): void
    {
        try {
            $result = ($command->callback)();
        } catch (Throwable $error) {
            $this->messageQueue->enqueue(self::mapMessage(new ErrorMsg($error, ErrorSource::Call), $wrap));

            return;
        }

        if ($result instanceof Cmd) {
            $this->dispatchCommand($result, $wrap);
        } elseif ($result instanceof Message) {
            $this->messageQueue->enqueue(self::mapMessage($result, $wrap));
        } elseif ($result !== null) {
            $this->messageQueue->enqueue(self::mapMessage(new ErrorMsg(
                new UnexpectedValueException('Cmd::call() callback must return Message, Cmd, or null'),
                ErrorSource::Call,
            ), $wrap));
        }
    }

    /**
     * Compose an inner map with an optional outer map.
     *
     * @param Closure(Message):Message      $inner
     * @param Closure(Message):Message|null $outer
     *
     * @return Closure(Message):Message
     */
    private static function composeWrap(Closure $inner, ?Closure $outer): Closure
    {
        if ($outer === null) {
            return $inner;
        }

        return static fn (Message $message): Message => $outer($inner($message));
    }

    /**
     * @param Closure(Message):Message|null $wrap
     */
    private static function mapMessage(Message $message, ?Closure $wrap): Message
    {
        return $wrap === null ? $message : $wrap($message);
    }
}
