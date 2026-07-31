<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

final readonly class Spacer implements Widget
{
    private function __construct(
        private int $size,
    ) {
    }

    public static function new(): self
    {
        return new self(1);
    }

    public static function of(int $size): self
    {
        return new self($size);
    }

    public function constraint(): Constraint
    {
        return Constraint::exact($this->size);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
