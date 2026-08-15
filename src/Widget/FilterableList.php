<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\Cmd;
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
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Runtime\ChildDispatcher;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Search\SubstringMatcher;

/**
 * A scrollable list with a free-text filter over it.
 */
final readonly class FilterableList implements Focusable, KeybindingsAware, DropdownContent, Committing
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    private const GAP_HEIGHT = 1;

    /**
     * Actions the list takes; every other key is typing and belongs to the field.
     */
    private const LIST_ACTIONS = [
        Actions::FOCUS_PREV,
        Actions::FOCUS_NEXT,
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
        private string $emptyText = 'No matches',
        private ?string $picked = null,
        private string $emptyCaption = '',
        ?array $matching = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $clearable = false,
        private ?int $pickedIndex = null,
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

    public function withEmptyText(string $emptyText): self
    {
        return $this->cloneWith(emptyText: $emptyText);
    }

    public function withEmptyCaption(string $caption): self
    {
        return $this->cloneWith(emptyCaption: $caption);
    }

    public function isFocused(): bool
    {
        return $this->field->isFocused();
    }

    public function withFocus(bool $focused): static
    {
        return $this->cloneWith(
            field: $this->field
                ->withFocus($focused)
                ->withDecoration($focused ? InputDecoration::Underline : InputDecoration::None),
            list: $this->list->withFocus($focused),
        );
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
    public function withPicked(?string $filterValue): self
    {
        return $this->cloneWith(picked: $filterValue, pickedIndex: null);
    }

    public function pickedItem(): ?ListItem
    {
        if ($this->picked === null) {
            return null;
        }

        $atIndex = $this->pickedIndex === null ? null : ($this->items[$this->pickedIndex] ?? null);

        if ($atIndex !== null && $atIndex->filterValue() === $this->picked) {
            return $atIndex;
        }

        foreach ($this->items as $item) {
            if ($item->filterValue() === $this->picked) {
                return $item;
            }
        }

        return null;
    }

    public function caption(): string
    {
        return $this->pickedItem()?->title() ?? $this->emptyCaption;
    }

    public function hasChoice(): bool
    {
        return $this->pickedItem() !== null;
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

        return $this->withPicked($this->clearable && $this->picked === $option ? null : $option);
    }

    /**
     * For a choice that may also be none: choosing the row already picked puts it
     * back down rather than picking it again.
     *
     * Off by default, because a list that must answer should not be emptiable by a
     * stray press. Nothing picked is a state this list can already hold and name
     * through its empty caption — this is how a reader reaches it.
     */
    public function clearable(): self
    {
        return $this->cloneWith(clearable: true);
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

        return FilterBar::HEIGHT + self::GAP_HEIGHT + max(1, $rows);
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
            Constraint::min(FilterBar::HEIGHT + self::GAP_HEIGHT + 1)->withFlex(),
        );
    }

    /**
     * Only the keys the list navigates with: `space` and Home/End stay with the
     * field, where they type and move the caret.
     */
    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::FOCUS_PREV => ['up'],
            Actions::FOCUS_NEXT => ['down'],
            Actions::PAGE_UP => ['shift+up', 'page_up'],
            Actions::PAGE_DOWN => ['shift+down', 'page_down'],
            Actions::SELECT => ['enter'],
            Actions::CANCEL => ['esc'],
        ]);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg) {
            return $this->withMouse($message, $area);
        }

        if (!$message instanceof KeyMsg || !$this->isFocused()) {
            return new UpdateResult($this);
        }

        // With nothing to clear the model comes back untouched, which is how a
        // host above learns the escape is still going spare.
        if ($this->effectiveKeybindings()->matches($message, Actions::CANCEL) && $this->query() !== '') {
            return new UpdateResult($this->withQuery(''));
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
            $clearing = $this->clearable && $this->picked === $value;

            return new UpdateResult($this->pickedAt($clearing ? null : $value, $this->sourceIndex()));
        }

        $dispatcher = new ChildDispatcher($this->globalKeybindings);

        if ($this->belongsToTheList($message)) {
            $result = $dispatcher->dispatchOne($this->list, $message, $this->listArea($area));

            return new UpdateResult($this->cloneWith(list: $result->child), $result->command);
        }

        $result = $dispatcher->dispatchOne($this->field, $message, $this->fieldArea($area));
        $field = $result->child;

        return new UpdateResult(
            $field->value() === $this->field->value()
                ? $this->cloneWith(field: $field)
                : $this->cloneWith(field: $field, matching: null)->reFiltered(),
            $result->command,
        );
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        FilterBar::render($frame, $this->barArea($area), $context, $this->field);

        $body = $this->listArea($area);
        if ($body->height < 1) {
            return;
        }

        if ($this->list->itemCount() === 0) {
            Paragraph::fromLines([new Line([Span::token($this->emptyText, StyleToken::Dim)])])
                ->render($frame, $body, $context);

            return;
        }

        $this->list->render($frame, $body, $context);
    }

    /**
     * The same choice, with the row it was made on. The value still decides:
     * the row is only consulted while it still reads the same, so a list that
     * moved on behind the choice falls back to finding it by value.
     */
    private function pickedAt(?string $filterValue, ?int $index): self
    {
        return $this->cloneWith(
            picked: $filterValue,
            pickedIndex: $filterValue === null ? null : $index,
        );
    }

    /**
     * A query of nothing keeps everything, in the order it was handed in. That
     * is not a matcher's answer to work out — asking one would cost a pass over
     * the whole list on every keystroke back to empty.
     *
     * @param list<ListItem> $items
     *
     * @return list<int>
     */
    private static function matching(?Matcher $matcher, string $query, array $items): array
    {
        if (trim($query) === '') {
            return array_keys($items);
        }

        $matching = ($matcher ?? new SubstringMatcher())->matching(
            $query,
            array_map(static fn (ListItem $item): string => $item->filterValue(), $items),
        );

        // A matcher may name the same item twice — nothing in the port forbids
        // it — and a row drawn twice is one row the cursor could not address.
        return array_values(array_unique(array_filter(
            $matching,
            static fn (int $index): bool => isset($items[$index]),
        )));
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
            $this->cloneWith(field: $field->child, list: $list->child->withSelected(-1)),
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

    private function barArea(Rect $area): Rect
    {
        return new Rect($area->x, $area->y, $area->width, FilterBar::HEIGHT);
    }

    private function fieldArea(Rect $area): Rect
    {
        return $this->barArea($area);
    }

    private function listArea(Rect $area): Rect
    {
        $top = FilterBar::HEIGHT + self::GAP_HEIGHT;

        return new Rect($area->x, $area->y + $top, $area->width, max(0, $area->height - $top));
    }
}
