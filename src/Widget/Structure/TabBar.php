<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Closure;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Inset;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\BorderGlyphs;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Support\Places;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\ClipsItsChildren;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Focus\CapturesThePointer;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focus\FollowsTheKeyboard;
use Elmphant\Tui\Widget\Focus\GraftsItsChildren;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use LogicException;

final readonly class TabBar implements CapturesThePointer, ClipsItsChildren, Focusable, FollowsTheKeyboard, GraftsItsChildren, WidgetContainer, KeybindingsAware, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    private const TAB_STRIP_HEIGHT = 3;

    /**
     * @param list<TabPage> $tabs
     */
    private function __construct(
        private array $tabs = [],
        private int $activeTab = 0,
        private ?string $caption = null,
        private ?string $shortCaption = null,
        private ?Spacing $padding = null,
        private bool $emphasizedBorder = false,
        private ?Style $borderStyleOverride = null,
        private ?Style $focusedBorderStyleOverride = null,
        private bool $bordered = true,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    public static function defaultKeybindings(): Keybindings
    {
        return (new Keybindings([
            Actions::TAB_NEXT => ['right'],
            Actions::TAB_PREVIOUS => ['left'],
        ]))->overriddenBy(ScrollPane::defaultKeybindings());
    }

    public function addTab(string $label, Widget ...$children): self
    {
        return $this->cloneWith(tabs: [...$this->tabs, new TabPage($label, ScrollPane::of(...$children))]);
    }

    public function activeTab(): int
    {
        return $this->activeTab;
    }

    /**
     * Include hidden pages so hosts can preserve their widget state when replacing tabs.
     *
     * @return list<TabPage>
     */
    public function tabs(): array
    {
        return $this->tabs;
    }

    public function capturesThePointer(): bool
    {
        return $this->activePane()->capturesThePointer();
    }

    public function tabAt(MouseMsg $message, Rect $area): ?int
    {
        if (!$message->isClick() || !$this->fitsIn($area) || !$this->onStrip($message, $area)) {
            return null;
        }

        return $this->tabStripLayout($area)->indexAt($message->x);
    }

    /**
     * @param list<TabPage> $tabs
     */
    public function withTabs(array $tabs): self
    {
        if ($tabs === $this->tabs) {
            return $this;
        }

        return $this->cloneWith(tabs: $tabs, activeTab: Places::cursorAfter(
            ($this->tabs[$this->activeTab] ?? null)?->key(),
            array_map(static fn (TabPage $tab): string => $tab->key(), $tabs),
            $this->activeTab,
        ));
    }

    public function withActiveTab(int $activeTab): self
    {
        return $this->cloneWith(activeTab: max(0, min($activeTab, count($this->tabs) - 1)));
    }

    /**
     * Shown after the tabs instead of the key hint the bindings derive. The short form is for narrow terminals.
     */
    public function withCaption(?string $caption, ?string $shortCaption = null): self
    {
        return $this->cloneWith(caption: $caption, shortCaption: $shortCaption);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function withPadding(Spacing $padding): static
    {
        return $this->cloneWith(padding: $padding);
    }

    /**
     * Without a border the tabs take one row and the page starts right below them.
     */
    public function withBorder(bool $bordered = true): self
    {
        return $this->cloneWith(bordered: $bordered);
    }

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

    /**
     * @return array<string, string> key label => what it does
     */
    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            'previous tab' => Actions::TAB_PREVIOUS,
            'next tab' => Actions::TAB_NEXT,
        ]);
    }

    /**
     * @return list<Widget>
     */
    public function children(): array
    {
        return [...$this->activePane()->children()];
    }

    public function withChildAt(int $index, Widget $child): static
    {
        return $this->withActivePane($this->activePane()->withChildAt($index, $child));
    }

    public function withKeyboardAt(?int $child): static
    {
        return isset($this->tabs[$this->activeTab])
            ? $this->withActivePane($this->activePane()->withKeyboardAt($child))
            : $this;
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): array
    {
        return [...$this->activePane()->placedChildren($this->contentArea($area))];
    }

    public function viewport(Rect $area, Widget $child): Rect
    {
        return $this->contentArea($area);
    }

    /**
     * Children have their own focus stops; the strip belongs to the bar, and the page scrolls separately.
     */
    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$this->fitsIn($area)) {
            return new UpdateResult($this);
        }

        if ($message instanceof MouseMsg && $message->isClick() && $this->onStrip($message, $area)) {
            $index = $this->tabAt($message, $area);

            return $index === null || $index === $this->activeTab
                ? new UpdateResult($this)
                : new UpdateResult($this->cloneWith(activeTab: $index));
        }

        if ($message instanceof KeyMsg) {
            $switched = $this->switchedTab($message);

            if ($switched !== null) {
                return $switched;
            }
        }

        $result = (new ChildDispatcher())->dispatchOne($this->activePane(), $message, $this->contentArea($area));

        return $result->changed
            ? new UpdateResult($this->withActivePane(self::requirePane($result->child)), $result->command)
            : new UpdateResult($this, $result->command);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if (!$this->fitsIn($area)) {
            return;
        }

        $theme = $context->theme;

        if (!$this->bordered) {
            $this->renderContent($frame, $area, $context);
            $this->renderOneRowStrip($frame, $area, $theme, $this->drawsFocused($context));

            return;
        }

        $prominent = $this->drawsFocused($context) || $this->emphasizedBorder;
        $glyphs = ($prominent ? $theme->focusedBorderType : $theme->borderType)->glyphs();
        $borderStyle = $prominent
            ? ($this->focusedBorderStyleOverride ?? $theme->focusedBorderStyle)
            : ($this->borderStyleOverride ?? $theme->borderStyle);

        $this->renderContent($frame, $area, $context);
        $this->renderTabStrip($frame, $area, $glyphs, $borderStyle, $theme, $this->drawsFocused($context));
        $this->renderContentBox($frame, $area, $glyphs, $borderStyle);
    }

    public function withKeybindings(?Keybindings $keybindings): static
    {
        return $this->cloneWith(customKeybindings: $keybindings, tabs: $this->tabsWithPane(
            static fn (ScrollPane $pane): ScrollPane => $pane->withKeybindings($keybindings),
        ));
    }

    public function withGlobalKeybindings(?Keybindings $keybindings): static
    {
        return $this->cloneWith(globalKeybindings: $keybindings, tabs: $this->tabsWithPane(
            static fn (ScrollPane $pane): ScrollPane => $pane->withGlobalKeybindings($keybindings),
        ));
    }

    /**
     * @param Closure(ScrollPane):ScrollPane $change
     *
     * @return list<TabPage>
     */
    private function tabsWithPane(Closure $change): array
    {
        return array_map(
            static fn (TabPage $tab): TabPage => $tab->withPane($change($tab->pane)),
            $this->tabs,
        );
    }

    private function activePane(): ScrollPane
    {
        return $this->tabs[$this->activeTab]->pane ?? ScrollPane::of();
    }

    private function withActivePane(ScrollPane $pane): self
    {
        $tabs = $this->tabs;
        $tabs[$this->activeTab] = $tabs[$this->activeTab]->withPane($pane);

        return $this->cloneWith(tabs: $tabs);
    }

    /**
     * @return ?UpdateResult<static>
     */
    private function switchedTab(KeyMsg $message): ?UpdateResult
    {
        $bindings = $this->effectiveKeybindings();

        return match (true) {
            $bindings->matches($message, Actions::TAB_PREVIOUS) => $this->switchTab(-1),
            $bindings->matches($message, Actions::TAB_NEXT) => $this->switchTab(1),
            default => null,
        };
    }

    private static function requirePane(object $model): ScrollPane
    {
        if (!$model instanceof ScrollPane) {
            throw new LogicException('A ScrollPane must answer with a ScrollPane');
        }

        return $model;
    }

    /**
     * @return UpdateResult<static>
     */
    private function switchTab(int $direction): UpdateResult
    {
        $count = count($this->tabs);
        if ($count <= 1) {
            return new UpdateResult($this);
        }

        $newIndex = ($this->activeTab + $direction + $count) % $count;

        return new UpdateResult($this->cloneWith(activeTab: $newIndex));
    }

    private function onStrip(MouseMsg $message, Rect $area): bool
    {
        return $message->y >= $area->y && $message->y <= $area->y + ($this->bordered ? 1 : 0);
    }

    private function tabStripLayout(Rect $area): TabStripLayout
    {
        return TabStripLayout::across(
            array_map(static fn (TabPage $tab): string => $tab->label, $this->tabs),
            $area,
            $this->activeTab,
        );
    }

    private function renderTabStrip(
        Frame $frame,
        Rect $area,
        BorderGlyphs $border,
        Style $borderStyle,
        Theme $theme,
        bool $focused,
    ): void {
        $borderForeground = $borderStyle->foregroundValue();
        $borderBackground = $borderStyle->backgroundValue();
        $borderAttributes = $borderStyle->attributes;

        $layout = $this->tabStripLayout($area);

        foreach (array_keys($this->tabs) as $tabIndex) {
            $cell = $layout->rectOf($tabIndex);
            $label = $layout->labelOf($tabIndex);
            if ($cell === null || $label === null) {
                continue;
            }

            $labelRow = $cell->y + 1;

            if ($tabIndex === $this->activeTab) {
                $tabRight = $cell->right() - 1;

                $frame->putChar($cell->x, $cell->y, $border->topLeft, $borderForeground, $borderBackground, $borderAttributes);
                $frame->fillRect($cell->x + 1, $cell->y, $tabRight - $cell->x - 1, 1, $borderForeground, $borderBackground, $borderAttributes, $border->horizontal);
                $frame->putChar($tabRight, $cell->y, $border->topRight, $borderForeground, $borderBackground, $borderAttributes);

                $frame->putChar($cell->x, $labelRow, $border->vertical, $borderForeground, $borderBackground, $borderAttributes);
                $frame->putChar($cell->x + 1, $labelRow, ' ', $borderForeground, $borderBackground);
                $frame->putStyled($cell->x + 2, $labelRow, $label, $theme->title);
                $frame->putChar($tabRight - 1, $labelRow, ' ', $borderForeground, $borderBackground);
                $frame->putChar($tabRight, $labelRow, $border->vertical, $borderForeground, $borderBackground, $borderAttributes);
            } else {
                $frame->putStyled($cell->x + 2, $labelRow, $label, $theme->dim);
            }
        }

        $hiddenBefore = $layout->hiddenBeforeColumn();
        if ($hiddenBefore !== null) {
            $frame->putStyled($hiddenBefore, $area->y + 1, TabStripLayout::HIDDEN_BEFORE, $theme->dim);
        }

        $hiddenAfter = $layout->hiddenAfterColumn();
        if ($hiddenAfter !== null) {
            $frame->putStyled($hiddenAfter, $area->y + 1, TabStripLayout::HIDDEN_AFTER, $theme->dim);
        }

        $this->renderTabHint($frame, $area, $area->y + 1, $layout->columnAfterLastTab(), $theme, $focused);
        $this->renderBaseline($frame, $area, $layout, $border, $borderStyle);
    }

    private function renderOneRowStrip(Frame $frame, Rect $area, Theme $theme, bool $focused): void
    {
        $layout = $this->tabStripLayout($area);

        foreach (array_keys($this->tabs) as $tabIndex) {
            $cell = $layout->rectOf($tabIndex);
            $label = $layout->labelOf($tabIndex);
            if ($cell === null || $label === null) {
                continue;
            }

            if ($tabIndex === $this->activeTab) {
                $frame->putStyled($cell->x + 1, $area->y, " {$label} ", $focused ? $theme->cursorStyle : $theme->selectedStyle);
            } else {
                $frame->putStyled($cell->x + 2, $area->y, $label, $theme->dim);
            }
        }

        $hiddenBefore = $layout->hiddenBeforeColumn();
        if ($hiddenBefore !== null) {
            $frame->putStyled($hiddenBefore, $area->y, TabStripLayout::HIDDEN_BEFORE, $theme->dim);
        }

        $hiddenAfter = $layout->hiddenAfterColumn();
        if ($hiddenAfter !== null) {
            $frame->putStyled($hiddenAfter, $area->y, TabStripLayout::HIDDEN_AFTER, $theme->dim);
        }

        $this->renderTabHint($frame, $area, $area->y, $layout->columnAfterLastTab(), $theme, $focused);
    }

    private function renderBaseline(
        Frame $frame,
        Rect $area,
        TabStripLayout $layout,
        BorderGlyphs $border,
        Style $borderStyle,
    ): void {
        $foreground = $borderStyle->foregroundValue();
        $background = $borderStyle->backgroundValue();
        $attributes = $borderStyle->attributes;
        $baselineY = $area->y + 2;

        $frame->putChar($area->x, $baselineY, $border->topLeft, $foreground, $background, $attributes);
        $frame->fillRect($area->x + 1, $baselineY, $area->width - 2, 1, $foreground, $background, $attributes, $border->horizontal);
        $frame->putChar($area->right() - 1, $baselineY, $border->topRight, $foreground, $background, $attributes);

        $cell = $layout->rectOf($this->activeTab);
        if ($cell === null) {
            return;
        }

        $frame->putChar(
            $cell->x,
            $baselineY,
            $cell->x === $area->x ? $border->vertical : $border->bottomRight,
            $foreground,
            $background,
            $attributes,
        );

        $tabRight = $cell->right() - 1;
        $frame->putChar($tabRight, $baselineY, $border->bottomLeft, $foreground, $background, $attributes);

        $frame->fillRect($cell->x + 1, $baselineY, $tabRight - $cell->x - 1, 1, 0, $background);
    }

    private function renderTabHint(Frame $frame, Rect $area, int $row, int $afterTabsX, Theme $theme, bool $focused): void
    {
        $rightEdge = $area->right() - 1;
        $text = $this->hintWithin($rightEdge - $afterTabsX, $focused);

        if ($text === null) {
            return;
        }

        $frame->putStyled($rightEdge - CharWidth::stringWidth($text) - 1, $row, $text, $theme->dim);
    }

    private function hintWithin(int $availableWidth, bool $focused): ?string
    {
        if ($this->caption !== null) {
            return match (true) {
                self::fits($this->caption, $availableWidth) => $this->caption,
                $this->shortCaption !== null && self::fits($this->shortCaption, $availableWidth) => $this->shortCaption,
                default => null,
            };
        }

        // Hide inline key hints when unfocused because the bar ignores keys; footer hints remain available.
        if (!$focused || count($this->tabs) < 2) {
            return null;
        }

        $keys = $this->switchKeyLabels();
        if ($keys === null) {
            return null;
        }

        return match (true) {
            self::fits($keys . ' to switch', $availableWidth) => $keys . ' to switch',
            self::fits($keys, $availableWidth) => $keys,
            default => null,
        };
    }

    private static function fits(string $text, int $availableWidth): bool
    {
        return $availableWidth >= CharWidth::stringWidth($text) + 2;
    }

    private function switchKeyLabels(): ?string
    {
        $bindings = $this->effectiveKeybindings();
        $previous = $bindings->patternsOf(Actions::TAB_PREVIOUS)[0] ?? null;
        $next = $bindings->patternsOf(Actions::TAB_NEXT)[0] ?? null;

        if ($previous === null || $next === null) {
            return null;
        }

        return $previous->label() . ' ' . $next->label();
    }

    private function renderContentBox(
        Frame $frame,
        Rect $area,
        BorderGlyphs $border,
        Style $borderStyle,
    ): void {
        $foreground = $borderStyle->foregroundValue();
        $background = $borderStyle->backgroundValue();
        $attributes = $borderStyle->attributes;

        $sideHeight = $area->bottom() - 1 - ($area->y + 3);
        $frame->fillRect($area->x, $area->y + 3, 1, $sideHeight, $foreground, $background, $attributes, $border->vertical);
        $frame->fillRect($area->right() - 1, $area->y + 3, 1, $sideHeight, $foreground, $background, $attributes, $border->vertical);

        $bottomY = $area->bottom() - 1;
        $frame->putChar($area->x, $bottomY, $border->bottomLeft, $foreground, $background, $attributes);
        $frame->fillRect($area->x + 1, $bottomY, $area->width - 2, 1, $foreground, $background, $attributes, $border->horizontal);
        $frame->putChar($area->right() - 1, $bottomY, $border->bottomRight, $foreground, $background, $attributes);
    }

    private function renderContent(Frame $frame, Rect $area, RenderContext $context): void
    {
        $contentArea = $this->contentArea($area);
        if ($contentArea->width < 1 || $contentArea->height < 1) {
            return;
        }

        if (!isset($this->tabs[$this->activeTab])) {
            return;
        }

        $context->draw($this->activePane(), $frame, $contentArea);
    }

    /**
     * Require room for the strip, baseline and one content row before drawing or accepting input.
     */
    private function fitsIn(Rect $area): bool
    {
        return $this->tabs !== [] && ($this->bordered ? $area->width >= 5 && $area->height >= 5 : $area->width >= 1 && $area->height >= 1);
    }

    private function contentArea(Rect $area): Rect
    {
        return (new Inset(
            margin: Spacing::only(top: $this->bordered ? self::TAB_STRIP_HEIGHT - 1 : 1),
            border: $this->bordered,
            padding: $this->padding,
        ))->inner($area);
    }
}
