<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Filter;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Search\SubstringMatcher;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Field\InputDecoration;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Table\StaticTable;

/**
 * A table with a free-text filter over its rows.
 */
final readonly class FilterableTable implements Focusable, MeasureHeight, KeybindingsAware, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

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
        private bool $focused = false,
        private ?Matcher $matcher = null,
        private ?TextInput $field = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    public static function defaultKeybindings(): Keybindings
    {
        return FilterBar::keybindings()->append(TextInput::defaultKeybindings());
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints(
            $this->query === '' ? [] : ['clear filter' => [Actions::CLEAR_LINE, Actions::CANCEL]],
        );
    }

    /**
     * The field a host is already editing, so the caret sits where the reader put
     * it instead of being rebuilt at the end of the text on every frame.
     */
    public function withField(TextInput $field): self
    {
        return $this->cloneWith(query: $field->value(), field: $field);
    }

    public function field(): TextInput
    {
        return $this->fieldFocused($this->focused);
    }

    public function query(): string
    {
        return $this->query;
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
        return $this->cloneWith(query: $query);
    }

    /**
     * Drawn as a field to type into only while the pane holding it is active.
     */
    public function withFocus(bool $focused): static
    {
        return $this->cloneWith(focused: $focused);
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$message instanceof KeyMsg || !$this->focused) {
            return new UpdateResult($this);
        }

        if ($this->effectiveKeybindings()->matches($message, Actions::CANCEL)) {
            return new UpdateResult($this->query === '' ? $this : $this->withQuery(''));
        }

        $result = (new ChildDispatcher($this->globalKeybindings))->dispatchOne($this->field(), $message, $this->fieldArea($area));

        return new UpdateResult($this->withField($result->child), $result->command);
    }

    public function withMatcher(Matcher $matcher): self
    {
        return $this->cloneWith(matcher: $matcher);
    }

    /**
     * How much of the table the filter kept, so a query narrowing to nothing still reads as a query.
     */
    public function summary(): string
    {
        return $this->summaryOf($this->matchingRows());
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::min(self::BAR_HEIGHT + 1));
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

        // Filtered once and handed to both halves: the bar reports what the
        // table shows, so running the query twice could only make them differ.
        $matching = $this->matchingRows();
        $table = $this->tableOf($matching);
        $clip = $frame->clip();

        if ($area->y >= $clip->y && $area->y < $clip->bottom()) {
            $this->renderBar(
                $frame,
                new Rect($area->x, $area->y, $this->barWidth($area->width, $table), self::BAR_HEIGHT),
                $context,
                $this->summaryOf($matching),
            );
        }

        if ($area->height > self::BAR_HEIGHT) {
            $table->render(
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

        $searched = array_map(static fn (array $row): string => implode(' ', $row), $this->rows);

        // A matcher may name the same row twice — nothing in the port forbids
        // it — and an index past the rows names no row at all.
        $matching = array_values(array_unique(array_filter(
            ($this->matcher ?? new SubstringMatcher())->matching($this->query, $searched),
            fn (int $index): bool => isset($this->rows[$index]),
        )));

        return array_map(fn (int $index): array => $this->rows[$index], $matching);
    }

    private function fieldFocused(bool $focused): TextInput
    {
        if ($this->field === null) {
            return FilterBar::field($this->query, $focused);
        }

        // Re-valued only when a host changed the query underneath it: doing so every
        // frame would drag the caret back to the end each time.
        $field = $this->field->value() === $this->query
            ? $this->field
            : $this->field->withValue($this->query);

        return $field
            ->withFocus($focused)
            ->withDecoration($focused ? InputDecoration::Underline : InputDecoration::None);
    }

    private function renderBar(Frame $frame, Rect $area, RenderContext $context, string $summary): void
    {
        FilterBar::render(
            $frame,
            $area,
            $context,
            $this->fieldFocused($this->drawsFocused($context)),
            $summary,
        );
    }

    /**
     * @param list<list<string>> $matching
     */
    private function summaryOf(array $matching): string
    {
        return count($matching) . ' of ' . count($this->rows);
    }

    /**
     * The bar belongs to the table, so it ends where the table does — but never so short that its own controls do not fit.
     */
    private function fieldArea(Rect $area): Rect
    {
        return new Rect($area->x, $area->y, $this->barWidth($area->width, $this->table()), self::BAR_HEIGHT);
    }

    private function barWidth(int $available, StaticTable $table): int
    {
        return max(self::MIN_BAR, min($available, $table->drawnWidth($available)));
    }

    private function table(): StaticTable
    {
        return $this->tableOf($this->matchingRows());
    }

    /**
     * @param list<list<string>> $matching
     */
    private function tableOf(array $matching): StaticTable
    {
        return StaticTable::new($this->headers, $matching)
            ->withWidthsFrom($this->rows)
            ->withMinWidth(self::MIN_BAR);
    }
}
