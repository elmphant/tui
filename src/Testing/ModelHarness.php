<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\BatchCmd;
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
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\FocusMove;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\NamesItsEffect;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\FocusMsg;
use Elmphant\Tui\Message\HttpResultMsg;
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
use Elmphant\Tui\Runtime\InputRouter;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\RoutesFocus;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Drives a model's pure update and render paths without starting a terminal.
 *
 * Effects are exposed as values and deliberately never carried out. The two a
 * model needs answered to make progress at all are: moving the keyboard, and a
 * message it sent itself, which go back through the loop as the runtime does them.
 *
 * @template TModel of \Elmphant\Tui\Runtime\Model
 */
final class ModelHarness
{
    private Rect $area;
    private ?Cmd $lastCommand = null;
    private FocusState $focus;
    private readonly RenderPass $pass;
    private int $depth = 0;

    /**
     * @var list<Cmd> what the last action asked for, batches and wraps taken apart
     */
    private array $issued = [];

    /**
     * @var array<string, array{command: Cmd, wrap: ?Closure, id: EffectId}> by scoped id
     */
    private array $running = [];

    /**
     * @param TModel $model
     */
    private function __construct(
        private Model $model,
        int $width,
        int $height,
        Theme $theme,
    ) {
        $this->area = Rect::fromSize($width, $height);
        $this->focus = new FocusState();
        $this->pass = RenderPass::new($theme);
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
     * Stamps the harness area onto key and pointer messages, and wraps a
     * pointer event that lands on a float the last render drew — exactly what
     * the runtime does before a message reaches the model.
     *
     * @return self<TModel>
     */
    public function dispatch(Message $message): self
    {
        $routed = InputRouter::route($message, $this->area, $this->pass->floats->placements());
        if ($routed === null) {
            return $this;
        }
        if ($this->depth === 0) {
            $this->issued = [];
        }
        ++$this->depth;

        try {
            $this->settleFocus();

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

            // What the caller pressed is what it asks about afterwards, so the errands
            // that press left behind do not take the answer's place.
            $this->lastCommand = $result->command;
        } finally {
            --$this->depth;
        }

        return $this;
    }

    /**
     * Every command the last action led to, the errands it caused included, with
     * batches, maps and scopes taken apart.
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
     * Ends a running Cmd::exec() with its output, delivered as the runtime would:
     * under the id it ran with, through every map it was handed through.
     *
     * @return self<TModel>
     */
    public function answer(EffectId|string $effect, string $stdout = '', int $exitCode = 0, string $stderr = ''): self
    {
        $running = $this->runningAs($effect, ExecCmd::class);

        return $this->ended($running, new ProcessResultMsg($stdout, $stderr, $exitCode, $exitCode === 0, $running['id']));
    }

    /**
     * Ends a running Cmd::http() with its response.
     *
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
     * Output from a running Cmd::process(), which keeps running.
     *
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
     * One beat of a running Cmd::tick(), which keeps running.
     *
     * @return self<TModel>
     */
    public function tick(EffectId|string $effect, float $at = 0.0): self
    {
        $running = $this->runningAs($effect, TickCmd::class);

        return $this->delivered($running, new TickMsg($at, $running['id']));
    }

    /**
     * What a running effect produces, as its CommandHandler's $emit would hand
     * it on. The effect keeps running.
     *
     * @return self<TModel>
     */
    public function emit(EffectId|string $effect, Message $message): self
    {
        return $this->delivered($this->runningAs($effect, Cmd::class), $message);
    }

    /**
     * Ends any running effect with the error it could not get past.
     *
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
     * Keys in the spelling Keybindings use, one after the other.
     *
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
        foreach (UserInput::typed($text) as $key) {
            $this->dispatch($key);
        }

        return $this;
    }

    /**
     * Presses and lets go where the text is drawn now, $offset columns to its
     * right; $nth = 1 is its second place on screen.
     *
     * @return self<TModel>
     */
    public function click(string $text, int $nth = 0, int $offset = 0): self
    {
        ['row' => $row, 'column' => $column] = $this->renderScreen()->locate($text, $nth);

        return $this->clickAt($column + $offset, $row);
    }

    /**
     * One action: what it led to, as issued() tells it, is what the press and
     * the release led to together.
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
     * Turns the wheel over the text: notches down, or up where negative.
     *
     * @return self<TModel>
     */
    public function wheel(string $text, int $notches): self
    {
        ['row' => $row, 'column' => $column] = $this->renderScreen()->locate($text);

        return $this->dispatch(UserInput::wheel($column, $row, $notches));
    }

    /**
     * Resizes the terminal the model believes it is running in: the new area
     * arrives as a ResizeMsg, exactly as the runtime delivers it.
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
     * Which component the keyboard reaches, or nobody. The runtime owns this, so
     * a test asks the harness rather than an app that would have to keep a copy.
     */
    public function focused(): ?ComponentId
    {
        $this->settleFocus();

        return $this->focus->focused();
    }

    /**
     * The keys whatever holds the keyboard answers — what a footer would carry.
     * Resolved by drawing, because that is when the tree names them.
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

        $frame = new Frame($this->area->width, $this->area->height);
        $frame->clear($this->pass->context->theme->base->backgroundValue());

        $this->pass->begin(0.0);
        $this->pass->focus->focusOn($this->focus);
        $this->model->render($frame, $this->pass);
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

    /**
     * Takes a command apart the way Program dispatches it, so an effect is held
     * under the id and through the wraps the runtime would run it with.
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
     * The runtime announces its first placement too, and a container that never
     * hears one cannot bring the arriving child into view.
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
        );
    }

    /**
     * The runtime keeps focus itself, and a command is how a model moves it, so
     * the harness has to honour one or a test can never press one key to move
     * the keyboard and a second to use it.
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
            $this->focus = $command->within($scope)->applyTo(
                $this->focus,
                $this->model instanceof RoutesFocus ? $this->model->focusableIds($this->focus) : [],
            );
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
