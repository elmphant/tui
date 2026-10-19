<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Filter;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Input\TakesText;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Support\Cloneable;
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
final readonly class FilterableTable implements Focusable, MeasureHeight, KeybindingsAware, PublishesHints, TakesText
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;
    use SearchMode;

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    private function __construct(
        private array $headers,
        private array $rows,
        private TextInput $field,
        private ?Matcher $matcher = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $searching = false,
    ) {
    }

    /**
     * Enter only hands the keys back from a search; the field has no use for it.
     */
    public static function defaultKeybindings(): Keybindings
    {
        return FilterBar::keybindings()
            ->append(new Keybindings([Actions::SELECT => ['enter']]))
            ->append(TextInput::defaultKeybindings());
    }

    public function hints(): array
    {
        return $this->searchHints(
            $this->query() === '' ? [] : ['clear filter' => $this->searchIsBound() ? Actions::CANCEL : [Actions::CLEAR_LINE, Actions::CANCEL]],
        );
    }

    /**
     * The field a host is already editing, so the caret sits where the reader put
     * it instead of being rebuilt at the end of the text on every frame.
     */
    public function withField(TextInput $field): self
    {
        return $this->cloneWith(field: $field);
    }

    public function field(): TextInput
    {
        return $this->field;
    }

    public function query(): string
    {
        return $this->field->value();
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public static function new(array $headers, array $rows): self
    {
        return new self($headers, $rows, FilterBar::field(''));
    }

    public function withQuery(string $query): self
    {
        return $query === $this->query() ? $this : $this->cloneWith(field: $this->field->withValue($query));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$message instanceof KeyMsg && !$message instanceof PasteMsg) {
            return new UpdateResult($this);
        }

        $toggled = $message instanceof KeyMsg ? $this->searchToggledBy($message) : null;
        if ($toggled !== null) {
            return new UpdateResult($toggled);
        }

        if ($message instanceof KeyMsg && $this->effectiveKeybindings()->matches($message, Actions::CANCEL)) {
            return new UpdateResult($this->withQuery(''));
        }

        if ($message instanceof KeyMsg && !$this->takesText()) {
            return new UpdateResult($this);
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
        return Sizing::of(Constraint::flex(), Constraint::min(FilterBar::HEIGHT + 1));
    }

    public function measureHeight(int $width): int
    {
        return FilterBar::HEIGHT + $this->table()->measureHeight($width);
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
                FilterBar::barArea($area, $this->barWidth($area->width, $table)),
                $context,
                $this->summaryOf($matching),
            );
        }

        if ($area->height > FilterBar::HEIGHT) {
            $table->render($frame, FilterBar::bodyArea($area), $context);
        }
    }

    /**
     * @return list<list<string>>
     */
    public function matchingRows(): array
    {
        if (trim($this->query()) === '') {
            return $this->rows;
        }

        $searched = array_map(static fn (array $row): string => implode(' ', $row), $this->rows);

        return array_map(
            fn (int $index): array => $this->rows[$index],
            FilterBar::matching($this->matcher, $this->query(), $searched),
        );
    }

    private function fieldFocused(bool $focused): TextInput
    {
        return $this->field->withDecoration($focused ? InputDecoration::Underline : InputDecoration::None);
    }

    private function renderBar(Frame $frame, Rect $area, RenderContext $context, string $summary): void
    {
        $bar = fn () => FilterBar::render(
            $frame,
            $area,
            $context,
            $this->fieldFocused($this->drawsFocused($context)),
            $summary,
        );

        $this->fieldHoldsTheKeys($context) ? $bar() : $context->withoutFocus($bar);
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
        return FilterBar::barArea($area, $this->barWidth($area->width, $this->table()));
    }

    private function barWidth(int $available, StaticTable $table): int
    {
        return max(FilterBar::MIN_WIDTH, min($available, $table->drawnWidth($available)));
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
            ->withMinWidth(FilterBar::MIN_WIDTH);
    }
}
