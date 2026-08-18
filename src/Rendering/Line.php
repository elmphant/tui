<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Core\Cloneable;

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
        return new self([new Span($content)]);
    }

    public function withResolvedTokens(Theme $theme): self
    {
        $resolved = [];
        foreach ($this->spans as $span) {
            $resolved[] = $span->withResolvedToken($theme);
        }

        return $this->cloneWith(spans: $resolved);
    }

    /**
     * Every span drawn inside a panel. Resolve tokens first: they carry no ink yet.
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
