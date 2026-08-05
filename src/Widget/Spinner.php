<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

final readonly class Spinner implements Interactive
{
    use Cloneable;

    private function __construct(
        private string $label = '',
        private SpinnerStyle $style = SpinnerStyle::Braille,
        private float $interval = 0.08,
    ) {
    }

    public static function new(string $label = ''): self
    {
        return new self(label: $label);
    }

    public function withStyle(SpinnerStyle $style): static
    {
        return $this->cloneWith(style: $style);
    }

    public function withLabel(string $label): self
    {
        return $this->cloneWith(label: $label);
    }

    /**
     * Seconds each spinner frame stays visible.
     */
    public function withInterval(float $seconds): self
    {
        return $this->cloneWith(interval: max(0.01, $seconds));
    }

    public function constraint(): Constraint
    {
        return Constraint::exact(1);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->height < 1 || $area->width < 1) {
            return;
        }

        $context->requestRepaint($this->interval);

        $frames = $this->style->frames();
        $index = (int)floor($context->now() / $this->interval) % count($frames);
        $character = $frames[$index];
        $width = $frame->putStyled($area->x, $area->y, $character, $context->theme->accent);

        if ($this->label !== '') {
            $frame->putStyled($area->x + $width + 1, $area->y, $this->label, $context->theme->base);
        }
    }
}
