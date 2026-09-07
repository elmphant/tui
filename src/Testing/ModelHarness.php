<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Cmd\FocusCmd;
use Elmphant\Tui\Core\Cmd\MappedCmd;
use Elmphant\Tui\Core\Cmd\MessageCmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusMove;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\RoutesFocus;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\FocusMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Rendering\FloatPlacement;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\InputRouter;
use InvalidArgumentException;
use LogicException;

/**
 * Drives a model's pure update and render paths without starting a terminal.
 *
 * Effects are exposed as values and deliberately never carried out. The two a
 * model needs answered to make progress at all are: moving the keyboard, and a
 * message it sent itself, which go back through the loop as the runtime does them.
 *
 * @template TModel of Model
 */
final class ModelHarness
{
    private Rect $area;
    private ?Cmd $lastCommand = null;
    private FocusState $focus;
    private readonly RenderContext $context;

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
        $this->context = new RenderContext($theme);
    }

    /**
     * @template T of Model
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
        $this->lastCommand = $this->model->init();

        $this->moveFocusFor($this->lastCommand);

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
        $routed = InputRouter::route($message, $this->area, $this->context->floatPlacements());
        if ($routed === null) {
            return $this;
        }
        $this->settleFocus();

        $result = $this->model instanceof RoutesFocus
            ? $this->model->update($routed, $this->area, $this->focus)
            : $this->model->update($routed, $this->area);

        /** @var TModel $next */
        $next = self::requireModel($result->model);
        $this->model = $next;
        $this->lastCommand = $result->command;
        $this->moveFocusFor($this->lastCommand);

        foreach (self::messagesIn($this->lastCommand) as $sent) {
            $this->dispatch($sent);
        }

        // What the caller pressed is what it asks about afterwards, so the errands
        // that press left behind do not take the answer's place.
        $this->lastCommand = $result->command;

        return $this;
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

        return $this->context->focusedHints();
    }

    public function renderFrame(): Frame
    {
        $this->settleFocus();

        $frame = new Frame($this->area->width, $this->area->height);

        $this->context->beginFrame(0.0);
        $this->context->focusOn($this->focus);
        $this->model->render($frame, $this->context);
        $this->context->commitFloatPlacements();

        return $frame;
    }

    /**
     * @internal the floats the last successful render drew, as input routes over them
     *
     * @return list<FloatPlacement>
     */
    public function floatPlacements(): array
    {
        return $this->context->floatPlacements();
    }

    public function renderScreen(): ScreenBuffer
    {
        return ScreenBuffer::fromFrame($this->renderFrame());
    }

    /**
     * @return list<Message>
     */
    private static function messagesIn(?Cmd $command): array
    {
        if ($command instanceof MappedCmd) {
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
    private function moveFocusFor(?Cmd $command): void
    {
        if ($command instanceof MappedCmd) {
            $this->moveFocusFor($command->inner);

            return;
        }

        if ($command instanceof BatchCmd) {
            foreach ($command->commands as $child) {
                $this->moveFocusFor($child);
            }

            return;
        }

        if ($command instanceof FocusCmd) {
            $this->focus = $command->applyTo(
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

        $arrived = new FocusMsg(true, $move);
        $result = $this->model instanceof RoutesFocus
            ? $this->model->update($arrived, $this->area, $this->focus)
            : $this->model->update($arrived, $this->area);

        /** @var TModel $next */
        $next = self::requireModel($result->model);
        $this->model = $next;
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
