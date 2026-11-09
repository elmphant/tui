<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Table;

use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Support\Cloneable;

final readonly class Row
{
    use Cloneable;

    /**
     * @param list<string|Span|Line> $cells
     */
    private function __construct(
        public array $cells = [],
        private Style|StyleToken|null $style = null,
        public bool $selectable = true,
        public bool $spansAllColumns = false,
        private ?string $key = null,
        private ?string $filterValue = null,
        public bool $pulsing = false,
    ) {
    }

    public static function new(string|Span|Line ...$cells): self
    {
        return new self(cells: array_values($cells));
    }

    public static function header(string $label): self
    {
        return new self(cells: [$label], selectable: false, spansAllColumns: true);
    }

    /**
     * A token follows the theme, a literal Style survives a theme switch.
     */
    public function withStyle(Style|StyleToken $style): self
    {
        return $this->cloneWith(style: $style);
    }

    public function resolvedStyleIn(Theme $theme): ?Style
    {
        return $this->style instanceof StyleToken ? $this->style->resolve($theme) : $this->style;
    }

    /**
     * Draw the row on a pulsing band of the theme's warning colour until the host takes it away.
     */
    public function withPulsing(bool $pulsing = true): self
    {
        return $this->cloneWith(pulsing: $pulsing);
    }

    /**
     * @param list<string|Span|Line> $cells
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
     * What a filter searches, for text the row does not draw, such as a description.
     */
    public function withFilterValue(?string $filterValue): self
    {
        return $this->cloneWith(filterValue: $filterValue);
    }

    public function filterValue(): string
    {
        return $this->filterValue ?? implode(' ', $this->texts());
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

    private static function textOf(string|Span|Line $cell): string
    {
        return match (true) {
            $cell instanceof Span => $cell->content,
            $cell instanceof Line => implode('', array_map(static fn (Span $span): string => $span->content, $cell->spans)),
            default => $cell,
        };
    }
}
