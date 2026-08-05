<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseMsg;
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
final readonly class FilterableList implements Focusable
{
    use Cloneable;

    private const GAP_HEIGHT = 1;

    /**
     * Keys the list takes; everything else is typing and belongs to the field.
     */
    private const LIST_KEYS = [Key::Up, Key::Down, Key::PageUp, Key::PageDown, Key::Enter];

    /**
     * @param list<ListItem> $items
     */
    private function __construct(
        private array $items,
        private TextInput $field,
        private ListView $list,
        private ?Matcher $matcher = null,
        private string $emptyText = 'No matches',
    ) {
    }

    /**
     * @param list<ListItem> $items
     */
    public static function new(array $items): self
    {
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

    public function withEmptyText(string $emptyText): self
    {
        return $this->cloneWith(emptyText: $emptyText);
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

        return $this->cloneWith(field: $this->field->withValue($query))->reFiltered();
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

    public function selectedItem(): ?ListItem
    {
        $index = $this->sourceIndex();

        return $index === null ? null : $this->items[$index] ?? null;
    }

    public function constraint(): Constraint
    {
        return Constraint::min(FilterBar::HEIGHT + self::GAP_HEIGHT + 1)->withFlex();
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg) {
            return $this->withMouse($message, $area);
        }

        if (!$message instanceof KeyMsg || !$this->isFocused()) {
            return new UpdateResult($this);
        }

        $dispatcher = new ChildDispatcher();

        if (\in_array($message->key, self::LIST_KEYS, true)) {
            $result = $dispatcher->dispatchOne($this->list, $message, $this->listArea($area));

            return new UpdateResult($this->cloneWith(list: $result->child), $result->command);
        }

        $result = $dispatcher->dispatchOne($this->field, $message, $this->fieldArea($area));
        $field = $result->child;

        return new UpdateResult(
            $field->value() === $this->field->value()
                ? $this->cloneWith(field: $field)
                : $this->cloneWith(field: $field)->reFiltered(),
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
     * @return UpdateResult<self>
     */
    private function withMouse(MouseMsg $message, Rect $area): UpdateResult
    {
        $dispatcher = new ChildDispatcher();
        $field = $dispatcher->dispatchOne($this->field, $message, $this->fieldArea($area));
        $list = $dispatcher->dispatchOne($this->list, $message, $this->listArea($area));

        return new UpdateResult(
            $this->cloneWith(field: $field->child, list: $list->child),
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
        return ($this->matcher ?? new SubstringMatcher())->matching(
            $this->query(),
            array_map(static fn (ListItem $item): string => $item->filterValue(), $this->items),
        );
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
