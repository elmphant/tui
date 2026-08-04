<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Layout\Rect;
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
 * Commands are exposed as values and are deliberately never executed.
 *
 * @template TModel of Model
 */
final class ModelHarness
{
    private Rect $area;
    private ?Cmd $lastCommand = null;
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
        $result = $this->model->update($routed, $this->area);

        /** @var TModel $next */
        $next = self::requireModel($result->model);
        $this->model = $next;
        $this->lastCommand = $result->command;

        return $this;
    }

    /**
     * @return self<TModel>
     */
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

    public function renderFrame(): Frame
    {
        $frame = new Frame($this->area->width, $this->area->height);

        $this->context->beginFrame(0.0);
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
