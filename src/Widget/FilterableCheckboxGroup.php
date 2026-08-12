<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Runtime\ChildDispatcher;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Search\SubstringMatcher;

/**
 * Boxes to tick with a free-text filter over them, for a list too long to walk.
 *
 * A tick is held by name rather than by row: the query decides which rows exist,
 * so a row number means something different from one keystroke to the next.
 */
final readonly class FilterableCheckboxGroup implements DropdownContent
{
    use Cloneable;

    /**
     * Keys the boxes take; every other one is typing and belongs to the field.
     */
    private const OWN_ACTIONS = [Actions::FOCUS_PREV, Actions::FOCUS_NEXT, Actions::TOGGLE];

    private const GAP_HEIGHT = 1;

    /**
     * @param list<string> $options
     * @param list<string> $checked
     */
    private function __construct(
        private array $options,
        private TextInput $field,
        private array $checked = [],
        private int $cursor = 0,
        private bool $focused = false,
        private ?Matcher $matcher = null,
    ) {
    }

    /**
     * How a query narrows the boxes. Substring matching by default; supply a
     * matcher for fuzzy or remote search, as the other filterable widgets take.
     */
    public function withMatcher(Matcher $matcher): self
    {
        return $this->cloneWith(matcher: $matcher);
    }

    /**
     * @param list<string> $options
     */
    public static function new(array $options): self
    {
        return new self($options, TextInput::new(FilterBar::PLACEHOLDER));
    }

    /**
     * @return list<string>
     */
    public function checkedValues(): array
    {
        return array_values(array_filter(
            $this->options,
            fn (string $option): bool => \in_array($option, $this->checked, true),
        ));
    }

    /**
     * A count of the ticks over the whole list. What no tick at all means is the
     * host's to say, exactly as it is for a plain group of boxes.
     */
    public function caption(): string
    {
        return \count($this->checkedValues()) . ' of ' . \count($this->options);
    }

    public function hasChoice(): bool
    {
        return $this->checkedValues() !== [];
    }

    /**
     * Measured against every option, not against the ones a query happens to be
     * leaving: a popup as wide as the query is long would move under the reader
     * with each keystroke.
     */
    public function popupWidth(): int
    {
        return CheckboxGroup::new($this->options)->popupWidth();
    }

    public function popupHeight(int $width): int
    {
        return FilterBar::HEIGHT + self::GAP_HEIGHT + \count($this->matching());
    }

    /**
     * Answered against the boxes as they are drawn now, so a press lands on the
     * name the reader was looking at rather than on the one that held that row
     * before the query moved it.
     */
    public function optionAt(MouseMsg $message, Rect $area): ?string
    {
        return $this->boxes()->optionAt($message, $this->boxesArea($area));
    }

    public function hasOption(string $option): bool
    {
        return \in_array($option, $this->options, true);
    }

    public function activated(string $option): static
    {
        if (!$this->hasOption($option)) {
            return $this;
        }

        return $this->cloneWith(checked: \in_array($option, $this->checked, true)
            ? array_values(array_filter($this->checked, static fn (string $name): bool => $name !== $option))
            : [...$this->checked, $option]);
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return $this->cloneWith(field: $this->field->withFocus($focused), focused: $focused);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$message instanceof KeyMsg || !$this->focused) {
            return new UpdateResult($this);
        }

        $dispatcher = new ChildDispatcher();

        if ($this->isForTheBoxes($message)) {
            $boxes = $dispatcher->dispatchOne($this->boxes(), $message, $this->boxesArea($area))->child;

            return new UpdateResult($this->cloneWith(checked: $this->checkedAfter($boxes), cursor: $boxes->cursor()));
        }

        $field = $dispatcher->dispatchOne($this->field, $message, $this->fieldArea($area))->child;

        return new UpdateResult($this->cloneWith(field: $field, cursor: 0));
    }

    public function constraint(): Constraint
    {
        return Constraint::flex();
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        FilterBar::render($frame, $this->fieldArea($area), $context, $this->field);

        $body = $this->boxesArea($area);
        if ($body->height > 0) {
            $this->boxes()->render($frame, $body, $context);
        }
    }

    private function isForTheBoxes(KeyMsg $message): bool
    {
        foreach (self::OWN_ACTIONS as $action) {
            if (CheckboxGroup::defaultKeybindings()->matches($message, $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The boxes the query left, ticked from the names this group is keeping. Built
     * on demand rather than held, so a query and a tick cannot disagree.
     */
    private function boxes(): CheckboxGroup
    {
        $matching = $this->matching();

        return CheckboxGroup::new($matching)
            ->withChecked(array_values(array_filter(
                array_keys($matching),
                fn (int $index): bool => \in_array($matching[$index], $this->checked, true),
            )))
            ->withCursor(min($this->cursor, max(0, \count($matching) - 1)))
            ->withFocus($this->focused);
    }

    /**
     * What the boxes made of the ticks they could see, and every tick they could
     * not: a name the query is hiding is not a name the reader untucked.
     *
     * @return list<string>
     */
    private function checkedAfter(CheckboxGroup $boxes): array
    {
        return [...array_values(array_diff($this->checked, $this->matching())), ...$boxes->checkedValues()];
    }

    private function fieldArea(Rect $area): Rect
    {
        return new Rect($area->x, $area->y, $area->width, FilterBar::HEIGHT);
    }

    /**
     * A blank row under the field, the same gap a filterable list leaves.
     */
    private function boxesArea(Rect $area): Rect
    {
        $top = FilterBar::HEIGHT + self::GAP_HEIGHT;

        return new Rect($area->x, $area->y + $top, $area->width, max(0, $area->height - $top));
    }

    /**
     * @return list<string>
     */
    private function matching(): array
    {
        $query = $this->field->value();

        if ($query === '') {
            return $this->options;
        }

        $matching = ($this->matcher ?? new SubstringMatcher())->matching($query, $this->options);

        return array_map(fn (int $index): string => $this->options[$index], $matching);
    }
}
