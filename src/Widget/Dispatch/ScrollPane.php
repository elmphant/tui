<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Dispatch;

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
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Support\KeyedCache;
use Elmphant\Tui\Widget\Bar\Scrollbar;
use Elmphant\Tui\Widget\ClipsItsChildren;
use Elmphant\Tui\Widget\CursorAware;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focus\HoldsTheKeyboard;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\FollowsTheKeyboard;
use Elmphant\Tui\Widget\GraftsItsChildren;
use Elmphant\Tui\Widget\Layout\VerticalStack;
use Elmphant\Tui\Widget\Layout\VerticalStackLayout;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Navigates;
use Elmphant\Tui\Widget\Navigation;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

/**
 * A vertical stack of children in a scrolling viewport.
 */
final readonly class ScrollPane implements ClipsItsChildren, FollowsTheKeyboard, GraftsItsChildren, KeybindingsAware, MeasureHeight, Navigates, WidgetContainer, PublishesHints
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
     */
    private function __construct(
        private array $children = [],
        private int $scrollOffset = 0,
        private ?int $followChild = null,
        private int $followSpan = 1,
        private bool $scrolledAway = false,
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

    public function scrollOffset(): int
    {
        return $this->scrollOffset;
    }

    /**
     * Scrolling by hand ends any follow: the reader has taken over.
     */
    public function withScrollOffset(int $scrollOffset): self
    {
        return $this->cloneWith(scrollOffset: max(0, $scrollOffset), scrolledAway: true);
    }

    /**
     * Keep the given child, or a run of them, in view whatever the offset says.
     */
    public function withFollowChild(?int $index, int $span = 1): self
    {
        return $this->cloneWith(followChild: $index, followSpan: max(1, $span), scrolledAway: false);
    }

    public function withKeyboardAt(?int $child): static
    {
        return $this->cloneWith(followChild: $child, followSpan: 1, scrolledAway: false);
    }

    /**
     * Where the pane holds anything the keyboard can reach, those children are the
     * stops and the frame around them is not one.
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
        ]);
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            'scroll' => [Actions::SCROLL_UP, Actions::SCROLL_DOWN],
        ]);
    }

    /**
     * Scrolling, and nothing else: each child the keyboard can reach is a stop of
     * its own, so a message meant for one arrives there rather than through here.
     */
    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg) {
            return new UpdateResult($this->scrolledByKey($message, $area) ?? $this);
        }

        if ($message instanceof MouseMsg && $message->inRect($area)) {
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
    public function placedChildren(Rect $area): iterable
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

    public function viewport(Rect $area): Rect
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

        $loose = $context->keyboardIsLoose() && !$this->aRowIsMarked() ? $this->firstFocusableIndex() : null;

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

        if ($offset === $this->scrollOffset && !$this->following()) {
            return null;
        }

        return $this->cloneWith(scrollOffset: $offset, scrolledAway: true);
    }

    private function following(): bool
    {
        return $this->followChild !== null && !$this->scrolledAway;
    }

    /**
     * The offset actually used: the stored one, unless a followed child would
     * fall outside the viewport.
     */
    private function resolvedOffset(VerticalStackLayout $layout, int $viewportHeight): int
    {
        $offset = $layout->clampScrollOffset($this->scrollOffset, $viewportHeight);

        if (!$this->following() || $viewportHeight < 1) {
            return $offset;
        }

        $first = max(0, $this->followChild ?? 0);
        $top = array_sum(array_slice($layout->heights, 0, $first));
        $span = array_sum(array_slice($layout->heights, $first, $this->followSpan));
        $child = $this->children[$first] ?? null;

        // A child that knows where its cursor is says so; showing its top would
        // leave the row the reader is on below the fold whenever it is tall.
        if ($this->followSpan === 1 && $child instanceof CursorAware) {
            $top += max(0, min($child->cursorRow(), $span - 1));
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
