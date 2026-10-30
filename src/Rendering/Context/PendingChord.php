<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

final readonly class PendingChord
{
    /**
     * @param array<string, string> $continuations key label => description
     */
    public function __construct(
        public string $typed,
        public array $continuations,
    ) {
    }
}
