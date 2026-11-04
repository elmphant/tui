<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Prose;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Widget;

final readonly class Spacer implements Widget
{
    private function __construct(
        private int $size,
    ) {
    }

    public static function new(int $size = 1): self
    {
        return new self($size);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact($this->size), Constraint::exact($this->size));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
