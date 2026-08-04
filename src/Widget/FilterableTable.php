<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Search\SubstringMatcher;

/**
 * A table with a free-text filter over its rows.
 */
final readonly class FilterableTable implements Widget, MeasureHeight
{
    private const BAR_HEIGHT = FilterBar::HEIGHT;
    private const MIN_BAR = FilterBar::MIN_WIDTH;

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    private function __construct(
        private array $headers,
        private array $rows,
        private string $query = '',
        private ?int $column = null,
        private bool $focused = false,
        private ?Matcher $matcher = null,
    ) {
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public static function new(array $headers, array $rows): self
    {
        return new self($headers, $rows);
    }

    public function withQuery(string $query): self
    {
        return new self($this->headers, $this->rows, $query, $this->column, $this->focused, $this->matcher);
    }

    /**
     * Null filters every column.
     */
    public function withColumn(?int $column): self
    {
        return new self($this->headers, $this->rows, $this->query, $column, $this->focused, $this->matcher);
    }

    /**
     * Drawn as a field to type into only while the pane holding it is active.
     */
    public function withFocus(bool $focused): self
    {
        return new self($this->headers, $this->rows, $this->query, $this->column, $focused, $this->matcher);
    }

    public function withMatcher(Matcher $matcher): self
    {
        return new self($this->headers, $this->rows, $this->query, $this->column, $this->focused, $matcher);
    }

    /**
     * How much of the table the filter kept, so a query narrowing to nothing still reads as a query.
     */
    public function summary(): string
    {
        return count($this->matchingRows()) . ' of ' . count($this->rows);
    }

    public function constraint(): Constraint
    {
        return Constraint::min(self::BAR_HEIGHT + 1);
    }

    public function measureHeight(int $width): int
    {
        return self::BAR_HEIGHT + $this->table()->measureHeight($width);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $clip = $frame->clip();

        if ($area->y >= $clip->y && $area->y < $clip->bottom()) {
            $this->renderBar(
                $frame,
                new Rect($area->x, $area->y, $this->barWidth($area->width), self::BAR_HEIGHT),
                $context,
            );
        }

        if ($area->height > self::BAR_HEIGHT) {
            $this->table()->render(
                $frame,
                new Rect($area->x, $area->y + self::BAR_HEIGHT, $area->width, $area->height - self::BAR_HEIGHT),
                $context,
            );
        }
    }

    /**
     * @return list<list<string>>
     */
    public function matchingRows(): array
    {
        if (trim($this->query) === '') {
            return $this->rows;
        }

        $column = $this->column;
        $searched = array_map(
            static fn (array $row): string => $column === null ? implode(' ', $row) : ($row[$column] ?? ''),
            $this->rows,
        );

        $matching = ($this->matcher ?? new SubstringMatcher())->matching($this->query, $searched);
        sort($matching);

        return array_map(fn (int $index): array => $this->rows[$index], $matching);
    }

    private function renderBar(Frame $frame, Rect $area, RenderContext $context): void
    {
        FilterBar::render(
            $frame,
            $area,
            $context,
            FilterBar::field($this->query, $this->focused),
            $this->summary(),
            $this->scopeLabel(),
        );
    }

    /**
     * Only a column the bar can name is labelled. With nothing scoped there is
     * no scope to report, and an accent word the reader cannot change reads as
     * a control that does not respond.
     */
    private function scopeLabel(): ?string
    {
        if ($this->column === null) {
            return null;
        }

        return $this->headers[$this->column] ?? null;
    }

    /**
     * The bar belongs to the table, so it ends where the table does — but never so short that its own controls do not fit.
     */
    private function barWidth(int $available): int
    {
        return max(self::MIN_BAR, min($available, $this->table()->drawnWidth($available)));
    }

    private function table(): StaticTable
    {
        return StaticTable::new($this->headers, $this->matchingRows())
            ->withWidthsFrom($this->rows)
            ->withMinWidth(self::MIN_BAR);
    }
}
