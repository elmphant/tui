<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\ScrollWindow;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Support\KeyedCache;
use Elmphant\Tui\Widget\Bar\Scrollbar;
use Elmphant\Tui\Widget\ClipsItsChildren;
use Elmphant\Tui\Widget\CursorAware;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focus\FollowsTheKeyboard;
use Elmphant\Tui\Widget\Focus\GraftsItsChildren;
use Elmphant\Tui\Widget\Focus\HoldsTheKeyboard;
use Elmphant\Tui\Widget\Focus\Navigates;
use Elmphant\Tui\Widget\Focus\Navigation;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Layout\VerticalStack;
use Elmphant\Tui\Widget\Layout\VerticalStackLayout;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\SingleMount;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

final readonly class ScrollPane implements ClipsItsChildren, FollowsTheKeyboard, GraftsItsChildren, KeybindingsAware, MeasureHeight, Navigates, WidgetContainer, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    private const KEY_STEP = 1;

    /**
     * Half of PHP_INT_MAX, so adding it to an offset cannot overflow into a float.
     */
    private const ALL_THE_WAY = PHP_INT_MAX >> 1;

    /**
     * @var KeyedCache<VerticalStackLayout>
     */
    private KeyedCache $layoutMemo;

    /**
     * @param list<Widget>                         $children
     * @param KeyedCache<VerticalStackLayout>|null $layoutMemo
     */
    private function __construct(
        private array $children = [],
        private int $scrollOffset = 0,
        private ?int $followChild = null,
        private int $followSpan = 1,
        private bool $scrolledAway = false,
        private bool $followingTheEnd = false,
        private ?MarkedRange $marked = null,
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

    /**
     * @param list<Widget> $children
     */
    public function withChildren(array $children): self
    {
        return $this->cloneWith(children: $children, layoutMemo: self::newLayoutMemo());
    }

    public function scrollOffset(Rect $area): int
    {
        $layout = VerticalStack::layoutUsing($this->layoutMemo, $this->children, $area->width, $area->height);

        return $this->resolvedOffset($layout, $area->height);
    }

    /**
     * Scrolling by hand ends any follow: the reader has taken over.
     */
    public function withScrollOffset(int $scrollOffset): self
    {
        return $this->cloneWith(scrollOffset: max(0, $scrollOffset), scrolledAway: true);
    }

    public function marked(): ?MarkedRange
    {
        return $this->marked;
    }

    public function withMarked(?MarkedRange $marked): self
    {
        return $this->cloneWith(marked: $marked);
    }

    public function withFollowChild(?int $index, int $span = 1): self
    {
        return $this->cloneWith(followChild: $index, followSpan: max(1, $span), scrolledAway: false);
    }

    /**
     * Keep the last row in view as children arrive, until the reader scrolls up;
     * scrolling back down to the end follows it again.
     */
    public function withFollowingTheEnd(bool $follows = true): self
    {
        return $this->cloneWith(followingTheEnd: $follows, scrolledAway: false);
    }

    public function followsTheEnd(): bool
    {
        return $this->followingTheEnd && !$this->scrolledAway && !$this->following();
    }

    public function withKeyboardAt(?int $child): static
    {
        return $this->cloneWith(followChild: $child, followSpan: 1, scrolledAway: false);
    }

    /**
     * Skip the pane in tab order when its children provide focus stops.
     */
    public function navigation(): Navigation
    {
        return self::holdsAFocusable($this->children) ? Navigation::Never : Navigation::Regular;
    }

    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::SCROLL_UP => ['up'],
            Actions::SCROLL_DOWN => ['down'],
            Actions::PAGE_UP => ['shift+up', 'page_up'],
            Actions::PAGE_DOWN => ['shift+down', 'page_down'],
            Actions::HOME => ['home'],
            Actions::END => ['end'],
        ]);
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            'scroll' => [Actions::SCROLL_UP, Actions::SCROLL_DOWN],
        ]);
    }

    /**
     * Handle scrolling here; input for focusable children reaches their own stops directly.
     */
    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg) {
            return new UpdateResult($this->scrolledByKey($message, $area) ?? $this);
        }

        $marking = $message instanceof MouseMsg ? $this->markedBy($message, $area) : null;
        if ($marking !== null) {
            return $marking;
        }

        if ($message instanceof MouseMsg && $message->isIn($area)) {
            return new UpdateResult($this->scrolledByWheel($message, $area) ?? $this);
        }

        return new UpdateResult($this);
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
        return $this->children;
    }

    /**
     * Return original children without focus markers so hosts can compare them before rebuilding.
     *
     * @return list<Widget>
     */
    public function authoredChildren(): array
    {
        return $this->children;
    }

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
    public function placedChildren(Rect $area): iterable
    {
        // Use original child instances for geometry; marked copies may never appear in the rendered tree.
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

    public function viewport(Rect $area, Widget $child): Rect
    {
        return $area;
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $children = $this->children;
        $layout = VerticalStack::layoutUsing($this->layoutMemo, $children, $area->width, $area->height);
        $offset = $this->resolvedOffset($layout, $area->height);

        $loose = $context->isKeyboardLoose() && !$this->aRowIsMarked() ? $this->firstFocusableIndex() : null;

        $frame->pushClip(new Rect($area->x, $area->y, $layout->childWidth, $area->height));

        try {
            $y = $area->y - $offset;

            foreach ($children as $index => $child) {
                $height = $layout->heights[$index];

                if ($y + $height > $area->y && $y < $area->bottom()) {
                    $childArea = new Rect($area->x, $y, $layout->childWidth, $height);

                    $draw = static function () use ($child, $frame, $childArea, $context): void {
                        $child->render($frame, $childArea, $context);
                    };

                    if (($child instanceof HoldsTheKeyboard && $child->isHeld()) || $index === $loose) {
                        $draw();
                    } else {
                        $context->withinChild($index, $draw);
                    }
                }

                $y += $height;

                if ($y >= $area->bottom()) {
                    break;
                }
            }

            $this->drawMark($frame, $area, $offset, $layout->childWidth);
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

    public function withChildAt(int $index, Widget $child): static
    {
        $children = $this->children;
        $children[$index] = $child;

        return $this->cloneWith(children: array_values($children), layoutMemo: self::newLayoutMemo());
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
     * @return ?UpdateResult<self>
     */
    private function markedBy(MouseMsg $message, Rect $area): ?UpdateResult
    {
        $layout = VerticalStack::layoutUsing($this->layoutMemo, $this->children, $area->width, $area->height);
        $marked = $this->markedAt($layout->childWidth);

        if ($message->action === MouseAction::Press) {
            [$row, $column] = $this->cellAt($message, $area, $layout);
            $marks = $message->button === MouseButton::Left
                && $message->isIn($area)
                && $this->resolvedOffset($layout, $area->height) + $message->y - $area->y < $layout->totalHeight;

            if (!$marks) {
                return $marked === null ? null : new UpdateResult($this->cloneWith(marked: null));
            }

            return new UpdateResult($this->cloneWith(
                marked: new MarkedRange($row, $column, $row, $column, $layout->childWidth, $this->scrolledAway),
                scrollOffset: $this->resolvedOffset($layout, $area->height),
                scrolledAway: true,
            ));
        }

        if ($marked === null || !$marked->dragging) {
            return null;
        }

        if ($message->action === MouseAction::Motion) {
            $edge = match (true) {
                $message->y < $area->y => -1,
                $message->y >= $area->bottom() => 1,
                default => 0,
            };
            $scrolled = ($edge === 0 ? null : $this->scrolledBy($edge, $area)) ?? $this;
            [$row, $column] = $scrolled->cellAt($message, $area, $layout);

            return new UpdateResult($scrolled->cloneWith(marked: $marked->withEnd($row, $column)));
        }

        [$row, $column] = $this->cellAt($message, $area, $layout);

        return match ($message->action) {
            MouseAction::Release => $marked->isEmpty()
                ? new UpdateResult($this->cloneWith(marked: null, scrolledAway: $marked->wasScrolledAway))
                : new UpdateResult($this->cloneWith(marked: $marked->released()), Cmd::copy($this->textOf($marked, $layout))),
            default => null,
        };
    }

    /**
     * A mark made at another width covers text that has wrapped differently since.
     */
    private function markedAt(int $width): ?MarkedRange
    {
        return $this->marked?->width === $width ? $this->marked : null;
    }

    /**
     * @return array{int, int} the content row and column under the pointer, held inside the pane
     */
    private function cellAt(MouseMsg $message, Rect $area, VerticalStackLayout $layout): array
    {
        return [
            min($layout->totalHeight - 1, $this->resolvedOffset($layout, $area->height) + max(0, min($area->height - 1, $message->y - $area->y))),
            max(0, min($layout->childWidth - 1, $message->x - $area->x)),
        ];
    }

    /**
     * Drawn again off screen, so rows scrolled out of view copy too.
     */
    private function textOf(MarkedRange $marked, VerticalStackLayout $layout): string
    {
        $first = $marked->firstRow();
        $frame = new Frame($layout->childWidth, $marked->lastRow() - $first + 1);
        $context = RenderContext::unfocused(Theme::dark());
        $top = 0;

        foreach ($this->children as $index => $child) {
            $height = $layout->heights[$index];

            if ($top + $height > $first && $top <= $marked->lastRow()) {
                $child->render($frame, new Rect(0, $top - $first, $layout->childWidth, $height), $context);
            }

            $top += $height;
        }

        $lines = [];
        $dropped = null;
        for ($row = 0; $row < $frame->height; ++$row) {
            [$from, $to] = $marked->columnsOn($first + $row);
            $line = rtrim($frame->textOf($row, $from, $to), ' ');

            if ($dropped === null) {
                $lines[] = $line;
            } else {
                $lines[array_key_last($lines)] .= $dropped . ltrim($line, ' ');
            }

            $dropped = $frame->continuation($row);
        }

        return implode("\n", $lines);
    }

    private function drawMark(Frame $frame, Rect $area, int $offset, int $width): void
    {
        $marked = $this->markedAt($width);

        if ($marked === null) {
            return;
        }

        for ($row = $marked->firstRow(); $row <= $marked->lastRow(); ++$row) {
            [$from, $to] = $marked->columnsOn($row);
            $frame->toggleAttribute($area->x + $from, $area->y + $row - $offset, $to - $from, Attr::REVERSE);
        }
    }

    private function scrolledByWheel(MouseMsg $message, Rect $area): ?self
    {
        return match ($message->action) {
            MouseAction::WheelDown => $this->scrolledBy($message->wheelSteps(), $area),
            MouseAction::WheelUp => $this->scrolledBy(-$message->wheelSteps(), $area),
            default => null,
        };
    }

    private function scrolledByKey(KeyMsg $message, Rect $area): ?self
    {
        $bindings = $this->effectiveKeybindings();

        return match (true) {
            $bindings->matches($message, Actions::SCROLL_DOWN) => $this->scrolledBy(self::KEY_STEP, $area),
            $bindings->matches($message, Actions::SCROLL_UP) => $this->scrolledBy(-self::KEY_STEP, $area),
            $bindings->matches($message, Actions::PAGE_DOWN) => $this->scrolledBy($area->height, $area),
            $bindings->matches($message, Actions::PAGE_UP) => $this->scrolledBy(-$area->height, $area),
            $bindings->matches($message, Actions::END) => $this->scrolledBy(self::ALL_THE_WAY, $area),
            $bindings->matches($message, Actions::HOME) => $this->scrolledBy(-self::ALL_THE_WAY, $area),
            default => null,
        };
    }

    /**
     * Scroll from the visible offset, which may differ from stored state while following a child; return null at the limit.
     */
    private function scrolledBy(int $rows, Rect $area): ?self
    {
        $layout = VerticalStack::layoutUsing($this->layoutMemo, $this->children, $area->width, $area->height);

        if (!$layout->hasScrollbar) {
            return null;
        }

        $shown = $this->resolvedOffset($layout, $area->height);
        $offset = min($layout->maxScrollOffset($area->height), max(0, $shown + $rows));

        if ($offset === $shown && !$this->following()) {
            return null;
        }

        $atTheEnd = $this->followingTheEnd && $offset === $layout->maxScrollOffset($area->height);

        return $this->cloneWith(
            scrollOffset: $offset,
            followChild: $atTheEnd ? null : $this->followChild,
            scrolledAway: !$atTheEnd,
        );
    }

    private function following(): bool
    {
        return $this->followChild !== null && !$this->scrolledAway;
    }

    private function resolvedOffset(VerticalStackLayout $layout, int $viewportHeight): int
    {
        $offset = $layout->clampScrollOffset($this->scrollOffset, $viewportHeight);

        if ($viewportHeight < 1) {
            return $offset;
        }

        if (!$this->following()) {
            return $this->followsTheEnd() ? $layout->maxScrollOffset($viewportHeight) : $offset;
        }

        $first = max(0, $this->followChild ?? 0);
        $top = array_sum(array_slice($layout->heights, 0, $first));
        $span = array_sum(array_slice($layout->heights, $first, $this->followSpan));

        // When a followed span is too tall, prioritize its last child, which holds focus.
        if ($span > $viewportHeight && $this->followSpan > 1) {
            $last = min($first + $this->followSpan, \count($layout->heights)) - 1;
            $top += array_sum(array_slice($layout->heights, $first, $last - $first));
            $span = $layout->heights[$last] ?? 0;
        }
        $child = $this->children[$first] ?? null;

        // Follow the child's cursor rather than its top, or a tall child could leave the cursor off screen.
        if ($this->followSpan === 1 && $child instanceof CursorAware) {
            $top += max(0, min($child->cursorLine(), $span - 1));
            $span = 1;
        }

        return ScrollWindow::showing(
            $offset,
            $top,
            $span,
            $viewportHeight,
            $layout->maxScrollOffset($viewportHeight),
        );
    }

    /**
     * A host that marks its rows has said where the keyboard is.
     */
    private function aRowIsMarked(): bool
    {
        foreach ($this->children as $child) {
            if ($child instanceof HoldsTheKeyboard && $child->isHeld()) {
                return true;
            }
        }

        return false;
    }

    private function firstFocusableIndex(): ?int
    {
        foreach ($this->children as $index => $child) {
            if (self::holdsAFocusable([$child])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param iterable<Widget> $widgets
     */
    private static function holdsAFocusable(iterable $widgets): bool
    {
        foreach ($widgets as $widget) {
            if ($widget instanceof Focusable && (!$widget instanceof Navigates || $widget->navigation() !== Navigation::Never)) {
                return true;
            }

            $opens = $widget instanceof GraftsItsChildren || ($widget instanceof SingleMount && $widget instanceof WidgetContainer);
            if ($opens && self::holdsAFocusable($widget->children())) {
                return true;
            }
        }

        return false;
    }
}
