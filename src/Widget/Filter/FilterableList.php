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
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Support\Places;
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
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\ValueItCannotHold;

final readonly class FilterableList implements Focusable, KeybindingsAware, DropdownContent, Committing, PublishesHints, TakesText, BindsForTheMoment
{
    use Cloneable;
    use DrawsFocus;
    use FiltersItsContent;
    use KeybindingsAwareBehavior;
    use SearchMode;

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
        private ?int $selectedSource = null,
        private string $emptyCaption = '',
        ?FilterMatches $matching = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $clearable = false,
        private bool $searching = false,
        private string $noItemsText = '',
    ) {
        $this->matching = FilterMatches::kept(
            $matching,
            $items,
            static fn (array $items): array => array_map(static fn (ListItem $item): string => $item->filterValue(), $items),
            $matcher,
            $field->value(),
        );
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

        $reloaded = $this->cloneWith(
            items: $items,
            selectedSource: Places::placeOf(
                $this->selectedItem()?->key(),
                array_map(static fn (ListItem $item): string => $item->key(), $items),
                $this->selectedSource ?? 0,
            ),
        );

        return $reloaded->cloneWith(list: $this->list->withItems($reloaded->matchingItems()));
    }

    public function withNoItemsText(string $noItemsText): self
    {
        return $this->cloneWith(noItemsText: $noItemsText);
    }

    /**
     * Describes an empty selection, where withNoMatchText() describes an empty list.
     */
    public function withEmptyCaption(string $caption): self
    {
        return $this->cloneWith(emptyCaption: $caption);
    }

    public function withCursorSourceIndex(?int $index): self
    {
        $kept = array_search($index, $this->matchingIndexes(), true);

        return $kept === false ? $this : $this->cloneWith(list: $this->list->withCursor($kept));
    }

    public function summary(): string
    {
        return $this->list->itemCount() . ' of ' . count($this->items);
    }

    public function cursorItem(): ?ListItem
    {
        $index = $this->cursorSourceIndex();

        return $index === null ? null : $this->items[$index] ?? null;
    }

    /**
     * Choose the first item with this text, as a form field holding text does.
     */
    public function withSelectedValue(?string $filterValue): self
    {
        $place = null;
        foreach ($this->items as $index => $item) {
            if ($item->filterValue() === $filterValue) {
                $place = $index;

                break;
            }
        }

        return $this->cloneWith(selectedSource: $place);
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
        return $this->selectedSource === null ? null : $this->items[$this->selectedSource] ?? null;
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
        $index = $this->list->indexAt($message, $this->contentArea($area));

        return $index === null ? null : ($this->matchingItems()[$index] ?? null)?->key();
    }

    public function hasOption(string $option): bool
    {
        return $this->placeOfKey($option) !== null;
    }

    public function activated(string $option): static
    {
        $place = $this->placeOfKey($option);

        return $place === null ? $this : $this->chosen($place);
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
            $rows += $item->measureHeight($width);
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

    private function placeOfKey(string $key): ?int
    {
        foreach ($this->items as $index => $item) {
            if ($item->key() === $key) {
                return $index;
            }
        }

        return null;
    }

    private function chosen(int $place): self
    {
        return $this->cloneWith(selectedSource: $this->clearable && $place === $this->selectedSource ? null : $place);
    }

    private function reFiltered(): self
    {
        return $this->cloneWith(
            list: $this->list
                ->withItems($this->matchingItems())
                ->withScrollOffset(0),
        );
    }

    private function atTheTop(): self
    {
        return $this->cloneWith(list: $this->list->withCursor(0));
    }

    private function contentCursor(): int
    {
        return $this->list->cursor();
    }

    /**
     * @return UpdateResult<self>
     */
    private function contentAnswered(KeyMsg $message, Rect $area): UpdateResult
    {
        if ($this->effectiveKeybindings()->matches($message, Actions::SELECT)) {
            $place = $this->cursorSourceIndex();

            return new UpdateResult($place === null ? $this : $this->chosen($place));
        }

        $result = (new ChildDispatcher($this->globalKeybindings))->dispatchOne($this->list, $message, $this->contentArea($area));

        return new UpdateResult($this->cloneWith(list: $result->child), $result->command);
    }

    /**
     * Only `selectedSource` holds the choice, so the list view's own selection stays empty.
     *
     * @return UpdateResult<self>
     */
    private function withMouse(MouseMsg $message, Rect $area): UpdateResult
    {
        $option = $message->isClick() ? $this->optionAt($message, $area) : null;
        $dispatcher = new ChildDispatcher($this->globalKeybindings);
        $field = $dispatcher->dispatchOne($this->field, $message, FilterBar::barArea($area));
        $list = $dispatcher->dispatchOne($this->list, $message, $this->contentArea($area));
        $answered = $this->cloneWith(field: $field->child, list: $list->child->withSelected(null));

        return new UpdateResult(
            $option === null ? $answered : $answered->activated($option),
            Cmd::combine($field->command, $list->command),
        );
    }

    private function barSummary(): ?string
    {
        return null;
    }

    private function nothingToShow(): string
    {
        return $this->items === [] ? $this->noItemsText : $this->noMatchText;
    }

    private function renderContent(Frame $frame, Rect $body, RenderContext $context): void
    {
        $chosen = array_search($this->selectedSource, $this->matchingIndexes(), true);
        $this->list->withSelected($chosen === false ? null : $chosen)->render($frame, $body, $context);
    }

    /**
     * @return list<ListItem>
     */
    private function matchingItems(): array
    {
        return array_map(fn (int $index): ListItem => $this->items[$index], $this->matchingIndexes());
    }
}
