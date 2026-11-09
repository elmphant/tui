<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Cmd\CallCmd;
use Elmphant\Tui\Core\Cmd\ClipboardCmd;
use Elmphant\Tui\Core\Cmd\FlashCmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\Cmd\LogCmd;
use Elmphant\Tui\Core\Cmd\MappedCmd;
use Elmphant\Tui\Core\Cmd\MessageCmd;
use Elmphant\Tui\Core\Cmd\PrintlnCmd;
use Elmphant\Tui\Core\Cmd\QuitCmd;
use Elmphant\Tui\Core\Cmd\ScopedCmd;
use Elmphant\Tui\Core\Cmd\ToastCmd;
use Elmphant\Tui\Core\CmdLeaf;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\ChordTracker;
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
use Elmphant\Tui\Rendering\Context\Flashes;
use Elmphant\Tui\Rendering\Context\PendingChord;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\InlineScreen;
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
use Elmphant\Tui\Text\TerminalText;
use LogicException;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use Revolt\EventLoop\UnsupportedFeatureException;
use RuntimeException;
use SplQueue;
use Throwable;
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
     * Bound elapsed time as well as message count so slow updates cannot leave Ctrl+C stuck in the queue.
     */
    private const MAX_DRAIN_SECONDS = 0.05;

    // 48 bytes a cell for both frames: 1000x500 is 48 MB.
    private const MAX_COLUMNS = 1_000;
    private const MAX_ROWS = 500;

    private ScreenMode $screenMode;
    private FrameScheduler $frames;
    /**
     * @var (Closure(ErrorMsg): (Cmd|null))|null
     */
    private ?Closure $errorHandler = null;
    private ?Tracer $tracer = null;
    private ?int $outputLevel = null;

    /**
     * @var list<CommandHandler>
     */
    private array $commandHandlers = [];

    private Terminal $terminal;
    private Surface $surface;
    /**
     * @var SplQueue<Message>
     */
    private SplQueue $messageQueue;
    /**
     * @var SplQueue<Message>
     */
    private SplQueue $inputQueue;
    /**
     * @var WeakMap<Message, int>
     */
    private WeakMap $errorChain;
    private PendingWheel $pendingWheel;
    private ChordTracker $chordTracker;
    private KeyHelpOverlay $keyHelp;
    private Toasts $toasts;
    private Flashes $flashes;
    /**
     * @var WeakMap<Message, true> keys the chord tracker already let through
     */
    private WeakMap $pastTheChords;
    private float $lastWheelFlush = 0.0;
    private Model $model;
    private ?Frame $backBuffer = null;
    private bool $inputClosed = false;
    private int $consecutiveRenderFailures = 0;
    private ?Throwable $fatalError = null;
    private int $unhandledErrors = 0;
    private ?Throwable $lastUnhandledError = null;
    private ?float $drainDeadline = null;
    private int $drainedThisTick = 0;
    private bool $resizePending = false;
    private Rect $screenRect;
    private ProgramState $state = ProgramState::New;
    private ?TerminalSession $terminalSession = null;

    /**
     * Create the effect runner on first dispatch; programs without effects do not need one.
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

    private ?Theme $startTheme = null;

    private ?Theme $drawnTheme = null;
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
        $this->focus = FocusState::undecided();
        $this->frames = new FrameScheduler();
        $this->screenMode = ScreenMode::main();
        $this->clock = new MonotonicClock();
        $this->screenRect = Rect::fromSize(80, 24);
        $this->pendingWheel = new PendingWheel();
        $this->inputQueue = new SplQueue();
        $this->errorChain = new WeakMap();
        $this->chordTracker = new ChordTracker();
        $this->keyHelp = new KeyHelpOverlay();
        $this->toasts = new Toasts();
        $this->flashes = new Flashes();
        $this->pastTheChords = new WeakMap();
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

    /**
     * @internal
     */
    public function state(): ProgramState
    {
        return $this->state;
    }

    /**
     * @return self<TModel>
     */
    public function withScreenMode(ScreenMode $mode): self
    {
        $program = $this->configured();
        $program->screenMode = $mode;

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
     * Override detected colour depth when the environment underreports terminal support, such as missing COLORTERM over SSH.
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
     * Try handlers in registration order, and only for commands without a built-in handler.
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
            $this->outputLevel = ob_get_level();
            ob_start();
            $this->terminal = $this->injectedTerminal ?? new SystemTerminal();
            $this->surface = $this->screenMode->isInline()
                ? new InlineSurface(new InlineScreen($this->terminal, $this->resolveColorProfile()))
                : new FullScreenSurface($this->terminal, new Screen($this->terminal, $this->resolveColorProfile()), $this->screenMode->isAlternate(), $this->tracer);
            $this->messageQueue = new SplQueue();
            $this->inputQueue = new SplQueue();
            $this->pendingWheel = new PendingWheel();
            $this->model = $this->initialModel;
            $this->stdinBuffer = new StdinBuffer(
                onSequence: $this->handleInputSequence(...),
                onPaste: function (string $content): void {
                    $this->inputQueue->enqueue(new PasteMsg($content));
                },
                remote: getenv('SSH_CONNECTION') !== false || getenv('SSH_TTY') !== false,
            );
            $this->setup();
            $this->startTheme = $this->drawnTheme = $this->resolveTheme();
            $this->renderPass = RenderPass::new($this->startTheme, $this->resolveColorProfile(), flashes: $this->flashes);
            $model = $this->model;
            $this->dispatchCommand($this->capturingOutput('init', static fn (): ?Cmd => $model->init()));
            if ($this->isRunning()) {
                $this->loop();
            }
            if ($this->fatalError === null) {
                $this->surface->finish(function (): void {
                    $this->renderFrame();
                });
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
        $this->reportUnhandledErrors();

        /** @var TModel */
        return $this->model;
    }

    /**
     * @internal
     */
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
            $this->traceStrayOutput();
        }
    }

    /**
     * A callback a command runs, a fiber or the error handler can echo too, and in raw mode that lands on the screen.
     */
    private function traceStrayOutput(): void
    {
        if ($this->outputLevel === null || ob_get_level() <= $this->outputLevel) {
            return;
        }

        $output = ob_get_clean();
        if (is_string($output) && $output !== '') {
            $this->tracer?->record(TraceEvent::Output, ['during' => 'tick', 'text' => $output]);
        }
        if ($this->state !== ProgramState::Stopped) {
            ob_start();
        }
    }

    private function reportUnhandledErrors(): void
    {
        if ($this->lastUnhandledError === null) {
            return;
        }

        $error = $this->lastUnhandledError;
        $this->terminal->write(sprintf(
            "%d %s went unhandled while the app ran. The last one: %s: %s in %s:%d\r\n",
            $this->unhandledErrors,
            $this->unhandledErrors === 1 ? 'error' : 'errors',
            $error::class,
            TerminalText::singleLine($error->getMessage()),
            TerminalText::singleLine($error->getFile()),
            $error->getLine(),
        ));
        $this->terminal->flush();
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
        $parent = getmypid();
        register_shutdown_function(static fn () => self::restoreAfterAFatalError($program, $parent));

        $this->terminalSession = new TerminalSession(
            $this->terminal,
            $this->screenMode->isAlternate(),
            $this->screenMode->tracksTheMouse(),
            $this->surface,
        );
        $this->terminalSession->start();

        // Probe size through stty and environment at startup; a cursor-position report could consume application input.
        $this->adoptTerminalSize();

        $this->registerSignalHandlers();
    }

    /**
     * @infection-ignore-all runs only after a fatal error, which the tests cause in a child process coverage cannot see
     *
     * @param WeakReference<self<TModel>> $program
     */
    private static function restoreAfterAFatalError(WeakReference $program, int|false $parent): void
    {
        // A forked child that exits runs this too, and its teardown would kill the parent's other forks.
        if (getmypid() !== $parent) {
            return;
        }

        $running = $program->get();
        if ($running === null || $running->state === ProgramState::Stopped) {
            return;
        }
        $running->teardown();
        if ($running->screenMode->isAlternate()) {
            self::reportFatalErrorTheAltScreenHid();
        }
    }

    /**
     * PHP prints a fatal error on the alt screen, and leaving the alt screen erases it.
     *
     * @infection-ignore-all runs only after a fatal error, which the tests cause in a child process coverage cannot see
     */
    private static function reportFatalErrorTheAltScreenHid(): void
    {
        $error = error_get_last();
        if ($error === null || ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) === 0) {
            return;
        }

        $logErrors = strtolower((string)ini_get('log_errors'));
        $loggedToStderr = in_array($logErrors, ['1', 'on', 'true', 'yes'], true) && (string)ini_get('error_log') === '';
        if ($loggedToStderr || strtolower((string)ini_get('display_errors')) === 'stderr') {
            return;
        }

        fwrite(STDERR, sprintf("PHP Fatal error:  %s in %s on line %d\n", TerminalText::singleLine($error['message']), TerminalText::singleLine($error['file']), $error['line']));
    }

    private function registerSignalHandlers(): void
    {
        if (!extension_loaded('pcntl')) {
            // Signal constants require pcntl; without it, resize detection and suspension support are limited.
            return;
        }

        try {
            $this->watch(EventLoopWatcher::Resize, EventLoop::onSignal(SIGWINCH, function () {
                $this->resizePending = true;
                EventLoop::defer(fn () => $this->tick());
            }));

            // Catch SIGHUP so closing the terminal still runs shutdown and restores terminal state.
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

            // Recheck size after the first loop tick, when Revolt installs its signal handlers.
            $this->watch(EventLoopWatcher::SizeRecheck, EventLoop::defer(function (): void {
                if ($this->state === ProgramState::Running && $this->terminalSize() !== [$this->screenRect->width, $this->screenRect->height]) {
                    $this->resizePending = true;
                    $this->tick();
                }
            }));
        } catch (UnsupportedFeatureException) {
            // Keep existing watchers if the loop driver cannot watch signals.
        }
    }

    private function suspend(): void
    {
        $this->cancelWatcher(EventLoopWatcher::Suspend);

        $this->restoreTerminalForSuspension();

        if (function_exists('posix_kill') && function_exists('pcntl_signal')) {
            pcntl_signal(SIGTSTP, SIG_DFL);
            // SIGTSTP may be ignored for orphaned process groups; use SIGSTOP to guarantee suspension after restoring the terminal.
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

        $this->surface->forget();
        $this->adoptTerminalSize();
    }

    private function adoptTerminalSize(): void
    {
        [$width, $height] = $this->terminalSize();
        $this->screenRect = Rect::fromSize($width, $height);
        $this->backBuffer = new Frame($width, $height);
        $this->inputQueue->enqueue(new ResizeMsg($width, $height));
        $this->frames->markDirty();
    }

    /**
     * @return array{int, int}
     */
    private function terminalSize(): array
    {
        [$width, $height] = $this->terminal->size();

        return [max(1, min($width, self::MAX_COLUMNS)), max(1, min($height, self::MAX_ROWS))];
    }

    private function watch(EventLoopWatcher $watcher, string $id): void
    {
        $this->cancelWatcher($watcher);
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
            $this->terminalSession?->close();
            $this->traceStrayOutput();
            $this->flushTrace();
        }
    }

    private function flushTrace(): void
    {
        if (!$this->tracer instanceof JsonlTracer) {
            return;
        }

        try {
            $this->tracer->close();
        } catch (RuntimeException) {
            // A trace that cannot be written must not stand in for the failure it was tracing.
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

        $colorScheme = $this->terminal->colorScheme();

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

        // Draw before the loop runs work that setup() queued: work that blocks would otherwise leave the screen empty.
        $this->tick();
        if (!$this->isRunning()) {
            return;
        }

        // Suspend this fiber instead of calling run(): a host may already run the shared loop, and quitting must not stop it.
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
        if (InputParser::isKittyKeyboardReply($sequence)) {
            $this->terminalSession?->enableKittyProtocol();

            return;
        }

        $message = InputParser::parseSequence($sequence);
        if ($message !== null) {
            $this->enqueueInputMessage($message);
        }
    }

    /**
     * Combine wheel events until the next frame so opposite trackpad notches cancel out.
     */
    private function enqueueInputMessage(Message $message): void
    {
        if ($message instanceof MouseMsg && self::wheelDirection($message) !== null) {
            $this->pendingWheel->add($message);

            return;
        }

        $this->inputQueue->enqueue($message);
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
            $this->inputQueue->enqueue($wheeled);
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
     * Tick after flushing buffered input; otherwise a lone Escape would wait until the next keystroke.
     */
    private function flushPendingInput(): void
    {
        unset($this->watchers[EventLoopWatcher::EscapeFlush->name]);
        $this->stdinBuffer->flush();
        $this->armInputFlush();
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
        $this->armInputFlush();
    }

    private function armInputFlush(): void
    {
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
        $this->drainedThisTick = 0;
        $this->handlePendingResize();
        $this->readInput();

        // Leaving a flooding child's output in its pipe blocks the child, and keeps keys from queueing behind it.
        // Asked before the timers fire: a ticker due on every turn would otherwise keep the pipes unread for good.
        $readsEffectOutput = $this->messageQueue->isEmpty() && $this->inputQueue->isEmpty();
        $now = $this->clock->now();
        $this->effects?->fire($now);
        $toastsEnded = $this->toasts->expire($now);
        $flashesEnded = $this->flashes->expire($now);
        if ($toastsEnded || $flashesEnded) {
            $this->frames->markDirty();
        }
        $expired = $this->chordTracker->expired($now);
        if ($expired !== []) {
            $this->handleBeforeTheQueue($expired);
        }
        if ($readsEffectOutput) {
            $this->effects?->pump();
        } else {
            $this->effects?->expireOverdue();
        }
        $this->flushWheelWhenDue($now);

        $this->drainMessageQueue();

        if ($this->isRunning() && $this->handlePendingResize()) {
            $this->drainMessageQueue();
        }

        if ($this->isRunning()) {
            $this->renderWhenDue();
        }

        if (!$this->isRunning()) {
            $this->resumeRunSuspension();

            return;
        }

        // Handlers may emit during init(), before the ticker exists; loop() uses this state when it starts.
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
            processesActive: $this->effects?->hasBusyProcesses() ?? false,
            queuePending: !$this->messageQueue->isEmpty() || !$this->inputQueue->isEmpty() || !$this->pendingWheel->isEmpty(),
        );
    }

    private function nextTimerDelay(): ?float
    {
        $now = $this->clock->now();
        $chordDeadline = $this->chordTracker->deadline();
        $toastDeadline = $this->toasts->nextDeadline();
        $flashDeadline = $this->flashes->nextDeadline();
        $delays = array_filter(
            [
                $this->effects?->nextDelay(),
                $chordDeadline === null ? null : max(0.0, $chordDeadline - $now),
                $toastDeadline === null ? null : max(0.0, $toastDeadline - $now),
                $flashDeadline === null ? null : max(0.0, $flashDeadline - $now),
            ],
            static fn (?float $delay): bool => $delay !== null,
        );

        return $delays === [] ? null : min($delays);
    }

    private function nextRenderDelay(): ?float
    {
        return $this->frames->nextDelay($this->clock->now());
    }

    private function handlePendingResize(): bool
    {
        if (!$this->resizePending) {
            return false;
        }
        $this->resizePending = false;
        $this->surface->forget();
        $this->adoptTerminalSize();

        return true;
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
        try {
            $screenRect = $this->drawnRect();
        } catch (Throwable) {
            // renderFrame() reports the failing wantedHeight(), and the error handler still needs an area to run under.
            $screenRect = $this->screenRect;
        }
        // A resize re-drain continues the tick's budget, so one tick cannot spend it twice.
        $this->drainDeadline ??= $this->clock->now() + self::MAX_DRAIN_SECONDS;
        $deadline = $this->drainDeadline;

        $this->moveInputAheadOfTheBacklog();

        $processed = 0;
        while ($this->isRunning() && $this->drainedThisTick < self::MAX_MESSAGES_PER_DRAIN && !$this->messageQueue->isEmpty()) {
            if ($processed > 0 && $this->clock->now() >= $deadline) {
                return;
            }

            ++$processed;
            ++$this->drainedThisTick;
            $message = $this->messageQueue->dequeue();

            if ($message === QuitMsg::Quit) {
                $this->stopRunning();

                return;
            }

            if ($message instanceof ErrorMsg) {
                $this->traceError($message->error, $message->source->value);
                if ($this->errorHandler === null) {
                    $this->rememberUnhandled($message->error);
                }
            }

            $handledByTheErrorHandler = $message instanceof ErrorMsg && $this->errorHandler !== null;

            try {
                $delivered = $handledByTheErrorHandler
                    ? $this->handleError($message)
                    : $this->deliver($message, $screenRect);
            } catch (Throwable $error) {
                if ($handledByTheErrorHandler) {
                    $this->traceError($error, 'onError');
                    $this->rememberUnhandled($error);
                }
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
     * Input the runtime put back at the front stays first.
     */
    private function moveInputAheadOfTheBacklog(): void
    {
        // SplQueue walks the list to reach an index. Do not index it in a loop.
        $front = 0;
        foreach ($this->messageQueue as $message) {
            if (!self::isInput($message)) {
                break;
            }
            ++$front;
        }

        $backlog = [];
        while ($this->messageQueue->count() > $front) {
            $backlog[] = $this->messageQueue->pop();
        }
        while (!$this->inputQueue->isEmpty()) {
            $this->messageQueue->enqueue($this->inputQueue->dequeue());
        }
        foreach (array_reverse($backlog) as $message) {
            $this->messageQueue->enqueue($message);
        }
    }

    private static function isInput(Message $message): bool
    {
        return $message instanceof KeyMsg || $message instanceof MouseMsg || $message instanceof PasteMsg || $message instanceof ResizeMsg || $message instanceof FocusMsg;
    }

    private function rememberUnhandled(Throwable $error): void
    {
        ++$this->unhandledErrors;
        $this->lastUnhandledError = $error;
    }

    /**
     * Count consecutive error responses to stop handlers from causing an endless failure loop.
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

    private function deliver(Message $message, Rect $screenRect): bool
    {
        $updateStart = hrtime(true);
        $stamped = InputRouter::route($message, $screenRect, $this->renderPass?->floats->placements() ?? []);
        if ($stamped === null) {
            return false;
        }
        $resolved = $this->resolvedFocus();

        // Deliver focus arrival before the queued key so the component is ready to handle it.
        if (!$resolved->sameAs($this->focus) && $resolved->focused() !== null) {
            $this->focus = $resolved;
            $this->messageQueue->unshift($stamped);
            $this->messageQueue->unshift(new FocusMsg());

            return false;
        }

        $this->focus = $resolved;

        if ($stamped instanceof KeyMsg && $this->model instanceof BindsChords && !isset($this->pastTheChords[$message])) {
            $keys = $this->chordTracker->feed($stamped, $this->model->chords($this->focus), $this->clock->now());

            if ($keys !== [$stamped]) {
                $this->handleBeforeTheQueue($keys);

                return false;
            }
        }

        $keyHelpWasOpen = $this->keyHelp->isOpen();
        if ($this->keyHelp->takes($stamped, $this->model, $this->focus)) {
            $this->frames->markDirty();

            return false;
        }
        if ($keyHelpWasOpen) {
            $this->frames->markDirty();
        }
        if ($this->toasts->takes($stamped, $screenRect)) {
            $this->frames->markDirty();

            return false;
        }

        $model = $this->model;
        $focus = $this->focus;
        $result = $this->capturingOutput('update', static fn (): UpdateResult => $model instanceof RoutesFocus
            ? $model->update($stamped, $screenRect, $focus)
            : $model->update($stamped, $screenRect));
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

    /**
     * Opening or closing a chord changes the footer even when the model is unchanged.
     *
     * @param list<KeyMsg> $keys
     */
    private function handleBeforeTheQueue(array $keys): void
    {
        $this->frames->markDirty();

        foreach (array_reverse($keys) as $key) {
            $this->pastTheChords[$key] = true;
            $this->messageQueue->unshift($key);
        }
    }

    private function adopt(Model $next): void
    {
        if ($next === $this->model) {
            return;
        }

        $this->model = $next;
        $this->frames->markDirty();
    }

    private function giveUp(Throwable $error): void
    {
        $this->traceError($error, 'fatal');
        $this->stopRunning();
        $this->fatalError = $error;
    }

    /**
     * Capture stray output so echo or var_dump cannot corrupt the terminal display.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     */
    private function capturingOutput(string $during, Closure $work): mixed
    {
        ob_start();
        try {
            return $work();
        } finally {
            $output = ob_get_clean();
            if (is_string($output) && $output !== '') {
                $this->tracer?->record(TraceEvent::Output, ['during' => $during, 'text' => $output]);
            }
        }
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

    private function drawnRect(): Rect
    {
        return $this->surface->area($this->screenRect, $this->model);
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

        $pass->begin($this->clock->now());

        try {
            $drawn = $this->drawnRect();
            $pass = $this->inChosenTheme($pass)->withArea($drawn);
            $frame->clear($pass->context->theme->base->background);
            $this->settleFocusBeforeDrawing();
            $pass->focus->focusOn($this->focus);
            $pass->focus->provideChord($this->pendingChord());
            $model = $this->model;
            $this->capturingOutput('render', static fn () => $model->render($frame, $pass));
            $pass->floats->drainOnto($frame, $pass->context);
            $this->toasts->drawOver($frame, $pass->context, $drawn);
            $this->keyHelp->drawOver($frame, $pass->context, $model, $this->focus, $drawn);
            $this->paintedAt = $this->clock->now();
            $this->surface->flush($frame, $drawn);
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

    private function pendingChord(): ?PendingChord
    {
        $held = $this->chordTracker->held();
        $last = end($held);

        if ($last === false || !$this->model instanceof BindsChords) {
            return null;
        }

        return new PendingChord(
            KeyPattern::fromKeyMsg($last->withPreceding(array_slice($held, 0, -1)))->label(),
            $this->model->chords($this->focus)->continuations(...$held),
        );
    }

    private function inChosenTheme(RenderPass $pass): RenderPass
    {
        $chosen = ($this->model instanceof ChoosesItsTheme ? $this->model->theme() : null) ?? $this->startTheme;
        if ($chosen === null || $chosen === $this->drawnTheme) {
            return $pass;
        }

        $this->drawnTheme = $chosen;

        return $this->renderPass = $pass->withTheme($chosen);
    }

    /**
     * Resolve focus before rendering because an update may remove or hide the focused component.
     */
    private function settleFocusBeforeDrawing(): void
    {
        $resolved = $this->resolvedFocus();
        $moved = !$resolved->sameAs($this->focus);
        $this->focus = $resolved;

        if ($moved && $resolved->focused() !== null) {
            $this->messageQueue->unshift(new FocusMsg());
        }
    }

    /**
     * Focus changes require a redraw even when the model is unchanged.
     */
    private function moveFocus(FocusCmd $command): void
    {
        $moved = $command->applyTo(
            $this->focus,
            $this->focusableIds(),
            $this->model instanceof RoutesFocus ? $this->model->reachableIds() : null,
        );

        if ($moved === null || $command->target !== null || !$moved->sameAs($this->focus)) {
            $this->tracer?->record(TraceEvent::Focus, [
                'from' => $this->focus->focused()?->value,
                'to' => ($moved ?? $this->focus)->focused()?->value,
                ...($command->target === null ? [] : ['requested' => $command->target->value]),
            ]);
        }

        if ($moved === null) {
            return;
        }

        if (!$moved->sameAs($this->focus)) {
            $this->focus = $moved;
            $this->frames->markDirty();
        }

        // Announce even a move to the same component, before any queued keys, so containers handle arrival first.
        if ($moved->focused() !== null) {
            $this->messageQueue->unshift(new FocusMsg($command->move));
        }
    }

    /**
     * A printable key may be part of a password, so the trace says only that text was typed.
     */
    private static function describeKey(KeyMsg $message): string
    {
        if (is_string($message->key) && !$message->ctrl && !$message->alt) {
            return 'text';
        }

        return ($message->ctrl ? 'ctrl+' : '')
            . ($message->alt ? 'alt+' : '')
            . ($message->shift ? 'shift+' : '')
            . KeyPattern::nameOf($message->key);
    }

    private function resolvedFocus(): FocusState
    {
        return $this->focus->resolvedAgainst(
            $this->focusableIds(),
            $this->reachableIds(),
            $this->model instanceof RoutesFocus ? $this->model->enclosingIds(...) : null,
        );
    }

    /**
     * @return list<ComponentId>
     */
    private function focusableIds(): array
    {
        return $this->model instanceof RoutesFocus ? $this->model->ringIds($this->focus) : [];
    }

    /**
     * @return list<ComponentId>
     */
    private function reachableIds(): array
    {
        return $this->model instanceof RoutesFocus ? $this->model->reachableIds() : [];
    }

    private function dispatchCommand(?Cmd $command, ?Closure $wrap = null, ?string $scope = null): void
    {
        if ($command === null || $this->isQuitting()) {
            return;
        }

        $this->tracer?->record(TraceEvent::Command, ['command' => $command::class]);

        if ($command instanceof MappedCmd || $command instanceof ScopedCmd || $command instanceof BatchCmd) {
            foreach (CmdLeaf::all($command, $wrap, $scope) as $leaf) {
                $this->dispatchCommand($leaf->command, $leaf->wrap, $leaf->scope);
            }

            return;
        }

        try {
            match (true) {
                $command instanceof QuitCmd => $this->stopRunning(),
                $command instanceof ClipboardCmd => $this->terminal->write(Ansi::clipboard($command->text)),
                $command instanceof ToastCmd => $this->showToast($command),
                $command instanceof FlashCmd => $this->showFlash($command->within($scope)),
                $command instanceof PrintlnCmd => $this->printAbove($command),
                $command instanceof FocusCmd => $this->moveFocus($command->within($scope)),
                $command instanceof MessageCmd => $this->messageQueue->enqueue(self::mapMessage($command->message, $wrap)),
                $command instanceof CallCmd => $this->executeCall($command, $wrap, $scope),
                $command instanceof LogCmd => $this->tracer?->record(TraceEvent::Log, [...$command->context, 'message' => $command->message]),
                default => $this->effects()->run($command, $wrap, $scope),
            };
        } catch (Throwable $error) {
            $this->messageQueue->enqueue(self::mapMessage(new ErrorMsg($error, ErrorSource::Command), $wrap));
        }
    }

    private function showToast(ToastCmd $toast): void
    {
        $this->toasts->show($toast, $this->clock->now());
        $this->frames->markDirty();
    }

    private function showFlash(FlashCmd $flash): void
    {
        $this->flashes->show($flash->component->value, $flash->key, $flash->seconds, $this->clock->now());
        $this->frames->markDirty();
    }

    private function printAbove(PrintlnCmd $println): void
    {
        $this->surface->printAbove($println->lines, $this->screenRect->width);
        $this->frames->markDirty();
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
            tracer: $this->tracer,
        );
    }

    /**
     * @param Closure(Message):Message|null $wrap
     */
    private function executeCall(CallCmd $command, ?Closure $wrap, ?string $scope): void
    {
        $answer = CallbackAnswer::of($command->callback, ErrorSource::Call, 'Cmd::call()');
        if ($answer instanceof Cmd) {
            $this->dispatchCommand($answer, $wrap, $scope);
        } elseif ($answer instanceof Message) {
            $this->messageQueue->enqueue(self::mapMessage($answer, $wrap));
        }
    }

    /**
     * @param Closure(Message):Message|null $wrap
     */
    private static function mapMessage(Message $message, ?Closure $wrap): Message
    {
        return $wrap === null ? $message : $wrap($message);
    }
}
