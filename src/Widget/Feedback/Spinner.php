<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Feedback;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Widget;

final readonly class Spinner implements Widget
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

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex()->withBasis($this->inkWidth()), Constraint::exact(1));
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

    /**
     * The widest frame rather than the current one: a width that changed as it
     * animated would shift whatever sits beside it every tick.
     */
    private function inkWidth(): int
    {
        $glyph = 0;

        foreach ($this->style->frames() as $character) {
            $glyph = max($glyph, CharWidth::stringWidth($character));
        }

        return $this->label === '' ? $glyph : $glyph + 1 + CharWidth::stringWidth($this->label);
    }
}
