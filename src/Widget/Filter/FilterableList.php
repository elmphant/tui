<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Filter;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Field\InputDecoration;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Listing\ListItem;
use Elmphant\Tui\Widget\Listing\ListItems;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Popup\DropdownContent;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\PublishesHints;

/**
 * A scrollable list with a free-text filter over it.
 */
final readonly class FilterableList implements Focusable, KeybindingsAware, DropdownContent, Committing, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    /**
     * Actions the list takes; every other key is typing and belongs to the field.
     */
    private const LIST_ACTIONS = [
        Actions::CURSOR_UP,
        Actions::CURSOR_DOWN,
        Actions::PAGE_UP,
        Actions::PAGE_DOWN,
        Actions::SELECT,
    ];

    /**
     * What the query kept, worked out once. Every read of the highlighted item
     * asks for it, and matching a long list is far too costly to repeat per read.
     *
     * @var list<int>
     */
    private array $matching;

    /**
     * @param list<ListItem> $items
     * @param list<int>|null $matching
     */
    private function __construct(
        private array $items,
        private TextInput $field,
        private ListView $list,
        private ?Matcher $matcher = null,
        private string $noMatchText = 'No matches',
        private ?string $selectedValue = null,
        private string $emptyCaption = '',
        ?array $matching = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $clearable = false,
        private ?int $selectedSourceIndex = null,
    ) {
        $this->matching = $matching ?? self::matching($matcher, $field->value(), $items);
    }

    /**
     * @param list<ListItem> $items
     */
    public static function new(array $items): self
    {
        ListItems::assertAll($items, 'FilterableList');

        return new self(
            $items,
            TextInput::new(FilterBar::PLACEHOLDER)->withDecoration(InputDecoration::None),
            ListView::new($items),
        );
    }

    /**
     * @param list<ListItem> $items
     */
    public function withItems(array $items): self
    {
        ListItems::assertAll($items, 'FilterableList');

        return $this->cloneWith(items: $items, matching: null)->reFiltered();
    }

    public function withMatcher(Matcher $matcher): self
    {
        return $this->cloneWith(matcher: $matcher, matching: null)->reFiltered();
    }

    public function withPlaceholder(string $placeholder): self
    {
        return $this->cloneWith(field: $this->field->withPlaceholder($placeholder));
    }

    /**
     * What the list shows where the query kept nothing — not to be confused with
     * withEmptyCaption(), which is what the caption reads when nothing is chosen.
     */
    public function withNoMatchText(string $noMatchText): self
    {
        return $this->cloneWith(noMatchText: $noMatchText);
    }

    public function withEmptyCaption(string $caption): self
    {
        return $this->cloneWith(emptyCaption: $caption);
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            'move' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN],
            'choose' => Actions::SELECT,
            ...($this->query() === '' ? [] : ['clear filter' => [Actions::CLEAR_LINE, Actions::CANCEL]]),
        ]);
    }

    public function query(): string
    {
        return $this->field->value();
    }

    public function withQuery(string $query): self
    {
        if ($query === $this->query()) {
            return $this;
        }

        return $this->cloneWith(field: $this->field->withValue($query), matching: null)->reFiltered();
    }

    /**
     * Highlights the item at $index of the list handed in, ignoring one the
     * query filtered away.
     */
    public function withSourceIndex(?int $index): self
    {
        $kept = array_search($index, $this->matchingIndexes(), true);

        return $kept === false ? $this : $this->cloneWith(list: $this->list->withCursor($kept));
    }

    /**
     * How much of the list the filter kept, so a query narrowing to nothing still reads as a query.
     */
    public function summary(): string
    {
        return $this->list->itemCount() . ' of ' . count($this->items);
    }

    /**
     * The highlighted item's index in the list handed in, not in what the filter kept.
     */
    public function sourceIndex(): ?int
    {
        return $this->matchingIndexes()[$this->list->cursor()] ?? null;
    }

    public function cursorItem(): ?ListItem
    {
        $index = $this->sourceIndex();

        return $index === null ? null : $this->items[$index] ?? null;
    }

    /**
     * An item is chosen by its own filter value, so narrowing the list to
     * nothing cannot erase a choice already made.
     */
    public function withSelectedValue(?string $filterValue): self
    {
        return $this->cloneWith(selectedValue: $filterValue, selectedSourceIndex: null);
    }

    public function selectedItem(): ?ListItem
    {
        if ($this->selectedValue === null) {
            return null;
        }

        $atIndex = $this->selectedSourceIndex === null ? null : ($this->items[$this->selectedSourceIndex] ?? null);

        if ($atIndex !== null && $atIndex->filterValue() === $this->selectedValue) {
            return $atIndex;
        }

        foreach ($this->items as $item) {
            if ($item->filterValue() === $this->selectedValue) {
                return $item;
            }
        }

        return null;
    }

    public function caption(): string
    {
        return $this->selectedItem()?->title() ?? $this->emptyCaption;
    }

    public function hasChoice(): bool
    {
        return $this->selectedItem() !== null;
    }

    public function optionAt(MouseMsg $message, Rect $area): ?string
    {
        $index = $this->list->indexAt($message, $this->listArea($area));

        return $index === null ? null : ($this->matchingItems()[$index] ?? null)?->filterValue();
    }

    public function hasOption(string $option): bool
    {
        foreach ($this->items as $item) {
            if ($item->filterValue() === $option) {
                return true;
            }
        }

        return false;
    }

    public function activated(string $option): static
    {
        if (!$this->hasOption($option)) {
            return $this;
        }

        return $this->withSelectedValue($this->clearable && $this->selectedValue === $option ? null : $option);
    }

    /**
     * For a choice that may also be none: choosing the row already picked puts it
     * back down rather than picking it again.
     *
     * Off by default, because a list that must answer should not be emptiable by a
     * stray press. Nothing picked is a state this list can already hold and name
     * through its empty caption — this is how a reader reaches it.
     */
    public function withClearable(bool $clearable = true): self
    {
        return $this->cloneWith(clearable: $clearable);
    }

    public function popupWidth(): int
    {
        $widest = 0;
        foreach ($this->items as $item) {
            $widest = max($widest, CharWidth::stringWidth($item->title()));
        }

        return $widest;
    }

    public function popupHeight(int $width): int
    {
        $rows = 0;
        foreach ($this->matchingItems() as $item) {
            $rows += $item->height($width);
        }

        return FilterBar::HEIGHT + FilterBar::GAP_BELOW + max(1, $rows);
    }

    /**
     * A query that matched nothing still binds enter to SELECT, so the binding
     * alone would close a popup over nothing.
     */
    public function commits(Message $message): bool
    {
        return $message instanceof KeyMsg
            && $this->effectiveKeybindings()->matches($message, Actions::SELECT)
            && $this->cursorItem() !== null;
    }

    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex(),
            Constraint::min(FilterBar::HEIGHT + FilterBar::GAP_BELOW + 1)->withFlex(),
        );
    }

    /**
     * Only the keys the list navigates with: `space` and Home/End stay with the
     * field, where they type and move the caret.
     */
    public static function defaultKeybindings(): Keybindings
    {
        return (new Keybindings([
            Actions::CURSOR_UP => ['up'],
            Actions::CURSOR_DOWN => ['down'],
            Actions::PAGE_UP => ['shift+up', 'page_up'],
            Actions::PAGE_DOWN => ['shift+down', 'page_down'],
            Actions::SELECT => ['enter'],
        ]))->merge(FilterBar::keybindings())
            ->append(TextInput::defaultKeybindings());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg) {
            return $this->withMouse($message, $area);
        }

        if ($message instanceof PasteMsg) {
            return $this->typedIntoTheField($message, $area);
        }

        if (!$message instanceof KeyMsg) {
            return new UpdateResult($this);
        }

        if ($this->effectiveKeybindings()->matches($message, Actions::CANCEL)) {
            return new UpdateResult($this->query() === '' ? $this : $this->withQuery(''));
        }

        // Choosing is the list's own move, but the choice is this widget's to keep:
        // the rows are whatever the query left, so only a filter value survives it.
        // The row it was made on rides along, so choosing the second of two that
        // read the same picks that one and not the first.
        if ($this->effectiveKeybindings()->matches($message, Actions::SELECT)) {
            $cursor = $this->cursorItem();

            if ($cursor === null) {
                return new UpdateResult($this);
            }

            $value = $cursor->filterValue();
            $clearing = $this->clearable && $this->selectedValue === $value;

            return new UpdateResult($this->selectedAt($clearing ? null : $value, $this->sourceIndex()));
        }

        if ($this->belongsToTheList($message)) {
            $result = (new ChildDispatcher($this->globalKeybindings))->dispatchOne($this->list, $message, $this->listArea($area));

            return new UpdateResult($this->cloneWith(list: $result->child), $result->command);
        }

        return $this->typedIntoTheField($message, $area);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        FilterBar::render($frame, $this->fieldArea($area), $context, $this->fieldDrawnBy($context));

        $body = $this->listArea($area);
        if ($body->height < 1) {
            return;
        }

        if ($this->list->itemCount() === 0) {
            Paragraph::fromLines([new Line([Span::token($this->noMatchText, StyleToken::Dim)])])
                ->render($frame, $body, $context);

            return;
        }

        $this->list->render($frame, $body, $context);
    }

    /**
     * @return UpdateResult<static>
     */
    private function typedIntoTheField(KeyMsg|PasteMsg $message, Rect $area): UpdateResult
    {
        $result = (new ChildDispatcher($this->globalKeybindings))->dispatchOne($this->field, $message, $this->fieldArea($area));
        $field = $result->child;

        return new UpdateResult(
            $field->value() === $this->field->value()
                ? $this->cloneWith(field: $field)
                : $this->cloneWith(field: $field, matching: null)->reFiltered(),
            $result->command,
        );
    }

    private function fieldDrawnBy(RenderContext $context): TextInput
    {
        return $this->field->withDecoration(
            $this->drawsFocused($context) ? InputDecoration::Underline : InputDecoration::None,
        );
    }

    /**
     * The same choice, with the row it was made on. The value still decides:
     * the row is only consulted while it still reads the same, so a list that
     * moved on behind the choice falls back to finding it by value.
     */
    private function selectedAt(?string $filterValue, ?int $index): self
    {
        return $this->cloneWith(
            selectedValue: $filterValue,
            selectedSourceIndex: $filterValue === null ? null : $index,
        );
    }

    /**
     * @param list<ListItem> $items
     *
     * @return list<int>
     */
    private static function matching(?Matcher $matcher, string $query, array $items): array
    {
        if (trim($query) === '') {
            return array_keys($items);
        }

        return FilterBar::matching(
            $matcher,
            $query,
            array_map(static fn (ListItem $item): string => $item->filterValue(), $items),
        );
    }

    private function belongsToTheList(KeyMsg $message): bool
    {
        $bindings = $this->effectiveKeybindings();

        foreach (self::LIST_ACTIONS as $action) {
            if ($bindings->matches($message, $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A click moves the cursor, and nothing else: what this list holds as chosen
     * is its picked item, so a mark the list makes on its own would outlive the
     * cursor and read as a second choice.
     *
     * @return UpdateResult<self>
     */
    private function withMouse(MouseMsg $message, Rect $area): UpdateResult
    {
        $dispatcher = new ChildDispatcher($this->globalKeybindings);
        $field = $dispatcher->dispatchOne($this->field, $message, $this->fieldArea($area));
        $list = $dispatcher->dispatchOne($this->list, $message, $this->listArea($area));

        return new UpdateResult(
            $this->cloneWith(field: $field->child, list: $list->child->withSelected(null)),
            Cmd::combine(array_values(array_filter([$field->command, $list->command]))),
        );
    }

    /**
     * A new query has a new best match, so the cursor goes back to the top of it.
     */
    private function reFiltered(): self
    {
        return $this->cloneWith(
            list: $this->list
                ->withItems($this->matchingItems())
                ->withCursor(0)
                ->withScrollOffset(0),
        );
    }

    /**
     * @return list<ListItem>
     */
    private function matchingItems(): array
    {
        return array_map(fn (int $index): ListItem => $this->items[$index], $this->matchingIndexes());
    }

    /**
     * @return list<int>
     */
    private function matchingIndexes(): array
    {
        return $this->matching;
    }

    private function fieldArea(Rect $area): Rect
    {
        return FilterBar::barArea($area);
    }

    private function listArea(Rect $area): Rect
    {
        return FilterBar::bodyArea($area, FilterBar::GAP_BELOW);
    }
}
