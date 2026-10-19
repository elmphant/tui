<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

/**
 * The keys of a chord typed so far, and what each key that can follow them does.
 */
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
