<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

final readonly class CursorPosition
{
    public function __construct(
        public int $row,
        public int $col,
        public CursorShape $shape,
    ) {
    }
}
