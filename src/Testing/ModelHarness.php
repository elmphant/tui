<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Closure;
use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Cmd\CallCmd;
use Elmphant\Tui\Core\Cmd\CancelCmd;
use Elmphant\Tui\Core\Cmd\CancelScopeCmd;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\Cmd\HttpCmd;
use Elmphant\Tui\Core\Cmd\MappedCmd;
use Elmphant\Tui\Core\Cmd\MessageCmd;
use Elmphant\Tui\Core\Cmd\ProcessCmd;
use Elmphant\Tui\Core\Cmd\ScopedCmd;
use Elmphant\Tui\Core\Cmd\TickCmd;
use Elmphant\Tui\Core\Cmd\ToastCmd;
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
use Elmphant\Tui\Rendering\Context\FloatPlacement;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\BindsChords;
use Elmphant\Tui\Runtime\ChoosesItsTheme;
use Elmphant\Tui\Runtime\InputRouter;
use Elmphant\Tui\Runtime\KeyHelpOverlay;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\RoutesFocus;
use Elmphant\Tui\Runtime\Toasts;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

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
    private ChordTracker $chordTracker;
    private KeyHelpOverlay $keyHelp;
    private Toasts $toasts;

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
     * @param TModel $model
     */
    private function __construct(
        private Model $model,
        int $width,
        int $height,
        private readonly Theme $theme,
    ) {
        $this->area = Rect::fromSize($width, $height);
        $this->focus = new FocusState();
        $this->pass = RenderPass::new($theme);
        $this->chordTracker = new ChordTracker();
        $this->keyHelp = new KeyHelpOverlay();
        $this->toasts = new Toasts();
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
    public function initialize(): self
    {
        $this->issued = [];
        ++$this->depth;

        try {
            $initCommand = $this->model->init();
            $this->record($initCommand);
            $this->moveFocusFor($initCommand);

            foreach ([new ResizeMsg($this->area->width, $this->area->height), ...self::messagesIn($initCommand)] as $sent) {
                $this->dispatch($sent);
            }
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
        $routed = InputRouter::route($message, $this->area, $this->pass->floats->placements());
        if ($routed === null) {
            return $this;
        }

        $model = $this->model;
        if ($routed instanceof KeyMsg && $model instanceof BindsChords) {
            $this->settleFocus();
            $keys = $this->chordTracker->feed($routed, $model->chords($this->focus), 0.0);

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

        return $this->ended($running, new ProcessResultMsg($stdout, $stderr, $exitCode, $exitCode === 0, $running['id']));
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
        $running = $this->runningAs($effect, ProcessCmd::class);

        return $this->delivered($running, new ProcessOutputMsg($output, $stream, $running['id']));
    }

    /**
     * @return self<TModel>
     */
    public function finish(EffectId|string $effect, int $exitCode = 0): self
    {
        $running = $this->runningAs($effect, ProcessCmd::class);

        return $this->ended($running, new ProcessFinishedMsg($exitCode, $exitCode === 0, $running['id']));
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
     * @return self<TModel>
     */
    public function fail(EffectId|string $effect, Throwable|string $error): self
    {
        $running = $this->runningAs($effect, Cmd::class);
        $source = match (true) {
            $running['command'] instanceof HttpCmd => ErrorSource::Http,
            $running['command'] instanceof ExecCmd, $running['command'] instanceof ProcessCmd => ErrorSource::Process,
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
        ['row' => $row, 'column' => $column] = $this->renderScreen()->locate($text, $nth);

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
            return $this->dispatch(UserInput::click($column, $row))->dispatch(UserInput::release($column, $row));
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
            return $this->dispatch(UserInput::click($fromColumn, $fromRow))
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
        ['row' => $row, 'column' => $column] = $this->renderScreen()->locate($text);

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
        $this->settleFocus();
        $this->pass = $this->pass->withTheme(($this->model instanceof ChoosesItsTheme ? $this->model->theme() : null) ?? $this->theme);

        $frame = new Frame($this->area->width, $this->area->height);
        $frame->clear($this->pass->context->theme->base->backgroundValue());

        $this->pass->begin(0.0);
        $this->pass->focus->focusOn($this->focus);
        $this->model->render($frame, $this->pass);
        $this->toasts->drawOver($frame, $this->pass->context);
        $this->keyHelp->drawOver($frame, $this->pass->context, $this->model, $this->focus);
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

    public function renderScreen(): ScreenBuffer
    {
        return ScreenBuffer::fromFrame($this->renderFrame());
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
        }
        ++$this->depth;

        try {
            $this->settleFocus();

            if ($this->keyHelp->takes($routed, $this->model, $this->focus) || $this->toasts->takes($routed, $this->area)) {
                return $this;
            }

            $result = $this->model instanceof RoutesFocus
                ? $this->model->update($routed, $this->area, $this->focus)
                : $this->model->update($routed, $this->area);

            /** @var TModel $next */
            $next = self::requireModel($result->model);
            $this->model = $next;
            $this->lastCommand = $result->command;
            $this->record($this->lastCommand);
            $this->moveFocusFor($this->lastCommand);

            foreach (self::messagesIn($this->lastCommand) as $sent) {
                $this->dispatch($sent);
            }

            // Keep the original action's result when processing its follow-up commands.
            $this->lastCommand = $result->command;
        } finally {
            --$this->depth;
        }

        return $this;
    }

    /**
     * Unpack commands as Program does, preserving each effect's ID and message mappings.
     *
     * @param (Closure(Message): Message)|null $wrap
     */
    private function record(?Cmd $command, ?Closure $wrap = null, ?string $scope = null): void
    {
        if ($command instanceof MappedCmd) {
            $inner = $command->wrap;
            $this->record($command->inner, $wrap === null ? $inner : static fn (Message $message): Message => $wrap($inner($message)), $scope);

            return;
        }

        if ($command instanceof ScopedCmd) {
            $this->record($command->inner, $wrap, $scope === null ? $command->scope : $scope . '/' . $command->scope);

            return;
        }

        if ($command instanceof BatchCmd) {
            foreach ($command->commands as $child) {
                $this->record($child, $wrap, $scope);
            }

            return;
        }

        if ($command === null) {
            return;
        }

        $this->issued[] = $command;
        if ($command instanceof ToastCmd) {
            $this->toasts->show($command, 0.0);

            return;
        }
        if ($command instanceof CallCmd) {
            $this->calls[] = ['command' => $command, 'wrap' => $wrap, 'scope' => $scope];

            return;
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
        try {
            $result = ($call->callback)();
        } catch (Throwable $error) {
            $result = new ErrorMsg($error, ErrorSource::Call);
        }

        if ($result instanceof Cmd) {
            $this->record($result, $wrap, $scope);
            $this->moveFocusFor($result, $scope);
            $sent = self::messagesIn($result);
        } else {
            $sent = match (true) {
                $result instanceof Message => [$result],
                $result === null => [],
                default => [new ErrorMsg(new UnexpectedValueException('Cmd::call() callback must return Message, Cmd, or null'), ErrorSource::Call)],
            };
        }

        foreach ($sent as $message) {
            $this->dispatch($wrap === null ? $message : $wrap($message));
        }
    }

    /**
     * @param class-string<Cmd> $type
     *
     * @return array{command: Cmd, wrap: ?Closure, id: EffectId}
     */
    private function runningAs(EffectId|string $effect, string $type): array
    {
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
        if ($command instanceof MappedCmd) {
            return array_map($command->wrap, self::messagesIn($command->inner));
        }

        if ($command instanceof ScopedCmd) {
            return self::messagesIn($command->inner);
        }

        if ($command instanceof BatchCmd) {
            return array_merge(...array_map(self::messagesIn(...), $command->commands));
        }

        return $command instanceof MessageCmd ? [$command->message] : [];
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
            $this->model instanceof RoutesFocus ? $this->model->focusableIds($this->focus) : [],
            $this->model instanceof RoutesFocus ? $this->model->reachableIds() : [],
            $this->model instanceof RoutesFocus ? $this->model->enclosingIds(...) : null,
        );
    }

    /**
     * Apply focus commands so later simulated input reaches the correct component.
     */
    private function moveFocusFor(?Cmd $command, ?string $scope = null): void
    {
        if ($command instanceof MappedCmd) {
            $this->moveFocusFor($command->inner, $scope);

            return;
        }

        if ($command instanceof ScopedCmd) {
            $this->moveFocusFor($command->inner, $scope === null ? $command->scope : $scope . '/' . $command->scope);

            return;
        }

        if ($command instanceof BatchCmd) {
            foreach ($command->commands as $child) {
                $this->moveFocusFor($child, $scope);
            }

            return;
        }

        if ($command instanceof FocusCmd) {
            $moved = $command->within($scope)->applyTo(
                $this->focus,
                $this->model instanceof RoutesFocus ? $this->model->focusableIds($this->focus) : [],
                $this->model instanceof RoutesFocus ? $this->model->reachableIds() : null,
            );

            if ($moved === null) {
                return;
            }

            $this->focus = $moved;
            $this->announceArrival($command->move);
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
