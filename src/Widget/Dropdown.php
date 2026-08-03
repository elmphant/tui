<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Search\SubstringMatcher;

/**
 * One row showing a value, and a floating list to pick another from.
 */
final readonly class Dropdown implements Focusable, KeybindingsAware
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    private const MARKER = '▾ ';

    /**
     * @param list<string>          $options
     * @param array<string, string> $labels
     */
    private function __construct(
        private array $options,
        private array $labels = [],
        private ?string $selected = null,
        private bool $open = false,
        private int $cursor = 0,
        private string $query = '',
        private int $scroll = 0,
        private bool $focused = false,
        private string $placeholder = '',
        private bool $freeText = false,
        private ?Matcher $matcher = null,
        private ?Rect $viewport = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    /**
     * @param list<string> $options
     */
    public static function new(array $options): self
    {
        return new self($options);
    }

    /**
     * @param array<string, string> $labels
     */
    public function withLabels(array $labels): self
    {
        return $this->cloneWith(labels: $labels);
    }

    public function withSelected(?string $selected): self
    {
        return $this->cloneWith(selected: $selected);
    }

    public function withPlaceholder(string $placeholder): self
    {
        return $this->cloneWith(placeholder: $placeholder);
    }

    public function withMatcher(Matcher $matcher): self
    {
        return $this->cloneWith(matcher: $matcher);
    }

    public function withFreeText(bool $freeText): self
    {
        return $this->cloneWith(freeText: $freeText);
    }

    /**
     * The area the popup may use. Without it the popup still opens; only
     * mouse hit-testing inside it needs the geometry.
     */
    public function withViewport(Rect $viewport): self
    {
        return $this->cloneWith(viewport: $viewport);
    }

    public function selectedValue(): ?string
    {
        return $this->selected;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return $this->cloneWith(focused: $focused, open: $focused ? $this->open : false);
    }

    public function constraint(): Constraint
    {
        return Constraint::exact(1);
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::TOGGLE => ['space', 'right'],
            Actions::SELECT => ['enter'],
            Actions::CANCEL => ['esc'],
            Actions::FOCUS_NEXT => ['down'],
            Actions::FOCUS_PREV => ['up'],
            Actions::DELETE_CHAR_BACKWARD => ['backspace'],
        ]);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg) {
            return new UpdateResult($this->moused($message, $area));
        }

        if (!$message instanceof KeyMsg || !$this->focused) {
            return new UpdateResult($this);
        }

        if (!$this->open) {
            return new UpdateResult(
                $this->effectiveKeybindings()->matches($message, Actions::TOGGLE)
                    ? $this->cloneWith(open: true, cursor: 0, query: '', scroll: 0)
                    : $this,
            );
        }

        return new UpdateResult($this->keyedWhileOpen($message, $area));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $value = $this->selected;
        $shown = $value === null ? $this->placeholder : ($this->labels[$value] ?? $value);
        $style = match (true) {
            $this->focused => $context->theme->accent,
            $value !== null => $context->theme->base,
            default => $context->theme->dim,
        };

        $frame->putStyled(
            $area->x,
            $area->y,
            CharWidth::truncateToWidth(self::MARKER . $shown, $area->width),
            $style,
        );

        if ($this->open) {
            $context->float(
                $frame,
                DropdownPopup::of($this->filtered(), $this->labels, $this->cursor, $this->query, $this->scroll),
                $area,
            );
        }
    }

    private function moused(MouseMsg $message, Rect $area): self
    {
        if (!$this->open) {
            return $this;
        }

        $filtered = $this->filtered();
        $placement = $this->placementIn($area, $filtered);

        if ($placement === null || !$message->inRect($placement)) {
            return $message->isClick() ? $this->closed() : $this;
        }

        return match (true) {
            $message->action === MouseAction::WheelUp => $this->scrolledBy(-$message->wheelSteps(1), $filtered, $area),
            $message->action === MouseAction::WheelDown => $this->scrolledBy($message->wheelSteps(1), $filtered, $area),
            $message->isClick() => $this->clickedRow($message->y - $placement->y, $placement, $filtered),
            default => $this,
        };
    }

    /**
     * @param list<string> $filtered
     */
    private function scrolledBy(int $steps, array $filtered, Rect $area): self
    {
        $rows = $this->visibleRows($filtered, $area);
        $furthest = max(0, count($filtered) - $rows);

        return $this->cloneWith(scroll: max(0, min($furthest, $this->scroll + $steps)));
    }

    /**
     * @param list<string> $filtered
     */
    private function clickedRow(int $offsetInPopup, Rect $placement, array $filtered): self
    {
        if ($offsetInPopup < DropdownPopup::CHROME || $offsetInPopup >= $placement->height - 1) {
            return $this;
        }

        $index = $this->scroll + $offsetInPopup - DropdownPopup::CHROME;

        if ($index > count($filtered) - 1) {
            return $this;
        }

        return $this->cloneWith(cursor: $index)->picked($filtered);
    }

    private function keyedWhileOpen(KeyMsg $message, Rect $area): self
    {
        $filtered = $this->filtered();
        $bindings = $this->effectiveKeybindings();

        return match (true) {
            $bindings->matches($message, Actions::CANCEL) => $this->closed(),
            $bindings->matches($message, Actions::SELECT) => $this->picked($filtered),
            $bindings->matches($message, Actions::FOCUS_NEXT) => $this->movedTo($this->cursor + 1, $filtered, $area),
            $bindings->matches($message, Actions::FOCUS_PREV) => $this->movedTo($this->cursor - 1, $filtered, $area),
            $bindings->matches($message, Actions::DELETE_CHAR_BACKWARD) => $this->searchedFor(mb_substr($this->query, 0, -1)),
            \is_string($message->key) && !$message->ctrl && !$message->alt => $this->searchedFor($this->query . $message->key),
            default => $this,
        };
    }

    private function closed(): self
    {
        return $this->cloneWith(open: false, query: '', cursor: 0, scroll: 0);
    }

    /**
     * @param list<string> $filtered
     */
    private function picked(array $filtered): self
    {
        $value = $filtered[$this->cursor] ?? null;

        if ($value === null) {
            return $this->freeText && trim($this->query) !== ''
                ? $this->cloneWith(selected: $this->query)->closed()
                : $this;
        }

        return $this->cloneWith(selected: $value)->closed();
    }

    /**
     * @param list<string> $filtered
     */
    private function movedTo(int $cursor, array $filtered, Rect $area): self
    {
        $last = count($filtered) - 1;

        if ($last < 0 || $cursor < 0 || $cursor > $last) {
            return $this;
        }

        return $this->cloneWith(cursor: $cursor, scroll: $this->scrollShowing($cursor, $filtered, $area));
    }

    /**
     * @param list<string> $filtered
     */
    private function scrollShowing(int $cursor, array $filtered, Rect $area): int
    {
        $rows = $this->visibleRows($filtered, $area);

        if ($rows < 1) {
            return $this->scroll;
        }

        return match (true) {
            $cursor < $this->scroll => $cursor,
            $cursor >= $this->scroll + $rows => $cursor - $rows + 1,
            default => $this->scroll,
        };
    }

    /**
     * @param list<string> $filtered
     */
    private function visibleRows(array $filtered, Rect $area): int
    {
        $placement = $this->placementIn($area, $filtered);

        if ($placement === null) {
            return count($filtered);
        }

        return max(0, $placement->height - DropdownPopup::CHROME - 1);
    }

    /**
     * @param list<string> $filtered
     */
    private function placementIn(Rect $area, array $filtered): ?Rect
    {
        if ($this->viewport === null) {
            return null;
        }

        return DropdownPopup::of($filtered, $this->labels, $this->cursor, $this->query, $this->scroll)
            ->placement($area, $this->viewport);
    }

    /**
     * A new query has a new best match, so the cursor goes back to the top.
     */
    private function searchedFor(string $query): self
    {
        return $this->cloneWith(query: $query, cursor: 0, scroll: 0);
    }

    /**
     * Ranked, not sorted: Matcher::matching() returns best match first. Matches
     * the label where there is one, otherwise the value.
     *
     * @return list<string>
     */
    private function filtered(): array
    {
        if (trim($this->query) === '') {
            return $this->options;
        }

        $haystack = array_map(fn (string $option): string => $this->labels[$option] ?? $option, $this->options);
        $matching = ($this->matcher ?? new SubstringMatcher())->matching($this->query, $haystack);

        return array_map(fn (int $index): string => $this->options[$index], $matching);
    }
}
