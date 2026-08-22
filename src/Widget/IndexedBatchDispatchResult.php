<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cmd;

/**
 * @internal
 *
 * @template-covariant TChild of Interactive
 */
final readonly class IndexedBatchDispatchResult
{
    /**
     * @param array<int, TChild> $overrides
     */
    public function __construct(
        public array $overrides,
        public ?Cmd $command,
    ) {
    }

    public function changed(): bool
    {
        return $this->overrides !== [];
    }
}
