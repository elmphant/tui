<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

/**
 * Where a program draws. An inline region does not know which terminal rows it
 * sits on, so a click has nowhere to land, and inline() takes no mouse.
 */
final readonly class ScreenMode
{
    private function __construct(
        private bool $alternate,
        private bool $inline,
        private bool $mouse,
    ) {
    }

    /**
     * The whole terminal, and the app's last frame stays in the scrollback on exit.
     */
    public static function main(bool $mouse = false): self
    {
        return new self(alternate: false, inline: false, mouse: $mouse);
    }

    /**
     * The whole terminal on the alternate screen, and the shell comes back on exit.
     */
    public static function alternate(bool $mouse = false): self
    {
        return new self(alternate: true, inline: false, mouse: $mouse);
    }

    /**
     * A region below the prompt, as tall as the app asks for.
     */
    public static function inline(): self
    {
        return new self(alternate: false, inline: true, mouse: false);
    }

    public function isAlternate(): bool
    {
        return $this->alternate;
    }

    public function isInline(): bool
    {
        return $this->inline;
    }

    public function tracksTheMouse(): bool
    {
        return $this->mouse;
    }
}
