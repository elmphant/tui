<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Closure;
use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\AfterCmd;
use Elmphant\Tui\Core\Cmd\CallCmd;
use Elmphant\Tui\Core\Cmd\CancelCmd;
use Elmphant\Tui\Core\Cmd\CancelScopeCmd;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\Cmd\FiberCmd;
use Elmphant\Tui\Core\Cmd\FlashCmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\Cmd\ForkCmd;
use Elmphant\Tui\Core\Cmd\HttpCmd;
use Elmphant\Tui\Core\Cmd\MessageCmd;
use Elmphant\Tui\Core\Cmd\PrintlnCmd;
use Elmphant\Tui\Core\Cmd\QuitCmd;
use Elmphant\Tui\Core\Cmd\StreamCmd;
use Elmphant\Tui\Core\Cmd\TickCmd;
use Elmphant\Tui\Core\Cmd\ToastCmd;
use Elmphant\Tui\Core\CmdLeaf;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\FocusMove;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\NamesItsEffect;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Input\ChordTracker;
use Elmphant\Tui\Input\TakesText;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\FocusMsg;
use Elmphant\Tui\Message\HttpResultMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\ProcessFinishedMsg;
use Elmphant\Tui\Message\ProcessOutputMsg;
use Elmphant\Tui\Message\ProcessResultMsg;
use Elmphant\Tui\Message\ProcessStream;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Context\Flashes;
use Elmphant\Tui\Rendering\Context\FloatPlacement;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\BindsChords;
use Elmphant\Tui\Runtime\CallbackAnswer;
use Elmphant\Tui\Runtime\ChoosesItsTheme;
use Elmphant\Tui\Runtime\FiberHandler;
use Elmphant\Tui\Runtime\ForkHandler;
use Elmphant\Tui\Runtime\InputRouter;
use Elmphant\Tui\Runtime\KeyHelpOverlay;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\RoutesFocus;
use Elmphant\Tui\Runtime\Toasts;
use Elmphant\Tui\Runtime\WantedTicks;
use Elmphant\Tui\Runtime\WantsTicks;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * Run pure model updates and rendering without a terminal.
 * Expose effects for tests; execute focus changes and self-messages to match runtime routing.
 *
 * @template TModel of \Elmphant\Tui\Runtime\Model
 */
final class ModelHarness
{
    private Rect $area;
    private ?Cmd $lastCommand = null;
    private FocusState $focus;
    private RenderPass $pass;
    private int $depth = 0;
    private bool $started = false;
    private ChordTracker $chordTracker;
    private KeyHelpOverlay $keyHelp;
    private Toasts $toasts;
    private WantedTicks $wantedTicks;

    private Flashes $flashes;
    private float $now = 0.0;

    /**
     * @var list<Cmd> what the last action asked for, batches and wraps taken apart
     */
    private array $issued = [];

    /**
     * @var array<string, array{command: Cmd, wrap: ?Closure, id: EffectId}> by scoped id
     */
    private array $running = [];

    /**
     * @var list<array{command: CallCmd, wrap: ?Closure, scope: ?string}> issued and not run yet
     */
    private array $calls = [];

    /**
     * @var list<array{command: AfterCmd, wrap: ?Closure, scope: ?string, due: float}> issued and not fired yet
     */
    private array $timers = [];

    /**
     * @var list<array{command: FiberCmd, wrap: ?Closure, id: ?EffectId}> issued and not run yet, by scoped id
     */
    private array $fibers = [];

    /**
     * @var list<array{command: ForkCmd, wrap: ?Closure, id: ?EffectId}> issued and not run yet, by scoped id
     */
    private array $forks = [];

    /**
     * @var list<string>
     */
    private array $printed = [];

    /**
     * @var list<Message> sent by the model and not delivered yet, first in first out, as Program's queue
     */
    private array $queue = [];

    private bool $draining = false;

    private bool $quitting = false;

