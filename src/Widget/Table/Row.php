<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Table;

use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Support\Cloneable;

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
        private ?string $key = null,
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

    /**
     * What the cursor and the selection follow across withRows(), for a row
     * whose cells change while it stays the same row: a process by its pid.
     */
    public function withKey(?string $key): self
    {
        return $this->cloneWith(key: $key);
    }

    /**
     * The key given, else the row's cell text.
     */
    public function key(): string
    {
        return $this->key ?? implode("\x1F", array_map(
            static fn (string|Span $cell): string => $cell instanceof Span ? $cell->content : $cell,
            $this->cells,
        ));
    }
}
