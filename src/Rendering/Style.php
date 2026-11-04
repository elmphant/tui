<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\TerminalText;

final readonly class Style
{
    use Cloneable;

    public function __construct(
        public ?Color $foreground = null,
        public ?Color $background = null,
        public int $attributes = 0,
        public ?string $link = null,
    ) {
    }

    /**
     * The text becomes a link the reader can open, in terminals that support OSC 8; others show the text alone.
     */
    public function withLink(string $url): Style
    {
        $url = TerminalText::singleLine($url);

        return $this->cloneWith(link: $url === '' ? null : $url);
    }

    public function cellAttributes(): int
    {
        return $this->link === null ? $this->attributes : $this->attributes | Hyperlinks::bitsFor($this->link);
    }

    public function withForeground(Color $color): Style
    {
        return $this->cloneWith(foreground: $color);
    }

    public function withBackground(Color $color): Style
    {
        return $this->cloneWith(background: $color);
    }

    public function bold(): Style
    {
        return $this->addAttributes(Attr::BOLD);
    }

    public function faint(): Style
    {
        return $this->addAttributes(Attr::FAINT);
    }

    public function italic(): Style
    {
        return $this->addAttributes(Attr::ITALIC);
    }

    public function underline(): Style
    {
        return $this->addAttributes(Attr::UNDERLINE);
    }

    public function reverse(): Style
    {
        return $this->addAttributes(Attr::REVERSE);
    }

    public function strikethrough(): Style
    {
        return $this->addAttributes(Attr::STRIKETHROUGH);
    }

    public function withAttributes(int $attributes): Style
    {
        return $this->cloneWith(attributes: $attributes);
    }

    public function addAttributes(int $attributes): Style
    {
        return $this->cloneWith(attributes: $this->attributes | $attributes);
    }

    /**
     * Use the panel background and adjust text contrast; pass 3 for quieter secondary text.
     */
    public function readableOn(self $panel, float $minimumRatio = 4.5): Style
    {
        if ($panel->background === null) {
            return $this->addAttributes($panel->attributes & Attr::REVERSE);
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
            link: $this->link ?? $parent->link,
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
            $this->cellAttributes(),
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
