<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Table;

/**
 * @internal
 */
final class StaticTableMemo
{
    /**
     * @param list<string> $built    lines already drawn, for $builtFor columns
     * @param ?list<int>   $demanded column widths already measured
     * @param ?int         $rowLines lines the rows take when nothing wraps
     */
    public function __construct(
        public array $built = [],
        public ?int $builtFor = null,
        public ?int $builtUpTo = null,
        public ?array $demanded = null,
        public ?string $keyed = null,
        public ?int $rowLines = null,
    ) {
    }
}
