<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Inset;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Widget\ClipsItsChildren;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Dispatch\ScrollPane;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\FollowsTheKeyboard;
use Elmphant\Tui\Widget\GraftsItsChildren;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Navigates;
use Elmphant\Tui\Widget\Navigation;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

final readonly class Panel implements ClipsItsChildren, FollowsTheKeyboard, GraftsItsChildren, KeybindingsAware, MeasureHeight, Navigates, WidgetContainer, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;
    /**
     * Tall enough that no chrome can clamp it away, so the rows it takes can be
     * read off the difference.
     */
    private const CHROME_PROBE_HEIGHT = 1_000;

    public function __construct(
        private string $title = '',
        private ScrollPane $pane = new ScrollPane(),
        private bool $emphasizedBorder = false,
        private ?Style $borderStyleOverride = null,
        private ?Style $focusedBorderStyleOverride = null,
        private ?Style $titleStyleOverride = null,
        private ?Spacing $padding = null,
        private ?Spacing $margin = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    /**
     * The pane does the scrolling and the focus cycling, so it owns the keys.
     */
    public static function defaultKeybindings(): Keybindings
    {
        return ScrollPane::defaultKeybindings();
    }

    public static function new(string $title = ''): self
    {
        return new self(title: $title);
    }

    public function hints(): array
    {
        return $this->pane->hints();
    }

    public function withTitle(string $title): self
    {
        return $this->cloneWith(title: $title);
    }

    /**
     * @param list<Widget> $children
     */
    public function withChildren(array $children): self
    {
        return $children === $this->pane->authoredChildren()
            ? $this
            : $this->withPane($this->pane->withChildren($children));
    }

    public function add(Widget $child): self
    {
        return $this->withPane($this->pane->add($child));
    }

    /**
     * Draw the border the way a focused panel draws it, without taking focus.
     *
     * The style comes from the theme in use at render time, so it stays
     * correct when the same panel is rendered with another theme.
     */
    public function withEmphasizedBorder(bool $emphasized = true): self
    {
        return $this->cloneWith(emphasizedBorder: $emphasized);
    }

    public function withBorderStyle(?Style $style): self
    {
        return $this->cloneWith(borderStyleOverride: $style);
    }

    public function withFocusedBorderStyle(?Style $style): self
    {
        return $this->cloneWith(focusedBorderStyleOverride: $style);
    }

    public function withTitleStyle(?Style $style): self
    {
        return $this->cloneWith(titleStyleOverride: $style);
    }

    public function withPadding(Spacing $padding): static
    {
        return $this->cloneWith(padding: $padding);
    }

    public function withMargin(Spacing $margin): static
    {
        return $this->cloneWith(margin: $margin);
    }

    public function withScrollOffset(int $scrollOffset): self
    {
        return $this->withPane($this->pane->withScrollOffset($scrollOffset));
    }

    public function scrollOffset(): int
    {
        return $this->pane->scrollOffset();
    }

    public function withFollowChild(?int $index, int $span = 1): self
    {
        return $this->withPane($this->pane->withFollowChild($index, $span));
    }

    public function withKeyboardAt(?int $child): static
    {
        return $this->withPane($this->pane->withKeyboardAt($child));
    }

    public function navigation(): Navigation
    {
        return $this->pane->navigation();
    }

    public function withKeybindings(?Keybindings $keybindings): static
    {
        return $this->cloneWith(
            customKeybindings: $keybindings,
            pane: $this->pane->withKeybindings($keybindings),
        );
    }

    public function withGlobalKeybindings(?Keybindings $keybindings): static
    {
        return $this->cloneWith(
            globalKeybindings: $keybindings,
            pane: $this->pane->withGlobalKeybindings($keybindings),
        );
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $result = (new ChildDispatcher())->dispatchOne($this->pane, $message, $this->contentArea($area));

        return $result->changed
            ? new UpdateResult($this->withPane($result->child), $result->command)
            : new UpdateResult($this, $result->command);
    }

    /**
     * @return list<Widget>
     */
    public function children(): iterable
    {
        return [...$this->pane->children()];
    }

    public function withChildAt(int $index, Widget $child): static
    {
        return $this->withPane($this->pane->withChildAt($index, $child));
    }

    /**
     * @return list<array{0: Widget, 1: Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        return [...$this->pane->placedChildren($this->contentArea($area))];
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $inset = $this->insetIn($area);
        $contentArea = $inset->inner($area);

        if ($contentArea->width > 0 && $contentArea->height > 0) {
            $this->pane->render($frame, $contentArea, $context);
        }

        if ($inset->border) {
            $this->block($context)->render($frame, $area);
        }
    }

    /**
     * What its content needs, plus the border and padding drawn around it, so a
     * parent can give a panel exactly the rows it will fill.
     */
    public function measureHeight(int $width): int
    {
        // Measured off a probe rather than assumed: the rows the chrome takes are
        // not the columns it takes once padding is uneven.
        $probe = new Rect(0, 0, $width, self::CHROME_PROBE_HEIGHT);
        $inner = $this->inset()->inner($probe);

        if ($inner->width < 1) {
            return 0;
        }

        return $this->pane->measureHeight($inner->width) + ($probe->height - $inner->height);
    }

    /**
     * Where a child is drawn inside $area, once the border, margin and padding
     * are taken off.
     *
     * Public because a caller that keeps a child of its own and feeds it input
     * has to hand it the rect the panel will draw it in; deriving that rect
     * again on the outside drifts from this one and breaks scrolling silently.
     */
    public function contentArea(Rect $area): Rect
    {
        return $this->insetIn($area)->inner($area);
    }

    public function viewport(Rect $area): Rect
    {
        return $this->contentArea($area);
    }

    private function inset(): Inset
    {
        return new Inset($this->margin, border: true, padding: $this->padding);
    }

    private function insetIn(Rect $area): Inset
    {
        return $this->inset()->fitting($area);
    }

    private function block(RenderContext $context): Block
    {
        $theme = $context->theme;

        $prominent = $this->drawsFocused($context) || $this->emphasizedBorder;

        $override = $prominent ? $this->focusedBorderStyleOverride : $this->borderStyleOverride;

        $block = Block::new()
            ->withBorder($theme->borderFor($prominent))
            ->withBorderStyle($override ?? $theme->borderStyleFor($prominent))
            ->withTitleStyle($this->titleStyleOverride ?? $theme->titleStyle)
            ->withTitle($this->title);

        if ($this->margin !== null) {
            $block = $block->withMargin($this->margin);
        }

        if ($this->padding !== null) {
            $block = $block->withPadding($this->padding);
        }

        return $block;
    }

    private function withPane(ScrollPane $pane): self
    {
        return $this->cloneWith(pane: $pane);
    }
}
