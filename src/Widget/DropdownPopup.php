<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

/**
 * The floating half of a Dropdown. Never `Dropdown` itself — the drain would redraw and requeue it.
 */
final readonly class DropdownPopup implements Floating
{
    public const CHROME = 3;

    /**
     * @param list<string>          $options
     * @param array<string, string> $labels
     */
    private function __construct(
        private array $options,
        private array $labels,
    ) {
    }

    /**
     * @param list<string>          $options
     * @param array<string, string> $labels
     */
    public static function of(array $options, array $labels, int $cursor, string $query, int $scroll): self
    {
        return new self($options, $labels);
    }

    public function placement(Rect $anchor, Rect $viewport): Rect
    {
        $below = max(0, $viewport->bottom() - $anchor->bottom());
        $above = max(0, $anchor->y - $viewport->y);
        $wanted = $this->wantedHeight();

        $opensDown = $wanted <= $below || $below >= $above;
        $height = min($wanted, $opensDown ? $below : $above);

        $width = min($viewport->width, $this->wantedWidth($anchor->width));
        $x = max($viewport->x, min($anchor->x, $viewport->right() - $width));

        return new Rect(
            $x,
            $opensDown ? $anchor->bottom() : $anchor->y - $height,
            $width,
            $height,
        );
    }

    public function constraint(): Constraint
    {
        return Constraint::flex();
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }

    private function wantedHeight(): int
    {
        return self::CHROME + 1 + count($this->options);
    }

    private function wantedWidth(int $anchorWidth): int
    {
        $widest = 0;

        foreach ($this->options as $option) {
            $widest = max($widest, CharWidth::stringWidth($this->labels[$option] ?? $option));
        }

        return max($anchorWidth, $widest + 2);
    }
}
