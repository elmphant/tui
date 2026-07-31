<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

/**
 * @internal
 */
final readonly class BatchCmd extends Cmd
{
    /**
     * @param list<Cmd> $commands
     */
    public function __construct(
        public array $commands,
    ) {
    }
}
