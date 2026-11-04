<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

/**
 * Apply margin, border and padding in that order, from outside to inside.
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

    /**
     * Drop padding first when decoration would leave no room for content.
     */
    public function fitting(Rect $area): self
    {
        foreach ([$this, new self($this->margin, $this->border)] as $inset) {
            $inner = $inset->inner($area);
            if ($inner->width > 0 && $inner->height > 0) {
                return $inset;
            }
        }

        return new self($this->margin);
    }

    public function inner(Rect $area): Rect
    {
        $inner = $area->inset($this->margin);

        if ($this->border) {
            $inner = $inner->inset(Spacing::all(1));
        }

        return $inner->inset($this->padding);
    }
}
