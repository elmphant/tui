<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

/**
 * How much of its own area a frame takes before its child's area begins.
 *
 * The three are taken in the order the terminal draws them: the margin holds
 * space outside the frame, the border occupies one cell on each side it is
 * drawn, and the padding holds space inside it.
 *
 * @internal
 */
final readonly class Inset
{
    public Spacing $margin;

    public Spacing $padding;

    public function __construct(
        ?Spacing $margin = null,
        public bool $border = false,
        ?Spacing $padding = null,
    ) {
        $this->margin = $margin ?? Spacing::none();
        $this->padding = $padding ?? Spacing::none();
    }

    public function inner(Rect $area): Rect
    {
        $inner = $this->margin->applyTo($area);

        if ($this->border) {
            $inner = $inner->inner();
        }

        return $this->padding->applyTo($inner);
    }
}
