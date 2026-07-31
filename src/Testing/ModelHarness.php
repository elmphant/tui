<?php

declare(strict_types=1);

namespace Elmphant\Tui\Testing;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\ResizeMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
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
     * @return self<TModel>
     */
    public function dispatch(Message $message): self
    {
        $result = $this->model->update($message, $this->area);

        // @phpstan-ignore instanceof.alwaysTrue (runtime guard for implementations that violate their PHPDoc contract)
        if (!$result->model instanceof Model) {
            throw new LogicException(sprintf('ModelHarness update must return a Model, got %s', get_debug_type($result->model)));
        }

        /** @var TModel $next */
        $next = $result->model;
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
        $this->model->render($frame, new RenderContext($this->theme));

        return $frame;
    }

    public function renderScreen(): ScreenBuffer
    {
        return ScreenBuffer::fromFrame($this->renderFrame());
    }

    private static function assertDimensions(int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException(sprintf('ModelHarness dimensions must be positive, got %dx%d', $width, $height));
        }
    }
}