    /**
     * @param TModel $model
     */
    private function __construct(
        private Model $model,
        int $width,
        int $height,
        private readonly Theme $theme,
    ) {
        $this->area = Rect::fromSize($width, $height);
        $this->focus = FocusState::undecided();
        $this->flashes = new Flashes();
        $this->pass = RenderPass::new($theme, flashes: $this->flashes);
        $this->chordTracker = new ChordTracker();
        $this->keyHelp = new KeyHelpOverlay();
        $this->toasts = new Toasts();
        $this->wantedTicks = new WantedTicks();
    }

    /**
     * @template T of \Elmphant\Tui\Runtime\Model
     *
     * @param T $model
     *
     * @return self<T>
     */
    public static function for(
        Model $model,
        int $width = 80,
        int $height = 24,
        ?Theme $theme = null,
    ): self {
        self::assertDimensions($width, $height);

        return new self($model, $width, $height, $theme ?? Theme::dark());
    }

    /**
     * @return self<TModel>
     */
    public function init(): self
    {
        $this->started = true;
        $this->issued = [];
        $this->quitting = false;
        ++$this->depth;

        try {
            $initCommand = $this->model->init();
            $this->record($initCommand);
            $this->moveFocusFor($initCommand);
            $this->deliverInOrder([new ResizeMsg($this->area->width, $this->area->height), ...self::messagesIn($initCommand)]);
            $this->lastCommand = $initCommand;
        } finally {
            --$this->depth;
        }

        return $this;
    }

    /**
     * Apply runtime input routing, including screen geometry and floats from the last render.
     *
     * @return self<TModel>
     */
    public function dispatch(Message $message): self
    {
        $this->startedLikeTheProgram();
        $routed = InputRouter::route($message, $this->area, $this->pass->floats->placements());
        if ($routed === null) {
            return $this;
        }

        $model = $this->model;
        if ($routed instanceof KeyMsg && $model instanceof BindsChords && !$this->keyHelp->isOpen()) {
            $this->settleFocus();
            $keys = $this->chordTracker->feed($routed, $model->chords($this->focus), $this->now);

            if ($keys !== [$routed]) {
                foreach ($keys as $key) {
                    $this->handled($key);
                }

                return $this;
            }
        }

        return $this->handled($routed);
    }

    /**
     * Commands caused by the last action, including follow-up work, with batches, maps and scopes unpacked.
     *
     * @template T of Cmd
     *
     * @param class-string<T> $type
     *
     * @return list<T>
     */
    public function issued(string $type = Cmd::class): array
    {
        $this->startedLikeTheProgram();

        return array_values(array_filter($this->issued, static fn (Cmd $command): bool => $command instanceof $type));
    }

