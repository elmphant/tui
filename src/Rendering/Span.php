<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;

final readonly class Span
{
    use Cloneable;

    public function __construct(
        public string $content,
        public ?Style $style = null,
        public ?StyleToken $token = null,
    ) {
    }

    public static function styled(string $content, Style $style): self
    {
        return new self(content: $content, style: $style);
    }

    public static function token(string $content, StyleToken $token): self
    {
        return new self(content: $content, token: $token);
    }

    public static function link(string $content, string $url): self
    {
        return new self(content: $content, style: (new Style())->withLink($url));
    }

    public function bold(): self
    {
        return $this->withAttributes(Attr::BOLD);
    }

    public function faint(): self
    {
        return $this->withAttributes(Attr::FAINT);
    }

    public function italic(): self
    {
        return $this->withAttributes(Attr::ITALIC);
    }

    public function underline(): self
    {
        return $this->withAttributes(Attr::UNDERLINE);
    }

    public function reverse(): self
    {
        return $this->withAttributes(Attr::REVERSE);
    }

    public function strikethrough(): self
    {
        return $this->withAttributes(Attr::STRIKETHROUGH);
    }

    public function withAttributes(int $attributes): self
    {
        return $this->cloneWith(style: ($this->style ?? new Style())->withAttributes($attributes));
    }

    /**
     * Call this before reading $style, or token spans render unstyled.
     */
    public function withResolvedToken(Theme $theme): self
    {
        if ($this->token === null) {
            return $this;
        }

        $resolved = $this->token->resolve($theme);

        return new self(
            content: $this->content,
            style: $this->style?->inherit($resolved) ?? $resolved,
            token: null,
        );
    }

    /**
     * Resolve style tokens before applying the panel background.
     */
    public function readableOn(Style $panel, float $minimumRatio = 4.5): self
    {
        if ($this->style === null) {
            return $this;
        }

        return $this->cloneWith(style: $this->style->readableOn($panel, $minimumRatio));
    }

    public function width(): int
    {
        return CharWidth::stringWidth($this->content);
    }
}
