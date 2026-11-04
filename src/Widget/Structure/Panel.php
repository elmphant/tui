<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Inset;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\ClipsItsChildren;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focus\FollowsTheKeyboard;
use Elmphant\Tui\Widget\Focus\GraftsItsChildren;
use Elmphant\Tui\Widget\Focus\Navigates;
use Elmphant\Tui\Widget\Focus\Navigation;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

final readonly class Panel implements ClipsItsChildren, FollowsTheKeyboard, GraftsItsChildren, KeybindingsAware, MeasureHeight, Navigates, WidgetContainer, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;
    /**
     * Use a height large enough to measure decoration without clipping it.
     */
    private const CHROME_PROBE_HEIGHT = 1_000;

    private ScrollPane $pane;

    public function __construct(
        private string $title = '',
        ?ScrollPane $pane = null,
        private bool $emphasizedBorder = false,
        private ?Style $borderStyleOverride = null,
        private ?Style $focusedBorderStyleOverride = null,
        private ?Style $titleStyleOverride = null,
        private ?Spacing $padding = null,
        private ?Spacing $margin = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private ?PinnedRows $header = null,
        private ?PinnedRows $footer = null,
        private ?Navigation $navigation = null,
    ) {
        $this->pane = $pane ?? ScrollPane::of();
    }

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
        return $children === $this->pane->children()
            ? $this
            : $this->withPane($this->pane->withChildren($children));
    }

    public function add(Widget $child): self
    {
        return $this->withPane($this->pane->add($child));
    }

    public function withHeader(?PinnedRows $header): self
    {
        return $this->cloneWith(header: $header);
    }

    /**
     * Place the footer's rule and gap above it, mirroring the header.
     */
    public function withFooter(?PinnedRows $footer): self
    {
        return $this->cloneWith(footer: $footer);
    }

    /**
     * Emphasize the border without changing focus, using the current render theme.
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

    public function scrollOffset(Rect $area): int
    {
        return $this->pane->scrollOffset($this->contentArea($area));
    }

    public function marked(): ?MarkedRange
    {
        return $this->pane->marked();
    }

    public function withMarked(?MarkedRange $marked): self
    {
        return $this->withPane($this->pane->withMarked($marked));
    }

    public function withFollowChild(?int $index, int $span = 1): self
    {
        return $this->withPane($this->pane->withFollowChild($index, $span));
    }

    public function withFollowingTheEnd(bool $follows = true): self
    {
        return $this->withPane($this->pane->withFollowingTheEnd($follows));
    }

    public function followsTheEnd(): bool
    {
        return $this->pane->followsTheEnd();
    }

    public function withKeyboardAt(?int $child): static
    {
        return $this->withPane($this->pane->withKeyboardAt($child));
    }

    public function navigation(): Navigation
    {
        return $this->navigation ?? $this->pane->navigation();
    }

    /**
     * A bound panel that is `Never` a stop still hears the mouse, so a reader can
     * copy from a card that holds nothing to operate.
     */
    public function withNavigation(?Navigation $navigation): self
    {
        return $this->cloneWith(navigation: $navigation);
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
        $content = $this->contentArea($area);
        $result = (new ChildDispatcher())->dispatchOne($this->pane, $this->pressedOnTheText($message, $area, $content), $content);

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
        [$headerArea, $rowsArea, $footerArea, $headerRule, $footerRule] = $this->regionsIn($area);

        foreach ([[$this->header?->widget, $headerArea], [$this->pane, $rowsArea], [$this->footer?->widget, $footerArea]] as [$part, $partArea]) {
            if ($part !== null && $partArea->width > 0 && $partArea->height > 0) {
                $part->render($frame, $partArea, $context);
            }
        }

        $inset = $this->insetIn($area);
        if (!$inset->border) {
            return;
        }

        $this->block($context)->render($frame, $area);

        foreach ([[$this->header, $headerRule], [$this->footer, $footerRule]] as [$pinned, $row]) {
            if ($pinned !== null && $row !== null) {
                $this->renderRule($frame, $inset->margin->applyTo($area), $row, $pinned->caption, $context);
            }
        }
    }

    public function measureHeight(int $width): int
    {
        // Measure decoration height separately because uneven padding can use different row and column counts.
        $probe = new Rect(0, 0, $width, self::CHROME_PROBE_HEIGHT);
        $inner = $this->inset()->inner($probe);

        if ($inner->width < 1) {
            return 0;
        }

        $pinned = 0;
        foreach (array_filter([$this->header, $this->footer]) as $part) {
            $pinned += $part->rows($inner->width) + $part->ruleRows() + $part->gap;
        }

        return $this->pane->measureHeight($inner->width) + $pinned + ($probe->height - $inner->height);
    }

    /**
     * Hosts routing input manually must use this same child area so hit-testing and rendering agree.
     */
    public function contentArea(Rect $area): Rect
    {
        return $this->regionsIn($area)[1];
    }

    public function footerArea(Rect $area): Rect
    {
        return $this->regionsIn($area)[2];
    }

    public function viewport(Rect $area, Widget $child): Rect
    {
        return $this->contentArea($area);
    }

    /**
     * A press in the padding or below the last row starts a mark at the nearest
     * text cell, so the reader need not hit the text exactly.
     */
    private function pressedOnTheText(Message $message, Rect $area, Rect $content): Message
    {
        $rows = min($content->height, $this->pane->measureHeight($content->width));
        $inside = (new Inset($this->margin, border: true))->inner($area);

        if (!$message instanceof MouseMsg || !$message->isClick() || !$message->isIn($inside) || $rows < 1) {
            return $message;
        }

        return new MouseMsg(
            max($content->x, min($content->right() - 1, $message->x)),
            max($content->y, min($content->y + $rows - 1, $message->y)),
            $message->action,
            $message->button,
            $message->repeat,
            $message->screen,
            ctrl: $message->ctrl,
            alt: $message->alt,
            shift: $message->shift,
            keyboardWasHere: $message->keyboardWasHere,
        );
    }

    private function inset(): Inset
    {
        return new Inset($this->margin, border: true, padding: $this->padding);
    }

    private function insetIn(Rect $area): Inset
    {
        return $this->inset()->fitting($area);
    }

    /**
     * @return array{Rect, Rect, Rect, ?int, ?int} header, scrolling rows, footer, and the rows of their rules
     */
    private function regionsIn(Rect $area): array
    {
        $inset = $this->insetIn($area);
        $inner = $inset->inner($area);
        $pinnedRows = ($this->header?->rows($inner->width) ?? 0) + ($this->footer?->rows($inner->width) ?? 0);
        $reserved = $this->pane->measureHeight($inner->width) > 0 ? 1 : 0;
        if ($inner->height - $pinnedRows < $reserved) {
            $sidesOnly = Spacing::only(right: $inset->padding->right, left: $inset->padding->left);
            $inner = (new Inset($inset->margin, $inset->border, $sidesOnly))->inner($area);
        }

        $left = $inner->height;
        $headerRows = $this->taken($left, $this->header?->rows($inner->width) ?? 0);
        $footerRows = $this->taken($left, $this->footer?->rows($inner->width) ?? 0);
        $headerRule = $this->taken($left, min($this->header?->ruleRows() ?? 0, max(0, $left - $reserved)));
        $footerRule = $this->taken($left, min($this->footer?->ruleRows() ?? 0, max(0, $left - $reserved)));
        $headerGap = $this->header->gap ?? 0;
        $footerGap = $this->footer->gap ?? 0;

        if ($headerGap + $footerGap > 0 && $headerGap + $footerGap > $left - $this->pane->measureHeight($inner->width)) {
            $headerGap = 0;
            $footerGap = 0;
        }

        $footerTop = $inner->bottom() - $footerRows;
        $rowsTop = $inner->y + $headerRows + $headerRule + $headerGap;

        return [
            new Rect($inner->x, $inner->y, $inner->width, $headerRows),
            new Rect($inner->x, $rowsTop, $inner->width, $left - $headerGap - $footerGap),
            new Rect($inner->x, $footerTop, $inner->width, $footerRows),
            $headerRule > 0 ? $inner->y + $headerRows : null,
            $footerRule > 0 ? $footerTop - 1 : null,
        ];
    }

    private function taken(int &$left, int $wanted): int
    {
        $taken = min($wanted, $left);
        $left -= $taken;

        return $taken;
    }

    /**
     * Keep the divider dim except where it joins the focused outer border.
     */
    private function renderRule(Frame $frame, Rect $border, int $row, ?string $caption, RenderContext $context): void
    {
        $prominent = $this->drawsFocused($context) || $this->emphasizedBorder;
        $glyphs = $context->theme->borderTypeFor($prominent)->glyphs();
        $quiet = $this->borderStyleOverride ?? $context->theme->borderStyleFor(false);
        $joint = ($prominent ? $this->focusedBorderStyleOverride : $this->borderStyleOverride) ?? $context->theme->borderStyleFor($prominent);

        $span = max(0, $border->width - 2);
        $label = $caption === null ? '' : ' ' . $caption . ' ';
        $labelWidth = CharWidth::stringWidth($label);
        $line = $labelWidth > 0 && $span - $labelWidth >= 1
            ? str_repeat($glyphs->horizontal, $span - $labelWidth) . $label
            : str_repeat($glyphs->horizontal, $span);

        $frame->putStyled($border->x + 1, $row, $line, $quiet);
        $frame->putStyled($border->x, $row, $glyphs->teeRight, $joint);
        $frame->putStyled($border->right() - 1, $row, $glyphs->teeLeft, $joint);
    }

    private function block(RenderContext $context): Block
    {
        $theme = $context->theme;

        $prominent = $this->drawsFocused($context) || $this->emphasizedBorder;

        $override = $prominent ? $this->focusedBorderStyleOverride : $this->borderStyleOverride;

        $block = Block::new()
            ->withBorderType($theme->borderTypeFor($prominent))
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
