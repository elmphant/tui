<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Closure;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseMsg;

/**
 * What a hook acts under: the screen, who holds the keyboard and what lies under the pointer, asked on demand.
 */
final readonly class InputContext
{
    /**
     * @internal App hands one to each hook
     *
     * @param Closure(): array<string, string> $hints
     * @param Closure(MouseMsg): ?ComponentId  $componentAt
     */
    public function __construct(
        private Rect $screen,
        private ?ComponentId $focused,
        private Closure $hints,
        private Closure $componentAt,
    ) {
    }

    public function screen(): Rect
    {
        return $this->screen;
    }

    public function focused(): ?ComponentId
    {
        return $this->focused;
    }

    /**
     * Active key hints, as shown by FocusHints.
     *
     * @return array<string, string>
     */
    public function hints(): array
    {
        return ($this->hints)();
    }

    /**
     * The innermost bound component drawn under the pointer, or null where none is.
     */
    public function componentAt(MouseMsg $message): ?ComponentId
    {
        return ($this->componentAt)($message);
    }
}
