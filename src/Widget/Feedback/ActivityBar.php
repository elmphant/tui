<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Feedback;

use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Interactive;

final readonly class ActivityBar implements Interactive
{
    use Cloneable;

    private const TRACK = '─';

    private function __construct(
        private string $label = '',
        private float $speed = 0.8,
        private int $barWidth = 20,
    ) {
    }

    public static function new(string $label = ''): self
    {
        return new self(label: $label);
    }

    public function withBarWidth(int $barWidth): self
    {
        return $this->cloneWith(barWidth: $barWidth);
    }

    public function withLabel(string $label): self
    {
        return $this->cloneWith(label: $label);
    }

    /**
     * Bar sweeps per second: at 1.0 the highlight travels the full bar each second.
     */
    public function withSpeed(float $speed): self
    {
        return $this->cloneWith(speed: max(0.01, $speed));
    }

    public function sizing(): Sizing
    {
        $label = $this->label === '' ? 0 : CharWidth::stringWidth($this->label) + 1;

        return Sizing::of(Constraint::flex()->withBasis($label + $this->barWidth), Constraint::exact(1));
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

        $theme = $context->theme;
        $x = $area->x;
        $labelWidth = 0;

        if ($this->label !== '') {
            $labelWidth = CharWidth::stringWidth($this->label) + 1;
            $frame->putString($x, $area->y, $this->label, $theme->dim->foregroundValue());
            $x += $labelWidth;
        }

        $availableBarWidth = $area->width - $labelWidth;
        $barWidth = min($this->barWidth, $availableBarWidth);
        if ($barWidth < 1) {
            return;
        }

        $lastIndex = $barWidth - 1;
        $position = self::bouncePosition($context->now() * $this->speed);
        $context->requestRepaint(1.0 / ($this->speed * max(1, $lastIndex)));

        $centerColumn = round($position * $lastIndex);
        $accentForeground = $theme->accent->foregroundValue();
        $dimForeground = $theme->dim->foregroundValue();

        for ($column = 0; $column < $barWidth; ++$column) {
            $distance = abs($column - $centerColumn);
            $character = match (true) {
                $distance < 1.0 => '█',
                $distance < 2.0 => '▓',
                $distance < 3.0 => '▒',
                $distance < 4.0 => '░',
                default => self::TRACK,
            };

            $frame->putChar($x + $column, $area->y, $character, $character === self::TRACK ? $dimForeground : $accentForeground);
        }
    }

    /**
     * Triangle wave over sweeps: 0→1 forward, 1→0 back, repeating.
     */
    private static function bouncePosition(float $sweeps): float
    {
        return 1.0 - abs(1.0 - fmod($sweeps, 2.0));
    }
}
