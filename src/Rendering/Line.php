<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

final readonly class Line
{
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

        return $resolved === $this->spans ? $this : new self($resolved, $this->style);
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
