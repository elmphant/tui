<?php

declare(strict_types=1);

namespace Elmphant\Tui\Application;

use Closure;
use Elmphant\Tui\Core\ComponentId;

/**
 * Provides the app's current keyboard state to message hooks.
 */
final readonly class Keyboard
{
    /**
     * @internal App::onMouse() hands one over
     *
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
     * Active key hints, as shown by FocusHints.
     *
     * @return array<string, string>
     */
    public function hints(): array
    {
        return ($this->hints)();
    }
}
