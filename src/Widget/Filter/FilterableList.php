<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Filter;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\BindsForTheMoment;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Input\TakesText;
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
use Elmphant\Tui\Widget\ValueItCannotHold;

final readonly class FilterableList implements Focusable, KeybindingsAware, DropdownContent, Committing, PublishesHints, TakesText, BindsForTheMoment
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;
    use SearchMode;

    private const LIST_ACTIONS = [
        Actions::CURSOR_UP,
        Actions::CURSOR_DOWN,
        Actions::PAGE_UP,
        Actions::PAGE_DOWN,
        Actions::HOME,
        Actions::END,
        Actions::SELECT,
    ];

    /**
     * Cache matches so repeated reads of the highlighted item do not repeat an expensive search.
     */
    private FilterMatches $matching;

    /**
     * @param list<ListItem> $items
     */
    private function __construct(
        private array $items,
        private TextInput $field,
        private ListView $list,
        private ?Matcher $matcher = null,
        private string $noMatchText = 'No matches',
        private ?string $selectedValue = null,
        private string $emptyCaption = '',
        ?FilterMatches $matching = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $clearable = false,
        private ?int $selectedSourceIndex = null,
        private bool $searching = false,
        private string $noItemsText = '',
    ) {
        $this->matching = $matching !== null && $matching->isFor($items, $matcher, $field->value())
            ? $matching
            : FilterMatches::of($items, $matcher, $field->value());
    }

    /**
     * @param list<ListItem> $items
     */
    public static function new(array $items): self
    {
        ListItems::assertAll($items, 'FilterableList');

        return new self(
            $items,
            TextInput::new()->withPlaceholder(FilterBar::PLACEHOLDER)->withDecoration(InputDecoration::None),
            ListView::new($items),
        );
    }

    /**
     * @param list<ListItem> $items
     */
    public function withItems(array $items): self
    {
        ListItems::assertAll($items, 'FilterableList');

        return $this->cloneWith(items: $items)->reFiltered();
    }

    public function withMatcher(Matcher $matcher): self
    {
        return $this->cloneWith(matcher: $matcher)->reFiltered();
    }

    public function withPlaceholder(string $placeholder): self
    {
        return $this->cloneWith(field: $this->field->withPlaceholder($placeholder));
    }

    /**
     * Shown when no items match; withEmptyCaption() describes an empty selection instead.
     */
    public function withNoMatchText(string $noMatchText): self
    {
        return $this->cloneWith(noMatchText: $noMatchText);
    }

    public function withNoItemsText(string $noItemsText): self
    {
        return $this->cloneWith(noItemsText: $noItemsText);
    }

    public function withEmptyCaption(string $caption): self
    {
        return $this->cloneWith(emptyCaption: $caption);
    }

    public function hints(): array
    {
        return $this->searchHints([
            'move' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN],
            'choose' => Actions::SELECT,
            ...($this->query() === '' ? [] : ['clear filter' => $this->searchIsBound() ? Actions::CANCEL : [Actions::CLEAR_LINE, Actions::CANCEL]]),
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

        return $this->cloneWith(field: $this->field->withValue($query))->reFiltered();
    }

    public function withSourceIndex(?int $index): self
    {
        $kept = array_search($index, $this->matchingIndexes(), true);

        return $kept === false ? $this : $this->cloneWith(list: $this->list->withCursor($kept));
    }

    public function summary(): string
    {
        return $this->list->itemCount() . ' of ' . count($this->items);
    }

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
     * Store selections by filter value so a query with no matches cannot erase them.
     */
    public function withSelectedValue(?string $filterValue): self
    {
        return $this->cloneWith(selectedValue: $filterValue, selectedSourceIndex: null);
    }

    public function value(): ?string
    {
        return $this->selectedItem()?->filterValue();
    }

    public function withValue(string|bool|array|null $value): static
    {
        return \is_string($value) || $value === null
            ? $this->withSelectedValue($value)
            : throw ValueItCannotHold::given($this, $value);
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
     * Allow choosing the selected row to clear it; disabled by default to prevent accidental empty choices.
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
     * A SELECT binding alone is not a commit: a query with no matches must not close its popup.
     */
    public function commits(Message $message): bool
    {
        return $message instanceof KeyMsg
            && !$this->isSearching()
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
     * Keep Space and Home/End for text editing; expose top and bottom actions for global bindings such as `gg` and `G`.
     */
    public static function defaultKeybindings(): Keybindings
    {
        return (new Keybindings([
            Actions::CURSOR_UP => ['up'],
            Actions::CURSOR_DOWN => ['down'],
            Actions::PAGE_UP => ['shift+up', 'page_up'],
            Actions::PAGE_DOWN => ['shift+down', 'page_down'],
            Actions::HOME => [],
            Actions::END => [],
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

        $toggled = $this->searchToggledBy($message);
        if ($toggled !== null) {
            return new UpdateResult($toggled);
        }

        if ($this->effectiveKeybindings()->matches($message, Actions::CANCEL)) {
            return new UpdateResult($this->query() === '' ? $this : $this->withQuery(''));
        }

        // Store the selected filter value and original row so duplicate values preserve the chosen item.
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

        return $this->takesText() ? $this->typedIntoTheField($message, $area) : new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $field = fn () => FilterBar::render($frame, $this->fieldArea($area), $context, $this->fieldDrawnBy($context));
        $this->fieldHoldsTheKeys($context) ? $field() : $context->withoutFocus($field);

        $body = $this->listArea($area);
        if ($body->height < 1) {
            return;
        }

        if ($this->list->itemCount() === 0) {
            Paragraph::fromLines([new Line([Span::token($this->items === [] ? $this->noItemsText : $this->noMatchText, StyleToken::Dim)])])
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
                : $this->cloneWith(field: $field)->reFiltered(),
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
     * Use the stored row only while its value still matches; otherwise find the selection by value.
     */
    private function selectedAt(?string $filterValue, ?int $index): self
    {
        return $this->cloneWith(
            selectedValue: $filterValue,
            selectedSourceIndex: $filterValue === null ? null : $index,
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
     * A click moves only the cursor; an independent list selection would appear as a second chosen item.
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
            Cmd::combine($field->command, $list->command),
        );
    }

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
        return $this->matching->indexes;
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
