<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Support\Cloneable;

final readonly class Line
{
    use Cloneable;

    /**
     * @param list<Span> $spans
     */
    public function __construct(
        public array $spans,
        public ?Style $style = null,
    ) {
    }

    public static function raw(string $content): self
    {
        return new self([Span::raw($content)]);
    }

    public function resolvedIn(Theme $theme): self
    {
        $resolved = [];
        foreach ($this->spans as $span) {
            $resolved[] = $span->resolvedIn($theme);
        }

        return $this->cloneWith(spans: $resolved);
    }

    /**
     * Resolve style tokens before applying the panel background.
     */
    public function readableOn(Style $panel, float $minimumRatio = 4.5): self
    {
        $placed = [];
        foreach ($this->spans as $span) {
            $placed[] = $span->readableOn($panel, $minimumRatio);
        }

        return $this->cloneWith(spans: $placed);
    }

    public function width(): int
    {
        $width = 0;
        foreach ($this->spans as $span) {
            $width += $span->width();
        }

        return $width;
    }
}
