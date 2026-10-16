<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Table;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\BorderStyle;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\TextWrap;
use Elmphant\Tui\Text\WrapCache;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Overflow;
use Elmphant\Tui\Widget\Widget;

/**
 * A bordered table that draws every row, with no cursor and no scrolling.
 *
 * Columns size themselves to their content, so a table sized for someone else's
 * terminal can be redrawn for this one. Renders the whole data set, so keep it
 * for small, known-size data — when the reader needs to navigate rows, or the row
 * count is unbounded, use Table.
 */
final readonly class StaticTable implements Widget, MeasureHeight
{
    use Cloneable;

    /**
     * Frame, dividers, and one space either side of every cell.
     */
    private const OVERHEAD_PER_COLUMN = 3;

    private const MIN_COLUMN = 4;

    /**
     * @param list<string>        $headers
     * @param list<list<string>>  $rows
     * @param ?list<list<string>> $measured rows the columns are sized for, when not the rows drawn
     */
    private function __construct(
        private array $headers,
        private array $rows,
        private BorderStyle $border = BorderStyle::Solid,
        private Overflow $overflow = Overflow::Stack,
        private ?array $measured = null,
        private int $minWidth = 0,
        private StaticTableMemo $memo = new StaticTableMemo(),
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

    public function withBorder(BorderStyle $border): self
    {
        return $this->rebuiltWith(border: $border);
    }

    /**
     * What to do with the columns that do not fit the width given.
     */
    public function withOverflow(Overflow $overflow): self
    {
        return $this->rebuiltWith(overflow: $overflow);
    }

    /**
     * Size the columns for these rows rather than the ones drawn, so a table
     * showing a subset keeps the layout of the whole.
     *
     * @param list<list<string>> $rows
     */
    public function withWidthsFrom(array $rows): self
    {
        return $this->rebuiltWith(measured: $rows);
    }

    /**
     * A table of one short column looks like a mistake beside a full-width pane.
     */
    public function withMinWidth(int $columns): self
    {
        return $this->rebuiltWith(minWidth: $columns);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex()->withBasis(max($this->minWidth, self::widthOf($this->demands()))),
            Constraint::min(1),
        );
    }

    public function measureHeight(int $width): int
    {
        if ($width < 1) {
            return 0;
        }

        return $this->unwrappedLineCount($width) ?? count($this->linesFor($width));
    }

    /**
     * What the table drew, which is at most the width it was offered.
     */
    public function drawnWidth(int $width): int
    {
        // Only the opening rule is read, and every line is the same width.
        $lines = $this->linesFor($width, 1);

        return $lines === [] ? 0 : min($width, CharWidth::stringWidth($lines[0]));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $style = $context->theme->base;
        $clip = $frame->clip();
        $first = max(0, $clip->y - $area->y);
        $lines = $this->linesFor($area->width, min($area->height, $clip->bottom() - $area->y));
        $last = min(count($lines), $area->height, $clip->bottom() - $area->y);

        $frame->pushClip($area);

        try {
            for ($row = $first; $row < $last; ++$row) {
                $frame->putStyled($area->x, $area->y + $row, $lines[$row], $style);
            }
        } finally {
            $frame->popClip();
        }
    }

    /**
     * Every column is grown from the widest cell it holds, so where the table
     * fits nothing wraps and the lines follow from the row count. Null where the
     * columns had to be squeezed, since then only drawing knows.
     */
    private function unwrappedLineCount(int $width): ?int
    {
        $widest = $this->demands();

        if (self::spaceFor($widest, $width) < 0) {
            return null;
        }

        $rules = $this->headers === [] ? 2 : 3;
        $rowLines = $this->memo->rowLines ??= array_sum(array_map(self::linesOfRow(...), $this->rows));

        return $rules + ($this->headers === [] ? 0 : self::linesOfRow($this->headers)) + $rowLines;
    }

    /**
     * The memo keys on width alone, so a copy must start without it.
     */
    private function rebuiltWith(mixed ...$overrides): self
    {
        return $this->cloneWith(...$overrides, memo: new StaticTableMemo());
    }

    /**
     * 32k rows: 10ms to measure, 2ms to key, and view() rebuilds per frame.
     * Every field the drawing reads belongs in it: the cache outlives the value,
     * so anything left out lets one table be drawn as another.
     */
    private function contentKey(): string
    {
        return $this->memo->keyed ??= hash('xxh3', serialize([
            $this->headers,
            $this->rows,
            $this->measured,
            $this->border,
            $this->overflow,
            $this->minWidth,
        ]));
    }

    /**
     * @return list<string>
     */
    private function linesFor(int $width, ?int $upTo = null): array
    {
        // A window held from an earlier render answers a smaller one, never a
        // larger one and never a question about the whole table.
        $memo = $this->memo;
        if ($memo->builtFor === $width
            && (null === $memo->builtUpTo || (null !== $upTo && $memo->builtUpTo >= $upTo))
        ) {
            return $memo->built;
        }

        /** @var list<string> $lines */
        $lines = WrapCache::remember(
            $this->contentKey() . ':lines:' . $width . ':' . ($upTo ?? 'all'),
            fn (): array => $this->draw($width, $upTo),
        );

        $memo->built = $lines;
        $memo->builtFor = $width;
        $memo->builtUpTo = $upTo;

        return $lines;
    }

    /**
     * The columns are still sized from every row — that is what sizing to their
     * content means — but only the rows $upTo can reach are drawn.
     *
     * @return list<string>
     */
    private function draw(int $width, ?int $upTo = null): array
    {
        $widest = $this->demands();
        $rows = null === $upTo ? $this->rows : array_slice($this->rows, 0, $upTo);

        if (self::spaceFor($widest, $width) >= 0) {
            $widths = self::grown($widest, min($width, max($this->minWidth, self::widthOf($widest))), $widest);

            return $this->drawn($this->headers, $rows, $widths, $upTo);
        }

        $floors = self::capped($this->wordFloors(), $width);

        if (self::spaceFor($floors, $width) < 0 && $this->overflow === Overflow::Stack) {
            $kept = $this->keptColumns($floors, $width);

            if ($kept !== [] && count($kept) < count($floors)) {
                return $this->drawnWithStackedColumns($kept, $floors, $width, $rows, $upTo);
            }
        }

        if (self::spaceFor($floors, $width) < 0) {
            return $this->drawn($this->headers, $rows, $this->sharedWidths($width), $upTo);
        }

        $widths = self::grown($floors, $width, $widest);

        return $this->drawn($this->headers, $rows, $widths, $upTo);
    }

    /**
     * The columns kept in the table: as many as fit at their natural width,
     * cheapest first, with the leading column always among them. Everything else
     * moves under its row as a labelled line.
     *
     * @param list<int> $demands
     *
     * @return list<int> column indexes, in their original order
     */
    private function keptColumns(array $demands, int $width): array
    {
        if (self::spaceFor($demands, $width) >= 0) {
            return array_keys($demands);
        }

        $order = $demands;
        asort($order);
        $kept = [0];

        foreach (array_keys($order) as $column) {
            $candidate = $column === 0 ? $kept : [...$kept, $column];

            if (self::spaceFor(self::at($demands, $candidate, 0), $width) >= 0) {
                $kept = $candidate;
            }
        }

        sort($kept);

        return self::spaceFor(self::at($demands, $kept, 0), $width) >= 0 ? $kept : [];
    }

    /**
     * @param list<int>          $kept
     * @param list<int>          $demands
     * @param list<list<string>> $sourceRows
     *
     * @return list<string>
     */
    private function drawnWithStackedColumns(array $kept, array $demands, int $width, array $sourceRows, ?int $upTo = null): array
    {
        $demoted = array_values(array_diff(array_keys($demands), $kept));
        $kepts = self::at($demands, $kept, 0);
        $widths = self::grown($kepts, $width, [$width, ...array_slice($kepts, 1)]);
        $host = $widths[0];
        $headers = self::at($this->headers, $kept);
        $headers[0] = self::stacked([$headers[0], ...self::at($this->headers, $demoted)], $host);
        $rows = [];

        foreach ($sourceRows as $index => $row) {
            if ($index > 0) {
                $rows[] = null;
            }

            $cells = self::at($row, $kept);
            $cells[0] = self::stacked([$cells[0], ...self::at($row, $demoted)], $host);
            $rows[] = $cells;
        }

        return $this->drawn($headers, $rows, $widths, $upTo);
    }

    /**
     * One field per line inside a single cell, each cut to the column rather
     * than wrapped: a wrapped value would push the ones below it out of line
     * with their labels in the header.
     *
     * @param list<string> $values
     */
    private static function stacked(array $values, int $width): string
    {
        $lines = array_map(
            static fn (string $value): string => TextWrap::ellipsize(trim($value), $width),
            $values,
        );
        $lines[0] = str_pad($lines[0], $width + strlen($lines[0]) - CharWidth::stringWidth($lines[0]));

        return implode("\n", $lines);
    }

    /**
     * @param list<string>            $headers
     * @param list<list<string>|null> $rows    a null row is a rule across the table
     * @param list<int>               $widths
     *
     * @return list<string>
     */
    private function drawn(array $headers, array $rows, array $widths, ?int $upTo = null): array
    {
        return TableGrid::draw($headers, $rows, $widths, $this->border->glyphs(), $upTo);
    }

    /**
     * The table spans the pane once some of its columns moved out of it: the
     * detail lines are the ones that need the room.
     *
     * @param list<int> $widths
     * @param list<int> $wants
     *
     * @return list<int>
     */
    private static function grown(array $widths, int $width, array $wants): array
    {
        $spare = self::spaceFor($widths, $width);

        if ($spare <= 0 || $widths === []) {
            return $widths;
        }

        $needs = array_map(static fn (int $has, int $want): int => max(0, $want - $has), $widths, $wants);
        $total = array_sum($needs);

        foreach ($needs as $column => $need) {
            $widths[$column] += $total === 0 ? 0 : min($need, (int)floor($spare * $need / $total));
        }

        $left = self::spaceFor($widths, $width);
        $widths[$total === 0 ? count($widths) - 1 : (int)array_search(max($needs), $needs, true)] += max(0, $left);

        return array_values($widths);
    }

    /**
     * Columns left over once every given column has its width.
     *
     * @param array<int, int> $widths
     */
    private static function spaceFor(array $widths, int $width): int
    {
        return $width - self::widthOf($widths);
    }

    /**
     * @param array<int, int> $widths
     */
    private static function widthOf(array $widths): int
    {
        return array_sum($widths) + count($widths) * self::OVERHEAD_PER_COLUMN + 1;
    }

    /**
     * @template TValue of string|int
     *
     * @param array<int, TValue> $values
     * @param list<int>          $columns
     * @param TValue             $missing
     *
     * @return list<TValue>
     */
    private static function at(array $values, array $columns, string|int $missing = ''): array
    {
        return array_map(static fn (int $column): string|int => $values[$column] ?? $missing, $columns);
    }

    /**
     * The width each column would take unwrapped, never below what its header
     * needs.
     *
     * @return list<int>
     */
    private function demands(): array
    {
        if (null !== $this->memo->demanded) {
            return $this->memo->demanded;
        }

        /** @var list<int> $demanded */
        $demanded = WrapCache::remember($this->contentKey() . ':columns', $this->measuring(...));

        return $this->memo->demanded = $demanded;
    }

    /**
     * @return list<int>
     */
    private function measuring(): array
    {
        $widest = [];

        foreach ($this->measured ?? $this->rows as $row) {
            foreach ($row as $column => $cell) {
                $width = self::widestLineOf($cell);

                if ($width > ($widest[$column] ?? 0)) {
                    $widest[$column] = $width;
                }
            }
        }

        $demands = [];

        foreach ($this->headers as $column => $header) {
            $demands[] = max(self::widestLineOf($header), $widest[$column] ?? 0);
        }

        return $demands;
    }

    private static function widestLineOf(string $cell): int
    {
        return max(array_map(CharWidth::stringWidth(...), explode("\n", $cell)));
    }

    /**
     * @param list<string> $cells
     */
    private static function linesOfRow(array $cells): int
    {
        return max([1, ...array_map(static fn (string $cell): int => substr_count($cell, "\n") + 1, $cells)]);
    }

    /**
     * A single outlying value must not decide the layout: past its share of the
     * table a column stops asking, and that one value breaks mid-word instead.
     *
     * @param list<int> $floors
     *
     * @return list<int>
     */
    private static function capped(array $floors, int $width): array
    {
        $share = max(self::MIN_COLUMN, intdiv($width, max(1, count($floors))));

        return array_map(static fn (int $floor): int => min($floor, $share), $floors);
    }

    /**
     * The narrowest each column can be without a word of it splitting: what it
     * gives up first when the table has to shrink.
     *
     * @return list<int>
     */
    private function wordFloors(): array
    {
        $floors = $this->columnFloors(count($this->headers));

        foreach ($this->measured ?? $this->rows as $row) {
            foreach ($row as $column => $cell) {
                foreach (self::words($cell) as $word) {
                    $floors[$column] = max($floors[$column] ?? self::MIN_COLUMN, CharWidth::stringWidth($word));
                }
            }
        }

        return array_values($floors);
    }

    /**
     * The last resort: not even one column fits at its natural width, so every
     * column takes an equal share and the content wraps.
     *
     * @return list<int>
     */
    private function sharedWidths(int $width): array
    {
        $count = max(1, count($this->headers));
        $floors = $this->columnFloors($count);
        $budget = $width - ($count * self::OVERHEAD_PER_COLUMN + 1);

        // Past this point the floors are what a reader would like, not what there
        // is: handing them back would draw a table wider than the pane, losing the
        // side of its frame. An even share of what is left keeps it whole — down
        // to a column apiece, below which there is no table to draw and the area
        // cuts off whatever is attempted.
        if ($budget < array_sum($floors)) {
            return $budget < $count ? $floors : self::spread($budget, $count);
        }

        $share = intdiv($budget, $count);
        $widths = array_map(static fn (int $floor): int => max($floor, $share), $floors);
        $last = $count - 1;
        $widths[$last] = max($floors[$last], $widths[$last] + $budget - array_sum($widths));

        return array_values($widths);
    }

    /**
     * $budget columns over $count columns, the remainder going to the leftmost,
     * so the sum is exactly the budget.
     *
     * @return list<int>
     */
    private static function spread(int $budget, int $count): array
    {
        $share = intdiv($budget, $count);
        $remainder = $budget % $count;

        $widths = [];
        for ($column = 0; $column < $count; ++$column) {
            $widths[] = $share + ($column < $remainder ? 1 : 0);
        }

        return $widths;
    }

    /**
     * A column never falls below the longest word of its own title: a header may
     * break where it has a space, but a word cut in half is unreadable.
     *
     * @return list<int>
     */
    private function columnFloors(int $count): array
    {
        $floors = [];

        for ($column = 0; $column < $count; ++$column) {
            $words = self::words($this->headers[$column] ?? '');
            $floors[] = max(self::MIN_COLUMN, ...array_map(CharWidth::stringWidth(...), $words === [] ? [''] : $words));
        }

        return $floors;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/\s+/', trim($text), flags: PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }
}
