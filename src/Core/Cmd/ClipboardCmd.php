<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\Cmd;

/**
 * @internal
 */
final readonly class ClipboardCmd extends Cmd
{
    public function __construct(
        public string $text,
    ) {
    }
}
