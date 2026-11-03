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
    private function __construct(
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
     * Use a stable key to preserve cursor and selection when cell contents change.
     */
    public function withKey(?string $key): self
    {
        return $this->cloneWith(key: $key);
    }

    public function key(): string
    {
        return $this->key ?? implode("\x1F", $this->texts());
    }

    /**
     * @return list<string>
     */
    public function texts(): array
    {
        return array_map(self::textOf(...), $this->cells);
    }

    public function textAt(int $column): string
    {
        return self::textOf($this->cells[$column] ?? '');
    }

    private static function textOf(string|Span $cell): string
    {
        return $cell instanceof Span ? $cell->content : $cell;
    }
}
