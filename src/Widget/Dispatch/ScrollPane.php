<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Dispatch;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\KeyedCache;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\ScrollWindow;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\VerticalStack;
use Elmphant\Tui\Layout\VerticalStackLayout;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\CursorAware;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focus\FocusTree;
use Elmphant\Tui\Widget\Focus\HoldsTheKeyboard;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Scrollbar;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

/**
 * A vertical stack of children in a scrolling viewport.
 */
final readonly class ScrollPane implements Focusable, KeybindingsAware, MeasureHeight, WidgetContainer, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    private const KEY_STEP = 1;

    /**
     * Reset whenever the children change, kept otherwise.
     *
     * @var KeyedCache<VerticalStackLayout>
     */
    private KeyedCache $layoutMemo;

    /**
     * @param list<Widget>                         $children
     * @param KeyedCache<VerticalStackLayout>|null $layoutMemo
     *
     * @internal use ScrollPane::of()
     */
    public function __construct(
        private array $children = [],
        private int $scrollOffset = 0,
        private int $focusIndex = 0,
        private ?int $followChild = null,
        private int $followSpan = 1,
        private bool $focused = false,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        ?KeyedCache $layoutMemo = null,
    ) {
        $this->layoutMemo = $layoutMemo ?? self::newLayoutMemo();
    }

    public static function of(Widget ...$children): self
    {
        return new self(array_values($children));
    }

    public function add(Widget $child): self
    {
        return $this->cloneWith(children: [...$this->children, $child], layoutMemo: self::newLayoutMemo());
    }

    public function scrollOffset(): int
    {
        return $this->scrollOffset;
    }

    /**
     * Scrolling by hand ends any follow: the reader has taken over.
     */
    public function withScrollOffset(int $scrollOffset): self
    {
        return $this->cloneWith(scrollOffset: max(0, $scrollOffset), followChild: null);
    }

    /**
     * Keep the given child, or a run of them, in view whatever the offset says.
     */
    public function withFollowChild(?int $index, int $span = 1): self
    {
        return $this->cloneWith(followChild: $index, followSpan: max(1, $span));
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return $this->cloneWith(focused: $focused);
    }

    /**
     * Focus arrives from the end, so Tab backwards lands on the last child
     * rather than the first.
     */
    public function withFocusReversed(): static
    {
        return $this->cloneWith(focusIndex: $this->tree()->focusAtEnd()->active(), focused: true);
    }

    /**
     * The index counts only the children that can take focus.
     */
    public function withFocusAt(int $index): self
    {
        return $this->cloneWith(focusIndex: $this->tree()->focusAt($index)->active(), focused: true);
    }

    public function focusIndex(): int
    {
        return $this->focusIndex;
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::FOCUS_CYCLE => ['tab'],
            Actions::FOCUS_PREVIOUS_CYCLE => ['shift+tab'],
            Actions::SCROLL_UP => ['up'],
            Actions::SCROLL_DOWN => ['down'],
            Actions::PAGE_UP => ['shift+up', 'page_up'],
            Actions::PAGE_DOWN => ['shift+down', 'page_down'],
        ]);
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            'scroll' => [Actions::SCROLL_UP, Actions::SCROLL_DOWN],
        ]);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg) {
            return $this->keyed($message, $area);
        }

        if ($message instanceof MouseMsg) {
            return $this->moused($message, $area);
        }

        return $this->broadcast($message, $area);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    /**
     * @return list<Widget>
     */
    public function children(): iterable
    {
        return $this->focusedChildren();
    }

    /**
     * Without the focus marks `children()` applies, so a host can compare what it is
     * about to hand in against what is already here.
     *
     * @return list<Widget>
     */
    public function authoredChildren(): array
    {
        return $this->children;
    }

    /**
     * Every row its children need, which is more than a viewport may show. A
     * caller sizing a pane to its content asks this and then decides.
     */
    public function measureHeight(int $width): int
    {
        if ($width < 1) {
            return 0;
        }

        return VerticalStack::layoutUsing($this->layoutMemo, $this->children, $width, 0)->totalHeight;
    }

    /**
     * @return list<array{0: Widget, 1: Rect}>
     */
    public function childWidgets(Rect $area): iterable
    {
        // Authored, not marked: only drawing knows where the keyboard is, and a
        // rect held for a marked widget belongs to one the tree may never draw.
        $children = $this->children;
        $layout = VerticalStack::layoutUsing($this->layoutMemo, $children, $area->width, $area->height);
        $y = $area->y - $this->resolvedOffset($layout, $area->height);
        $placed = [];

        foreach ($children as $index => $child) {
            $placed[] = [$child, new Rect($area->x, $y, $layout->childWidth, $layout->heights[$index])];
            $y += $layout->heights[$index];
        }

        return $placed;
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $children = $this->childrenFocused($this->drawsFocused($context));
        $layout = VerticalStack::layoutUsing($this->layoutMemo, $children, $area->width, $area->height);
        $offset = $this->resolvedOffset($layout, $area->height);

        $frame->pushClip(new Rect($area->x, $area->y, $layout->childWidth, $area->height));

        try {
            $y = $area->y - $offset;

            foreach ($children as $index => $child) {
                $height = $layout->heights[$index];

                if ($y + $height > $area->y && $y < $area->bottom()) {
                    $childArea = new Rect($area->x, $y, $layout->childWidth, $height);

                    // The pane holds the keyboard for one child; the rest sit in
                    // the same focused subtree and must not read it as their own.
                    if (self::holdsKeyboard($child)) {
                        $child->render($frame, $childArea, $context);
                    } else {
                        $context->withoutFocus(static function () use ($child, $frame, $childArea, $context): void {
                            $child->render($frame, $childArea, $context);
                        });
                    }
                }

                $y += $height;

                if ($y >= $area->bottom()) {
                    break;
                }
            }
        } finally {
            $frame->popClip();
        }

        if (!$layout->hasScrollbar) {
            return;
        }

        Scrollbar::renderFor(
            $frame,
            $area,
            $offset,
            $layout->totalHeight + ScrollWindow::BOTTOM_PADDING,
            $context->theme,
        );
    }

    /**
     * @return KeyedCache<VerticalStackLayout>
     */
    private static function newLayoutMemo(): KeyedCache
    {
        /** @var KeyedCache<VerticalStackLayout> $cache */
        $cache = new KeyedCache();

        return $cache;
    }

    /**
     * @return UpdateResult<self>
     */
    private function keyed(KeyMsg $message, Rect $area): UpdateResult
    {
        if (!$this->focused) {
            return new UpdateResult($this);
        }

        $delegated = $this->delegatedToFocusedChild($message, $area);

        if ($delegated !== null) {
            return $delegated;
        }

        return new UpdateResult($this->cycledFocus($message) ?? $this->scrolledByKey($message, $area) ?? $this);
    }

    /**
     * The wheel is the pane's own. A click picks the child under it and then
     * goes on to the children, so the one under the pointer can answer too.
     *
     * @return UpdateResult<self>
     */
    private function moused(MouseMsg $message, Rect $area): UpdateResult
    {
        if (!$message->inRect($area)) {
            return $this->broadcast($message, $area);
        }

        $scrolled = match ($message->action) {
            MouseAction::WheelDown => $this->scrolledBy($message->wheelSteps(), $area),
            MouseAction::WheelUp => $this->scrolledBy(-$message->wheelSteps(), $area),
            default => null,
        };

        if ($scrolled !== null) {
            return new UpdateResult($scrolled);
        }

        $pane = $message->isClick() && $this->focused
            ? $this->focusedByClick($message, $area)
            : $this;

        return $pane->broadcast($message, $area);
    }

    /**
     * Anything that is not input belongs to every child, not only the focused
     * one: a tick reaches the spinner nobody is looking at.
     *
     * @return UpdateResult<self>
     */
    private function broadcast(Message $message, Rect $area): UpdateResult
    {
        $interactives = [];
        $rects = [];

        foreach ($this->childWidgets($area) as $index => [$child, $childArea]) {
            if ($child instanceof Interactive) {
                $interactives[$index] = $child;
                $rects[$index] = $childArea;
            }
        }

        $batch = (new ChildDispatcher($this->globalKeybindings))->dispatchList($interactives, $rects, $message);

        if (!$batch->changed()) {
            return new UpdateResult($this, $batch->command);
        }

        $children = $this->children;

        foreach ($batch->overrides as $index => $child) {
            $children[$index] = $child;
        }

        return new UpdateResult($this->cloneWith(children: array_values($children), layoutMemo: self::newLayoutMemo()), $batch->command);
    }

    /**
     * The focused child answers first. Null means it did not, and the pane may
     * treat the message as its own.
     *
     * @return ?UpdateResult<self>
     */
    private function delegatedToFocusedChild(Message $message, Rect $area): ?UpdateResult
    {
        $children = $this->focusedChildren();
        $layout = VerticalStack::layoutUsing($this->layoutMemo, $children, $area->width, $area->height);
        $y = $area->y - $this->resolvedOffset($layout, $area->height);

        foreach ($children as $index => $child) {
            $height = $layout->heights[$index];

            if ($child instanceof Interactive && $child instanceof Focusable && $child->isFocused()) {
                $result = (new ChildDispatcher($this->globalKeybindings))->dispatchOne(
                    $child,
                    $message,
                    new Rect($area->x, $y, $layout->childWidth, $height),
                );

                $updated = $result->changed
                    ? $this->withChildAt($index, $result->child)
                    : $this;
                $updated = $updated->followingCursorOf($result->child, $layout, $index, $area->height);

                return $updated === $this && $result->command === null
                    ? null
                    : new UpdateResult($updated, $result->command);
            }

            $y += $height;
        }

        return null;
    }

    private function withChildAt(int $index, Widget $child): self
    {
        $children = $this->children;
        $children[$index] = $child;

        return $this->cloneWith(children: array_values($children), layoutMemo: self::newLayoutMemo());
    }

    /**
     * Scrolled just enough to bring the child's cursor row back on screen.
     */
    private function followingCursorOf(Widget $child, VerticalStackLayout $layout, int $index, int $height): self
    {
        if (!$child instanceof CursorAware || $height < 1) {
            return $this;
        }

        $row = array_sum(array_slice($layout->heights, 0, $index)) + $child->cursorRow();
        $offset = ScrollWindow::showing($this->scrollOffset, $row, 1, $height, $layout->maxScrollOffset($height));

        return $offset === $this->scrollOffset ? $this : $this->cloneWith(scrollOffset: $offset);
    }

    /**
     * Focus moved to the next or previous child, or null when it would leave
     * the pane — then the parent decides where it goes.
     */
    private function cycledFocus(KeyMsg $message): ?self
    {
        $bindings = $this->effectiveKeybindings();
        $reverse = $bindings->matches($message, Actions::FOCUS_PREVIOUS_CYCLE);

        if (!$reverse && !$bindings->matches($message, Actions::FOCUS_CYCLE)) {
            return null;
        }

        $tree = $this->tree();

        if ($tree->wouldWrap($reverse)) {
            return null;
        }

        return $this->cloneWith(focusIndex: ($reverse ? $tree->previous() : $tree->next())->active());
    }

    private function scrolledByKey(KeyMsg $message, Rect $area): ?self
    {
        $bindings = $this->effectiveKeybindings();

        return match (true) {
            $bindings->matches($message, Actions::SCROLL_DOWN) => $this->scrolledBy(self::KEY_STEP, $area),
            $bindings->matches($message, Actions::SCROLL_UP) => $this->scrolledBy(-self::KEY_STEP, $area),
            $bindings->matches($message, Actions::PAGE_DOWN) => $this->scrolledBy($area->height, $area),
            $bindings->matches($message, Actions::PAGE_UP) => $this->scrolledBy(-$area->height, $area),
            default => null,
        };
    }

    /**
     * The pane scrolled by the given rows, or null when it is already as far as
     * it goes.
     *
     * It counts from the rows on screen, not from the stored offset: following a
     * child moves the view without moving that number, so counting from it would
     * jump away from what the reader is looking at.
     */
    private function scrolledBy(int $rows, Rect $area): ?self
    {
        $layout = VerticalStack::layoutUsing($this->layoutMemo, $this->children, $area->width, $area->height);

        if (!$layout->hasScrollbar) {
            return null;
        }

        $shown = $this->resolvedOffset($layout, $area->height);
        $offset = min($layout->maxScrollOffset($area->height), max(0, $shown + $rows));

        if ($offset === $this->scrollOffset && $this->followChild === null) {
            return null;
        }

        return $this->cloneWith(scrollOffset: $offset, followChild: null);
    }

    /**
     * A click that lands on no child leaves the focus where it was.
     */
    private function focusedByClick(MouseMsg $message, Rect $area): self
    {
        $layout = VerticalStack::layoutUsing($this->layoutMemo, $this->children, $area->width, $area->height);
        $y = $area->y - $this->resolvedOffset($layout, $area->height);
        $areas = [];

        foreach ($this->children as $index => $child) {
            $height = $layout->heights[$index];

            if ($child instanceof Focusable) {
                $areas[] = new Rect($area->x, $y, $layout->childWidth, $height);
            }

            $y += $height;
        }

        return $this->withFocusAt($this->tree()->focusByClick($message, ...$areas)->active());
    }

    /**
     * The offset actually used: the stored one, unless a followed child would
     * fall outside the viewport.
     */
    private function resolvedOffset(VerticalStackLayout $layout, int $viewportHeight): int
    {
        $offset = $layout->clampScrollOffset($this->scrollOffset, $viewportHeight);

        if ($this->followChild === null || $viewportHeight < 1) {
            return $offset;
        }

        $first = max(0, $this->followChild);

        return ScrollWindow::showing(
            $offset,
            array_sum(array_slice($layout->heights, 0, $first)),
            array_sum(array_slice($layout->heights, $first, $this->followSpan)),
            $viewportHeight,
            $layout->maxScrollOffset($viewportHeight),
        );
    }

    private function tree(): FocusTree
    {
        return new FocusTree($this->focusableCount(), $this->focusIndex);
    }

    private function focusableCount(): int
    {
        return count($this->focusable());
    }

    /**
     * @return list<Focusable>
     */
    private function focusable(): array
    {
        return array_values(array_filter(
            $this->children,
            static fn (Widget $child): bool => $child instanceof Focusable,
        ));
    }

    private static function holdsKeyboard(Widget $child): bool
    {
        return match (true) {
            $child instanceof HoldsTheKeyboard => $child->isHeld(),
            $child instanceof Focusable => $child->isFocused(),
            default => false,
        };
    }

    /**
     * The children as they should be drawn: the focused one marked, the rest
     * blurred, and nothing focused at all while the pane itself is not.
     *
     * @return list<Widget>
     */
    private function focusedChildren(): array
    {
        return $this->childrenFocused($this->focused);
    }

    /**
     * @return list<Widget>
     */
    private function childrenFocused(bool $focused): array
    {
        $focusable = $this->focusable();

        if ($focusable === []) {
            return $this->children;
        }

        return FocusTree::merge(
            $this->children,
            $focused
                ? $this->tree()->apply($focusable)
                : array_map(static fn (Focusable $child): Focusable => $child->withFocus(false), $focusable),
        );
    }
}
