<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Layout\Rect;

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
