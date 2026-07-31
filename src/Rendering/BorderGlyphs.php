<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering;

final readonly class BorderGlyphs
{
    public function __construct(
        public string $topLeft,
        public string $topRight,
        public string $bottomLeft,
        public string $bottomRight,
        public string $horizontal,
        public string $vertical,
        public string $teeRight,
        public string $teeLeft,
        public string $teeDown,
        public string $teeUp,
        public string $cross = '┼',
    ) {
    }
}
