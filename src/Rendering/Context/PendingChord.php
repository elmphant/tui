<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

final readonly class PendingChord
{
    /**
     * @internal the runtime builds it while a chord is open
     *
     * @param array<string, string> $continuations key label => description
     */
    public function __construct(
        public string $typed,
        public array $continuations,
    ) {
    }
}
