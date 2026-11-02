<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\InlineScreen;

/**
 * @internal
 */
final readonly class InlineSurface implements Surface
{
    public function __construct(
        private InlineScreen $screen,
    ) {
    }

    public function area(Rect $terminal, Model $model): Rect
    {
        if (!$model instanceof SizesItsRegion) {
            return $terminal;
        }

        return Rect::fromSize($terminal->width, max(1, min($model->wantedHeight($terminal->width), $terminal->height)));
    }

    public function flush(Frame $frame, Rect $area): void
    {
        $this->screen->flush($frame, max($area->height, $frame->drawnHeight()));
    }

    public function forget(): void
    {
    }

    public function printAbove(array $lines, int $width): void
    {
        $this->screen->printAbove($lines, $width);
    }

    public function finish(Closure $drawLastFrame): void
    {
        if (!$this->screen->printedLast()) {
            $drawLastFrame();
        }
    }

    public function pause(): string
    {
        return $this->screen->parkBelow();
    }

    public function leave(): string
    {
        return $this->screen->parkBelow();
    }
}
