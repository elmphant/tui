<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\Cmd;

/**
 * @internal
 */
final readonly class LogCmd extends Cmd
{
    /**
     * @param array<string, scalar|null> $context
     */
    public function __construct(
        public string $message,
        public array $context = [],
    ) {
    }
}
