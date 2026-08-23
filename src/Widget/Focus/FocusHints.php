<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Bar\StatusBar;
use Elmphant\Tui\Widget\Widget;

/**
 * The keys whatever holds the keyboard answers, asked for at render time so no
 * one has to keep a copy of where the keyboard is.
 */
final readonly class FocusHints implements Widget
{
    private function __construct()
    {
    }

    public static function new(): self
    {
        return new self();
    }

    public function sizing(): Sizing
    {
        return StatusBar::forHints([])->sizing();
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        StatusBar::forHints($context->focusedHints())->render($frame, $area, $context);
    }
}