    /**
     * Complete a simulated exec effect with its original ID and message mappings.
     *
     * @return self<TModel>
     */
    public function answer(EffectId|string $effect, string $stdout = '', int $exitCode = 0, string $stderr = ''): self
    {
        $running = $this->runningAs($effect, ExecCmd::class);

        return $this->ended($running, new ProcessResultMsg($stdout, $stderr, $exitCode, $running['id']));
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return self<TModel>
     */
    public function respond(EffectId|string $effect, int $status = 200, string $body = '', array $headers = []): self
    {
        $running = $this->runningAs($effect, HttpCmd::class);

        return $this->ended($running, new HttpResultMsg($status, $body, $headers, $running['id']));
    }

    /**
     * @return self<TModel>
     */
    public function stream(EffectId|string $effect, string $output, ProcessStream $stream = ProcessStream::Stdout): self
    {
        $running = $this->runningAs($effect, StreamCmd::class);

        return $this->delivered($running, new ProcessOutputMsg($output, $stream, $running['id']));
    }

    /**
     * @return self<TModel>
     */
    public function finish(EffectId|string $effect, int $exitCode = 0): self
    {
        $running = $this->runningAs($effect, StreamCmd::class);

        return $this->ended($running, new ProcessFinishedMsg($exitCode, $running['id']));
    }

    /**
     * @return self<TModel>
     */
    public function tick(EffectId|string $effect, float $at = 0.0): self
    {
        $running = $this->runningAs($effect, TickCmd::class);

        return $this->delivered($running, new TickMsg($at, $running['id']));
    }

    /**
     * @return self<TModel>
     */
    public function emit(EffectId|string $effect, Message $message): self
    {
        return $this->delivered($this->runningAs($effect, Cmd::class), $message);
    }

    /**
     * Run pending call callbacks with their original mappings and scopes.
     *
     * @return self<TModel>
     */
    public function runCalls(): self
    {
        $this->startedLikeTheProgram();
        $calls = $this->calls;
        $this->calls = [];
        $this->issued = [];
        ++$this->depth;

        try {
            foreach ($calls as ['command' => $call, 'wrap' => $wrap, 'scope' => $scope]) {
                $this->answeredCall($call, $wrap, $scope);
            }
        } finally {
            --$this->depth;
        }

        return $this;
    }

    /**
     * Run pending fiber work in turn, outside any fiber; a cancelled or replaced fiber does not run.
     *
     * @return self<TModel>
     */
    public function runFibers(): self
    {
        $this->startedLikeTheProgram();
        $fibers = $this->fibers;
        $this->fibers = [];
        $this->issued = [];
        ++$this->depth;

        try {
            foreach ($fibers as ['command' => $fiber, 'wrap' => $wrap, 'id' => $id]) {
                if ($id !== null && ($this->running[(string)$id]['command'] ?? null) !== $fiber) {
                    continue;
                }
                if ($id !== null) {
                    unset($this->running[(string)$id]);
                }

                $answer = FiberHandler::answerOf($fiber);
                if ($answer !== null) {
                    $this->dispatch($wrap === null ? $answer : $wrap($answer));
                }
            }
        } finally {
            --$this->depth;
        }

        return $this;
    }

    /**
     * Run pending forks in turn, in the test's own process: what $work sends arrives first, then what it returns.
     *
     * @return self<TModel>
     */
    public function runForks(): self
    {
        $this->startedLikeTheProgram();
        $forks = $this->forks;
        $this->forks = [];
        $this->issued = [];
        ++$this->depth;

        try {
            foreach ($forks as ['command' => $fork, 'wrap' => $wrap, 'id' => $id]) {
                if ($id !== null && ($this->running[(string)$id]['command'] ?? null) !== $fork) {
                    continue;
                }
                if ($id !== null) {
                    unset($this->running[(string)$id]);
                }

                $send = function (Message $message) use ($wrap): void {
                    $this->dispatch($wrap === null ? $message : $wrap($message));
                };
                $answer = ForkHandler::answerOf($fork, $send);
                if ($answer !== null) {
                    $send($answer);
                }
            }
        } finally {
            --$this->depth;
        }

        return $this;
    }

    /**
     * @return self<TModel>
     */
    public function fail(EffectId|string $effect, Throwable|string $error): self
    {
        $running = $this->runningAs($effect, Cmd::class);
        $source = match (true) {
            $running['command'] instanceof HttpCmd => ErrorSource::Http,
            $running['command'] instanceof ExecCmd, $running['command'] instanceof StreamCmd => ErrorSource::Process,
            $running['command'] instanceof FiberCmd => ErrorSource::Fiber,
            $running['command'] instanceof ForkCmd => ErrorSource::Fork,
            default => ErrorSource::Command,
        };

        return $this->ended($running, new ErrorMsg(\is_string($error) ? new RuntimeException($error) : $error, $source, $running['id']));
    }

    /**
     * @return self<TModel>
     */
    public function press(string ...$patterns): self
    {
        foreach ($patterns as $pattern) {
            $this->dispatch(UserInput::key($pattern));
        }

        return $this;
    }

    /**
     * @return self<TModel>
     */
    public function type(string $text): self
    {
        $this->refuseTextNoFieldCanTake($text);
        foreach (UserInput::typed($text) as $key) {
            $this->dispatch($key);
        }

        return $this;
    }

    /**
     * Click the current text location plus $offset columns; $nth = 1 selects the second match.
     *
     * @return self<TModel>
     */
    public function click(string $text, int $nth = 0, int $offset = 0): self
    {
        ['row' => $row, 'column' => $column] = $this->screen()->findOrFail($text, $nth);

        return $this->clickAt($column + $offset, $row);
    }

    /**
     * Treat press and release as one action when collecting issued commands.
     *
     * @return self<TModel>
     */
    public function clickAt(int $column, int $row): self
    {
        $this->issued = [];
        ++$this->depth;

        try {
            return $this->dispatch(UserInput::press($column, $row))->dispatch(UserInput::release($column, $row));
        } finally {
            --$this->depth;
        }
    }

    /**
     * @return self<TModel>
     */
    public function drag(int $fromColumn, int $fromRow, int $toColumn, int $toRow): self
    {
        $this->issued = [];
        ++$this->depth;

        try {
            return $this->dispatch(UserInput::press($fromColumn, $fromRow))
                ->dispatch(UserInput::drag($toColumn, $toRow))
                ->dispatch(UserInput::release($toColumn, $toRow));
        } finally {
            --$this->depth;
        }
    }

    /**
     * @return self<TModel>
     */
    public function paste(string $text): self
    {
        return $this->dispatch(UserInput::paste($text));
    }

    /**
     * @return self<TModel>
     */
    public function wheel(string $text, int $notches): self
    {
        ['row' => $row, 'column' => $column] = $this->screen()->findOrFail($text);

        return $this->dispatch(UserInput::wheel($column, $row, $notches));
    }

    /**
     * Deliver the new area as a ResizeMsg, as the runtime does.
     *
     * @return self<TModel>
     */
    public function resize(int $width, int $height): self
    {
        self::assertDimensions($width, $height);
        $this->area = Rect::fromSize($width, $height);

        return $this->dispatch(new ResizeMsg($width, $height));
    }

    /**
     * Moves the clock, so animations turn, toasts expire, a held chord key is handed
     * on alone and every Cmd::after() that comes due fires. Ticks still wait for tick().
     *
     * @return self<TModel>
     */
    public function advance(float $seconds): self
    {
        $this->issued = [];
        $this->now += max(0.0, $seconds);
        $this->toasts->expire($this->now);
        $this->flashes->expire($this->now);
        ++$this->depth;

        try {
            foreach ($this->chordTracker->expired($this->now) as $key) {
                $this->handled($key);
            }
            $this->firedTimers();
        } finally {
            --$this->depth;
        }

        return $this;
    }

    /**
     * @return TModel
     */
    public function model(): Model
    {
        return $this->model;
    }

    public function lastCommand(): ?Cmd
    {
        return $this->lastCommand;
    }

    /**
     * @return list<string> what Cmd::println() printed, every line since the start
     */
    public function printed(): array
    {
        return $this->printed;
    }

    /**
     * Read focus from the harness, which owns it just as the runtime does.
     */
    public function focused(): ?ComponentId
    {
        $this->settleFocus();

        return $this->focus->focused();
    }

    /**
     * Resolve active key hints while drawing, when the tree identifies their owner.
     *
     * @return array<string, string>
     */
    public function focusedHints(): array
    {
        $this->renderFrame();

        return $this->pass->focus->hints();
    }

    public function renderFrame(): Frame
    {
        $this->startedLikeTheProgram();
        $this->settleFocus();
        $this->pass = $this->pass->withTheme(($this->model instanceof ChoosesItsTheme ? $this->model->theme() : null) ?? $this->theme);

        $frame = new Frame($this->area->width, $this->area->height);
        $frame->clear($this->pass->context->theme->base->background);

        $this->pass->begin($this->now);
        $this->pass->focus->focusOn($this->focus);
        $this->model->render($frame, $this->pass);
        $this->toasts->drawOver($frame, $this->pass->context, $this->area);
        $this->keyHelp->drawOver($frame, $this->pass->context, $this->model, $this->focus, $this->area);
        $this->pass->floats->commit();

        return $frame;
    }

    /**
     * @internal the floats the last successful render drew, as input routes over them
     *
     * @return list<FloatPlacement>
     */
    public function floatPlacements(): array
    {
        return $this->pass->floats->placements();
    }

    public function screen(): ScreenBuffer
    {
        return ScreenBuffer::fromFrame($this->renderFrame());
    }

    /**
     * The program runs init() before anything else, so a test that skips it still sees what setup() asked for.
     */
    private function startedLikeTheProgram(): void
    {
        if (!$this->started) {
            $this->init();
        }
    }

    private function refuseTextNoFieldCanTake(string $text): void
    {
        if (!$this->model instanceof App || $this->focused() !== null) {
            return;
        }

        $field = self::unboundTextField($this->model->view());
        if ($field !== null) {
            throw new LogicException(sprintf('Typed "%s", but no component holds the keyboard. The %s on screen is not bound, so it never gets keys: wrap it in Component::bind()', $text, (new ReflectionClass($field))->getShortName()));
        }
    }

    private static function unboundTextField(Widget $widget): ?Widget
    {
        if ($widget instanceof Component) {
            return null;
        }
        if ($widget instanceof TakesText) {
            return $widget;
        }
        foreach ($widget instanceof WidgetContainer ? $widget->children() : [] as $child) {
            $field = self::unboundTextField($child);
            if ($field !== null) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return self<TModel>
     */
    private function handled(Message $routed): self
    {
        if ($this->depth === 0) {
            $this->issued = [];
            $this->quitting = false;
        }
        ++$this->depth;

        try {
            $this->settleFocus();

            $handedOn = $this->keyHelp->handOn($routed, $this->model, $this->focus);
            if ($handedOn === null || $this->toasts->takes($handedOn, $this->area)) {
                return $this;
            }
            $routed = $handedOn;

            $result = $this->model instanceof RoutesFocus
                ? $this->model->update($routed, $this->area, $this->focus)
                : $this->model->update($routed, $this->area);

            /** @var TModel $next */
            $next = self::requireModel($result->model);
            $this->model = $next;
            $this->keepTheWantedTicks();
            $this->lastCommand = $result->command;
            $this->record($this->lastCommand);
            $this->moveFocusFor($this->lastCommand);
            $this->deliverInOrder(self::messagesIn($this->lastCommand));

            // Keep the original action's result when processing its follow-up commands.
            $this->lastCommand = $result->command;
        } finally {
            --$this->depth;
        }

        return $this;
    }

    /**
     * @param list<Message> $messages
     */
    private function deliverInOrder(array $messages): void
    {
        array_push($this->queue, ...$messages);
        if ($this->draining) {
            return;
        }

        $this->draining = true;
        try {
            while (!$this->quitting && $this->queue !== []) {
                $this->dispatch(array_shift($this->queue));
            }
        } finally {
            $this->queue = [];
            $this->draining = false;
        }
    }

    private function keepTheWantedTicks(): void
    {
        if (!$this->model instanceof WantsTicks) {
            return;
        }

        foreach ($this->wantedTicks->follow($this->model->wantedTicks()) as $change) {
            $this->recordOne($change->command, $change->wrap, $change->scope);
        }
    }

    /**
     * Unpack commands as Program does, preserving each effect's ID and message mappings.
     *
     * @param (Closure(Message): Message)|null $wrap
     */
    private function record(?Cmd $command, ?Closure $wrap = null, ?string $scope = null): void
    {
        foreach (CmdLeaf::all($command, $wrap, $scope) as $leaf) {
            $this->recordOne($leaf->command, $leaf->wrap, $leaf->scope);
        }
    }

    /**
     * @param (Closure(Message): Message)|null $wrap
     */
    private function recordOne(Cmd $command, ?Closure $wrap, ?string $scope): void
    {
        $this->issued[] = $command;
        if ($command instanceof QuitCmd) {
            $this->quitting = true;
        }
        if ($command instanceof FiberCmd) {
            $this->fibers[] = ['command' => $command, 'wrap' => $wrap, 'id' => $command->id === null ? null : ($scope === null ? $command->id : $command->id->within($scope))];
        }
        if ($command instanceof ForkCmd) {
            $this->forks[] = ['command' => $command, 'wrap' => $wrap, 'id' => $command->id === null ? null : ($scope === null ? $command->id : $command->id->within($scope))];
        }
        if ($command instanceof ToastCmd) {
            $this->toasts->show($command, $this->now);

            return;
        }
        if ($command instanceof FlashCmd) {
            $flash = $command->within($scope);
            $this->flashes->show($flash->component->value, $flash->key, $flash->seconds, $this->now);

            return;
        }
        if ($command instanceof PrintlnCmd) {
            array_push($this->printed, ...$command->lines);

            return;
        }
        if ($command instanceof CallCmd) {
            $this->calls[] = ['command' => $command, 'wrap' => $wrap, 'scope' => $scope];

            return;
        }
        if ($command instanceof AfterCmd) {
            $this->timers[] = ['command' => $command, 'wrap' => $wrap, 'scope' => $scope, 'due' => $this->now + $command->delay];
        }
        if ($command instanceof CancelScopeCmd && $scope !== null) {
            $this->running = array_filter($this->running, static fn (array $running): bool => !$running['id']->isWithin($scope));

            return;
        }

        $id = match (true) {
            $command instanceof CancelCmd => $command->id,
            $command instanceof NamesItsEffect => $command->effectId(),
            default => null,
        };
        if ($id === null) {
            return;
        }

        $scoped = $scope === null ? $id : $id->within($scope);
        unset($this->running[(string)$scoped]);

        if (!$command instanceof CancelCmd) {
            $this->running[(string)$scoped] = ['command' => $command, 'wrap' => $wrap, 'id' => $scoped];
        }
    }

    /**
     * @param (Closure(Message): Message)|null $wrap
     */
    private function answeredCall(CallCmd $call, ?Closure $wrap, ?string $scope): void
    {
        $this->answered(CallbackAnswer::of($call->callback, ErrorSource::Call, 'Cmd::call()'), $wrap, $scope);
    }

    private function firedTimers(): void
    {
        $due = array_filter($this->timers, fn (array $timer): bool => $timer['due'] <= $this->now);
        usort($due, static fn (array $one, array $other): int => $one['due'] <=> $other['due']);
        $this->timers = array_values(array_filter($this->timers, fn (array $timer): bool => $timer['due'] > $this->now));

        foreach ($due as ['command' => $timer, 'wrap' => $wrap, 'scope' => $scope]) {
            if ($timer->id !== null) {
                $id = (string)($scope === null ? $timer->id : $timer->id->within($scope));
                if (($this->running[$id]['command'] ?? null) !== $timer) {
                    continue;
                }
                unset($this->running[$id]);
            }
            $this->answered(CallbackAnswer::of($timer->then, ErrorSource::Timer, 'Cmd::after()', $timer->id), $wrap, $scope);
        }
    }

    /**
     * @param (Closure(Message): Message)|null $wrap
     */
    private function answered(Message|Cmd|null $answer, ?Closure $wrap, ?string $scope): void
    {
        if ($answer instanceof Cmd) {
            $this->record($answer, $wrap, $scope);
            $this->moveFocusFor($answer, $scope);
            $sent = self::messagesIn($answer);
        } else {
            $sent = $answer === null ? [] : [$answer];
        }

        $this->deliverInOrder(array_map(static fn (Message $message): Message => $wrap === null ? $message : $wrap($message), $sent));
    }

    /**
     * @param class-string<Cmd> $type
     *
     * @return array{command: Cmd, wrap: ?Closure, id: EffectId}
     */
    private function runningAs(EffectId|string $effect, string $type): array
    {
        $this->startedLikeTheProgram();
        $matches = array_filter(
            $this->running,
            static fn (array $running): bool => $effect instanceof EffectId ? $running['id']->equals($effect) : $running['id']->value === $effect,
        );

        if ($matches === []) {
            throw new LogicException(sprintf('No effect named "%s" is running', $effect));
        }
        if (\count($matches) > 1) {
            throw new LogicException(sprintf('"%s" runs in more than one scope (%s); name one with EffectId::named()->within()', $effect, implode(', ', array_keys($matches))));
        }

        $running = reset($matches);
        if (!$running['command'] instanceof $type) {
            throw new LogicException(sprintf('"%s" is a %s, not a %s', $effect, $running['command']::class, $type));
        }

        return $running;
    }

    /**
     * @param array{command: Cmd, wrap: ?Closure, id: EffectId} $running
     *
     * @return self<TModel>
     */
    private function ended(array $running, Message $message): self
    {
        unset($this->running[(string)$running['id']]);

        return $this->delivered($running, $message);
    }

    /**
     * @param array{command: Cmd, wrap: ?Closure, id: EffectId} $running
     *
     * @return self<TModel>
     */
    private function delivered(array $running, Message $message): self
    {
        return $this->dispatch($running['wrap'] === null ? $message : ($running['wrap'])($message));
    }

    /**
     * @return list<Message>
     */
    private static function messagesIn(?Cmd $command): array
    {
        $messages = [];
        foreach (CmdLeaf::all($command) as $leaf) {
            if ($leaf->command instanceof MessageCmd) {
                $messages[] = $leaf->wrap === null ? $leaf->command->message : ($leaf->wrap)($leaf->command->message);
            }
        }

        return $messages;
    }

    /**
     * Announce initial focus too, so containers can scroll the focused child into view.
     */
    private function settleFocus(): void
    {
        $previous = $this->focus;
        $this->focus = $this->resolvedFocus();

        if (!$this->focus->sameAs($previous)) {
            $this->announceArrival(FocusMove::To);
        }
    }

    private function resolvedFocus(): FocusState
    {
        return $this->focus->resolvedAgainst(
            $this->model instanceof RoutesFocus ? $this->model->ringIds($this->focus) : [],
            $this->model instanceof RoutesFocus ? $this->model->reachableIds() : [],
            $this->model instanceof RoutesFocus ? $this->model->enclosingIds(...) : null,
        );
    }

    /**
     * Apply focus commands so later simulated input reaches the correct component.
     */
    private function moveFocusFor(?Cmd $command, ?string $scope = null): void
    {
        foreach (CmdLeaf::all($command, scope: $scope) as $leaf) {
            if (!$leaf->command instanceof FocusCmd) {
                continue;
            }

            $moved = $leaf->command->within($leaf->scope)->applyTo(
                $this->focus,
                $this->model instanceof RoutesFocus ? $this->model->ringIds($this->focus) : [],
                $this->model instanceof RoutesFocus ? $this->model->reachableIds() : null,
            );

            if ($moved === null) {
                continue;
            }

            $this->focus = $moved;
            $this->announceArrival($leaf->command->move);
        }
    }

    private function announceArrival(FocusMove $move): void
    {
        if ($this->focus->focused() === null) {
            return;
        }

        $arrived = new FocusMsg($move);
        $result = $this->model instanceof RoutesFocus
            ? $this->model->update($arrived, $this->area, $this->focus)
            : $this->model->update($arrived, $this->area);

        /** @var TModel $next */
        $next = self::requireModel($result->model);
        $this->model = $next;
        $this->record($result->command);
        $this->moveFocusFor($result->command);

        foreach (self::messagesIn($result->command) as $sent) {
            $this->dispatch($sent);
        }
    }

    private static function requireModel(Reducer $model): Model
    {
        if (!$model instanceof Model) {
            throw new LogicException(sprintf('ModelHarness update must return a Model, got %s', get_debug_type($model)));
        }

        return $model;
    }

    private static function assertDimensions(int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException(sprintf('ModelHarness dimensions must be positive, got %dx%d', $width, $height));
        }
    }
}
