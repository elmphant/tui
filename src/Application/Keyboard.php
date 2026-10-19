<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Closure;
use Elmphant\Tui\Core\ComponentId;

/**
 * Where the keyboard is for the message a hook is handed, asked of the app on demand.
 */
final readonly class Keyboard
{
    /**
     * @param Closure(): array<string, string> $hints
     */
    public function __construct(
        private ?ComponentId $focused,
        private Closure $hints,
    ) {
    }

    public function focused(): ?ComponentId
    {
        return $this->focused;
    }

    /**
     * The keys whatever holds the keyboard answers, as `FocusHints` shows them.
     *
     * @return array<string, string>
     */
    public function hints(): array
    {
        return ($this->hints)();
    }
}
