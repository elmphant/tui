<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\BorderStyle;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

/**
 * The floating half of a Dropdown. Never `Dropdown` itself — the drain would redraw and requeue it.
 */
final readonly class DropdownPopup implements PointerFloating
{
    private const CHROME = 3;

    private const PLACEHOLDER = 'Filter…';

    private const GAP = 1;

    private const MIN_SIDE = 3;

    /**
     * @param list<string>          $options
     * @param array<string, string> $labels
     */
    private function __construct(
        private array $options,
        private array $labels,
        private int $cursor,
        private string $query,
        private int $scroll,
        private FloatTarget $floatTarget,
    ) {
    }

    /**
     * @param list<string>          $options
     * @param array<string, string> $labels
     */
    public static function of(array $options, array $labels, int $cursor, string $query, int $scroll, FloatTarget $target): self
    {
        return new self($options, $labels, $cursor, $query, $scroll, $target);
    }

    public function target(): FloatTarget
    {
        return $this->floatTarget;
    }

    public function captures(MouseAction $action): bool
    {
        return match ($action) {
            MouseAction::Press, MouseAction::WheelUp, MouseAction::WheelDown => true,
            MouseAction::Motion, MouseAction::Release => false,
        };
    }

    public function placement(Rect $anchor, Rect $viewport): Rect
    {
        $below = max(0, $viewport->bottom() - $anchor->bottom());
        $above = max(0, $anchor->y - $viewport->y);
        $wanted = $this->cappedWantedHeight($viewport->height);

        $opensDown = $wanted <= $below || $below >= $above;
        $height = min($wanted, $opensDown ? $below : $above);

        $width = min($viewport->width, $this->wantedWidth($anchor->width));
        $x = max($viewport->x, min($anchor->x, $viewport->right() - $width));
        $y = $opensDown ? $anchor->bottom() : $anchor->y - $height;

        if ($width < self::MIN_SIDE || $height < self::MIN_SIDE) {
            return new Rect($x, $y, 0, 0);
        }

        return new Rect($x, $y, $width, $height);
    }

    /**
     * How many options the popup shows at this size.
     */
    public function optionRowsIn(Rect $placement): int
    {
        return max(0, $placement->height - self::CHROME - 1);
    }

    /**
     * The option drawn on row $y, or null for the chrome rows and for rows
     * past the end of the drawn window.
     */
    public function optionAt(Rect $placement, int $y): ?string
    {
        $offset = $y - $placement->y;

        if ($offset < self::CHROME || $offset >= $placement->height - 1) {
            return null;
        }

        return $this->options[$this->scroll + $offset - self::CHROME] ?? null;
    }

    public function constraint(): Constraint
    {
        return Constraint::flex();
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 3 || $area->height < 3) {
            return;
        }

        $context->theme->base->apply($frame, $area);
        Block::new()->border(BorderStyle::Rounded)->borderStyle($context->theme->borderStyle)->render($frame, $area);

        $inner = $area->shrink(1, 1, 1, 1);

        $count = (string)count($this->options);
        $countWidth = mb_strlen($count);
        $fieldWidth = $inner->width - ($countWidth + self::GAP);

        if ($fieldWidth <= 0) {
            $fieldWidth = $inner->width;
            $countWidth = 0;
        }

        TextInput::new(self::PLACEHOLDER)
            ->withValue($this->query)
            ->withFocus(true)
            ->withDecoration(InputDecoration::Underline)
            ->render($frame, new Rect($inner->x, $inner->y, $fieldWidth, 1), $context);

        if ($countWidth > 0) {
            $frame->putStyled($inner->right() - $countWidth, $inner->y, $count, $context->theme->dim);
        }

        if ($inner->height >= 2) {
            $frame->putString($inner->x, $inner->y + 1, str_repeat('─', $inner->width));
        }

        $rows = $inner->height - 2;
        $window = array_slice($this->options, $this->scroll, max(0, $rows));

        foreach ($window as $offset => $option) {
            $index = $this->scroll + $offset;

            $frame->putStyled(
                $inner->x,
                $inner->y + 2 + $offset,
                CharWidth::truncateToWidth($this->labels[$option] ?? $option, $inner->width),
                $context->theme->optionStyle($index === $this->cursor, false, true),
            );
        }
    }

    private function cappedWantedHeight(int $viewportHeight): int
    {
        $half = intdiv($viewportHeight, 2);

        return min($this->wantedHeight(), max($half, self::CHROME + 2));
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
