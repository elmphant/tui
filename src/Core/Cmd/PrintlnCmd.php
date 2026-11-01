<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\Cmd;

final readonly class PrintlnCmd extends Cmd
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public array $lines,
    ) {
    }
}
