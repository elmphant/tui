<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

use Elmphant\Tui\Terminal\CursorShape;

final readonly class CursorPosition
{
    public function __construct(
        public int $x,
        public int $y,
        public CursorShape $shape,
    ) {
    }
}
