<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Dispatch;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Widget\Interactive;

/**
 * @internal
 *
 * @template-covariant TChild of Interactive
 */
final readonly class BatchDispatchResult
{
    /**
     * @param array<string, TChild> $overrides
     */
    public function __construct(
        public array $overrides,
        public ?Cmd $command,
    ) {
    }

    public function hasChanged(): bool
    {
        return $this->overrides !== [];
    }
}
