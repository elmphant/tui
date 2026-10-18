<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Form;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Choice\RadioRow;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Focus\CapturesKeys;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\HoldsAValue;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\ValueItCannotHold;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

/**
 * One of a set of values, shown by label: a row of marks where they fit on one
 * row, a list that opens where they would wrap. Which of the two is asked of the
 * area each time, so a resize switches over and what was chosen stays chosen.
 */
final readonly class ChoiceField implements CapturesKeys, Focusable, HoldsAValue, PublishesHints, WidgetContainer
{
    use Cloneable;

    public const NOTHING_CHOSEN = 'choose…';

    /**
     * @param array<array-key, string> $options value => label
     * @param Dropdown<FilterableList> $list
     */
    private function __construct(
        private array $options,
        private RadioRow $row,
        private Dropdown $list,
        private ?Matcher $matcher,
        private bool $clearable,
    ) {
    }

    /**
     * @param array<array-key, string> $options value => label
     */
    public static function new(array $options): self
    {
        return self::built($options, null, null, false);
    }

    public function withValue(string|bool|array|null $value): static
    {
        return \is_string($value) || $value === null
            ? self::built($this->options, $value, $this->matcher, $this->clearable)
            : throw ValueItCannotHold::given($this, $value);
    }

    /**
     * What was chosen stays chosen while it is still offered.
     *
     * @param array<array-key, string> $options value => label
     */
    public function withOptions(array $options): self
    {
        return self::built($options, $this->value(), $this->matcher, $this->clearable);
    }

    /**
     * How the collapsed list narrows to what the reader types.
     */
    public function withMatcher(Matcher $matcher): self
    {
        return self::built($this->options, $this->value(), $matcher, $this->clearable);
    }

    /**
     * Choosing what is already chosen takes it back, for a field that may be left empty.
     */
    public function withClearable(bool $clearable = true): self
    {
        return self::built($this->options, $this->value(), $this->matcher, $clearable);
    }

    public function value(): ?string
    {
        $label = $this->row->selectedValue();
        $value = $label === null ? false : array_search($label, $this->options, true);

        return $value === false ? null : (string)$value;
    }

    /**
     * As wide as its row of marks: given less, it collapses rather than wraps.
     */
    public function sizing(): Sizing
    {
        return Sizing::of($this->row->sizing()->columns, Constraint::exact(1));
    }

    public function capturesKeys(): bool
    {
        return $this->list->capturesKeys();
    }

    public function hints(): array
    {
        if ($this->list->isOpen()) {
            return $this->list->hints();
        }

        return $this->row->effectiveKeybindings()->hints([
            'move' => [Actions::CURSOR_LEFT, Actions::CURSOR_RIGHT],
            'choose' => Actions::SELECT,
        ]);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($this->fitsOnOneRow($area->width)) {
            return $this->row->update($message, $area)
                ->mapModel(fn (RadioRow $row): self => $row === $this->row ? $this : $this->cloneWith(
                    row: $row,
                    list: self::listChoosing(array_values($this->options), $row->selectedValue(), $this->matcher, $this->clearable),
                ));
        }

        $step = $this->stepOf($message);
        if ($step !== 0 && !$this->list->isOpen()) {
            return new UpdateResult($this->withValue($this->stepped($step)));
        }

        return $this->list->update($message, $area)
            ->mapModel(fn (Dropdown $list): self => $list === $this->list ? $this : $this->cloneWith(
                list: $list,
                row: $this->row->withSelectedValue(\is_string($list->value()) ? $list->value() : null),
            ));
    }

    /**
     * @return list<Widget>
     */
    public function children(): iterable
    {
        return [$this->row, $this->list];
    }

    /**
     * @return list<array{0: Widget, 1: Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        return [[$this->shownIn($area), $area]];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->shownIn($area)->render($frame, $area, $context);
    }

    private function shownIn(Rect $area): Widget
    {
        return $this->fitsOnOneRow($area->width) ? $this->row : $this->list;
    }

    /**
     * @param array<array-key, string> $options
     */
    private static function built(array $options, ?string $value, ?Matcher $matcher, bool $clearable): self
    {
        $labels = array_values($options);
        $label = $value === null ? null : $options[$value] ?? null;
        $row = RadioRow::new($labels)->withSelectedValue($label)->withClearable($clearable)->withWrapping(false);

        return new self($options, $row, self::listChoosing($labels, $label, $matcher, $clearable), $matcher, $clearable);
    }

    /**
     * @param list<string> $labels
     *
     * @return Dropdown<FilterableList>
     */
    private static function listChoosing(array $labels, ?string $label, ?Matcher $matcher, bool $clearable): Dropdown
    {
        $list = FilterableList::new(array_map(
            static fn (string $label): SimpleListItem => new SimpleListItem($label),
            $labels,
        ))->withSelectedValue($label)->withEmptyCaption(self::NOTHING_CHOSEN)->withClearable($clearable);

        return Dropdown::new($matcher === null ? $list : $list->withMatcher($matcher));
    }

    private function fitsOnOneRow(int $width): bool
    {
        return $this->row->measureHeight($width) <= 1;
    }

    private function stepOf(Message $message): int
    {
        if (!$message instanceof KeyMsg) {
            return 0;
        }

        $keys = $this->row->effectiveKeybindings();

        return match (true) {
            $keys->matches($message, Actions::CURSOR_LEFT) => -1,
            $keys->matches($message, Actions::CURSOR_RIGHT) => 1,
            default => 0,
        };
    }

    private function stepped(int $step): ?string
    {
        $values = array_map(strval(...), array_keys($this->options));
        if ($values === []) {
            return null;
        }

        $at = array_search($this->value(), $values, true);
        $next = $at === false ? 0 : max(0, min(\count($values) - 1, $at + $step));

        return $values[$next];
    }
}
