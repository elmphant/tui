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
     */
    public function __construct(
        public array $built = [],
        public ?int $builtFor = null,
        public ?int $builtUpTo = null,
        public ?array $demanded = null,
        public ?string $keyed = null,
    ) {
    }
}
