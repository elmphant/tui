<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Support\Cloneable;

final readonly class Style
{
    use Cloneable;

    public function __construct(
        public ?Color $foreground = null,
        public ?Color $background = null,
        public int $attributes = 0,
    ) {
    }

    public function foreground(Color $color): Style
    {
        return $this->cloneWith(foreground: $color);
    }

    public function background(Color $color): Style
    {
        return $this->cloneWith(background: $color);
    }

    public function bold(): Style
    {
        return $this->withAttributes(Attr::BOLD);
    }

    public function faint(): Style
    {
        return $this->withAttributes(Attr::FAINT);
    }

    public function italic(): Style
    {
        return $this->withAttributes(Attr::ITALIC);
    }

    public function underline(): Style
    {
        return $this->withAttributes(Attr::UNDERLINE);
    }

    public function reverse(): Style
    {
        return $this->withAttributes(Attr::REVERSE);
    }

    public function strikethrough(): Style
    {
        return $this->withAttributes(Attr::STRIKETHROUGH);
    }

    public function withAttributes(int $attributes): Style
    {
        return $this->cloneWith(attributes: $this->attributes | $attributes);
    }

    /**
     * This ink drawn inside a panel: it sits on the panel's background, so it
     * cannot punch a hole in a filled row, and lifts off it far enough to read.
     * Pass 3 for text that is meant to stay quieter than the body.
     */
    public function readableOn(self $panel, float $minimumRatio = 4.5): Style
    {
        if ($panel->background === null) {
            return $this->withAttributes($panel->attributes & Attr::REVERSE);
        }

        return $this->cloneWith(
            foreground: ($this->foreground ?? $panel->foreground)?->readableOn($panel->background, $minimumRatio),
            background: $panel->background,
        );
    }

    public function inherit(self $parent): Style
    {
        return new static(
            foreground: $this->foreground ?? $parent->foreground,
            background: $this->background ?? $parent->background,
            attributes: $this->attributes !== 0 ? $this->attributes : $parent->attributes,
        );
    }

    public function apply(Frame $frame, Rect $area): void
    {
        $frame->fillRect(
            $area->x,
            $area->y,
            $area->width,
            $area->height,
            $this->foregroundValue(),
            $this->backgroundValue(),
            $this->attributes,
        );
    }

    public function foregroundValue(): int
    {
        return $this->foreground === null ? 0 : $this->foreground->value;
    }

    public function backgroundValue(): int
    {
        return $this->background === null ? 0 : $this->background->value;
    }
}
