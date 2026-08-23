<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Table;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;

final readonly class Row
{
    use Cloneable;

    /**
     * @param list<string|Span> $cells
     */
    public function __construct(
        public array $cells = [],
        public ?Style $style = null,
        public bool $selectable = true,
        public bool $spansAllColumns = false,
    ) {
    }

    public static function new(string|Span ...$cells): self
    {
        return new self(cells: array_values($cells));
    }

    /**
     * A group label laid out across the whole table rather than per column.
     */
    public static function header(string $label): self
    {
        return new self(cells: [$label], selectable: false, spansAllColumns: true);
    }

    public function withStyle(Style $style): self
    {
        return $this->cloneWith(style: $style);
    }

    /**
     * @param list<string|Span> $cells
     */
    public function withCells(array $cells): self
    {
        return $this->cloneWith(cells: $cells);
    }

    public function withSelectable(bool $selectable): self
    {
        return $this->cloneWith(selectable: $selectable);
    }
}
