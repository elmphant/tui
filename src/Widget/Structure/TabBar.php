<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Closure;
use Elmphant\Tui\Core\Cloneable;
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
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\BorderGlyphs;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Choice\Options;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Dispatch\ScrollPane;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use LogicException;

final readonly class TabBar implements Focusable, WidgetContainer, KeybindingsAware, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    private const TAB_STRIP_HEIGHT = 3;

    /**
     * @param list<TabPage> $tabs
     */
    public function __construct(
        private array $tabs = [],
        private int $selectedTab = 0,
        private ?string $hint = null,
        private ?string $shortHint = null,
        private bool $focused = false,
        private ?Spacing $padding = null,
        private bool $emphasizedBorder = false,
        private ?Style $borderStyleOverride = null,
        private ?Style $focusedBorderStyleOverride = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    public static function defaultKeybindings(): Keybindings
    {
        return (new Keybindings([
            Actions::TAB_NEXT => ['right'],
            Actions::TAB_PREVIOUS => ['left'],
        ]))->merge(ScrollPane::defaultKeybindings());
    }

    public static function new(): self
    {
        return new self();
    }

    public function addTab(string $label): self
    {
        return $this->cloneWith(tabs: [...$this->tabs, new TabPage($label)]);
    }

    public function add(Widget $child): self
    {
        if ($this->tabs === []) {
            return $this;
        }

        $tabs = $this->tabs;
        $lastIndex = count($tabs) - 1;
        $tabs[$lastIndex] = $tabs[$lastIndex]->add($child);

        return $this->cloneWith(tabs: $tabs);
    }

    public function selectedTab(): int
    {
        return $this->selectedTab;
    }

    /**
     * @param list<TabPage> $tabs
     */
    public function withTabs(array $tabs): self
    {
        if ($tabs === $this->tabs) {
            return $this;
        }

        return $this->cloneWith(tabs: $tabs, selectedTab: Options::cursorAfter(
            ($this->tabs[$this->selectedTab] ?? null)?->label,
            array_map(static fn (TabPage $tab): string => $tab->label, $tabs),
        ));
    }

    /**
     * The chosen page takes the focus the old one had.
     */
    public function withSelectedTab(int $selectedTab): self
    {
        return $this->cloneWith(selectedTab: $selectedTab)->withFocus($this->focused);
    }

    /**
     * The hint drawn at the right of the strip, when it fits.
     *
     * It replaces the switch hint the bar derives from its own keybindings,
     * for applications that drive tab switching from somewhere else. Pass a
     * short form to keep something readable on a narrow terminal.
     */
    public function withHint(?string $hint, ?string $shortHint = null): self
    {
        return $this->cloneWith(hint: $hint, shortHint: $shortHint);
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
     * Draw the border the way a focused bar draws it, without taking focus.
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

    /**
     * The keys this bar answers, for a footer to carry when the strip cannot.
     *
     * @return array<string, string> key label => what it does
     */
    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            'previous tab' => Actions::TAB_PREVIOUS,
            'next tab' => Actions::TAB_NEXT,
        ]);
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return $this->cloneWith(focused: $focused, tabs: $this->tabsWithFocus(
            static fn (ScrollPane $pane): ScrollPane => $focused ? $pane->withFocusAt(0) : $pane->withFocus(false),
        ));
    }

    public function withFocusReversed(): static
    {
        return $this->cloneWith(focused: true, tabs: $this->tabsWithFocus(
            static fn (ScrollPane $pane): ScrollPane => $pane->withFocusReversed(),
        ));
    }

    /**
     * @return list<Widget>
     */
    public function children(): array
    {
        return [...$this->activePane()->children()];
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function childWidgets(Rect $area): array
    {
        return [...$this->activePane()->childWidgets($this->contentArea($area))];
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$this->fitsIn($area)) {
            return new UpdateResult($this);
        }

        if ($message instanceof MouseMsg && $message->isClick() && $message->y >= $area->y && $message->y <= $area->y + 1) {
            return $this->handleTabClick($message, $area);
        }

        if ($message instanceof KeyMsg && !$this->focused) {
            return new UpdateResult($this);
        }

        $pane = $this->activePane();
        $result = (new ChildDispatcher())->dispatchOne($pane, $message, $this->contentArea($area));

        if ($result->changed) {
            return new UpdateResult($this->withActivePane(self::requirePane($result->child)), $result->command);
        }

        return $message instanceof KeyMsg
            ? $this->switchedTab($message) ?? new UpdateResult($this, $result->command)
            : new UpdateResult($this, $result->command);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if (!$this->fitsIn($area)) {
            return;
        }

        $theme = $context->theme;
        $prominent = $this->drawsFocused($context) || $this->emphasizedBorder;
        $glyphs = $theme->borderFor($prominent)->glyphs();
        $override = $prominent ? $this->focusedBorderStyleOverride : $this->borderStyleOverride;
        $borderStyle = $override ?? $theme->borderStyleFor($prominent);

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
     * Focus belongs to the active page; every other page is blurred, so
     * switching to one never arrives with two children lit.
     *
     * @return list<TabPage>
     */
    private function tabsWithFocus(Closure $active): array
    {
        $tabs = [];

        foreach ($this->tabs as $index => $tab) {
            $tabs[] = $tab->withPane(
                $index === $this->selectedTab ? $active($tab->pane) : $tab->pane->withFocus(false),
            );
        }

        return $tabs;
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
        return $this->tabs[$this->selectedTab]->pane ?? new ScrollPane();
    }

    private function withActivePane(ScrollPane $pane): self
    {
        $tabs = $this->tabs;
        $tabs[$this->selectedTab] = $tabs[$this->selectedTab]->withPane($pane);

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

        $newIndex = ($this->selectedTab + $direction + $count) % $count;

        return new UpdateResult($this->cloneWith(selectedTab: $newIndex)->withFocus(true));
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleTabClick(MouseMsg $message, Rect $area): UpdateResult
    {
        $index = $this->tabStripLayout($area)->indexAt($message->x);

        if ($index === null || $index === $this->selectedTab) {
            return new UpdateResult($this);
        }

        return new UpdateResult($this->cloneWith(selectedTab: $index)->withFocus($this->focused));
    }

    private function tabStripLayout(Rect $area): TabStripLayout
    {
        return TabStripLayout::across(
            array_map(static fn (TabPage $tab): string => $tab->label, $this->tabs),
            $area,
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

        foreach ($this->tabs as $tabIndex => $tab) {
            $cell = $layout->rectOf($tabIndex);
            if ($cell === null) {
                break;
            }

            $labelRow = $cell->y + 1;

            if ($tabIndex === $this->selectedTab) {
                $tabRight = $cell->right() - 1;

                $frame->putChar($cell->x, $cell->y, $border->topLeft, $borderForeground, $borderBackground, $borderAttributes);
                $frame->fillRect($cell->x + 1, $cell->y, $tabRight - $cell->x - 1, 1, $borderForeground, $borderBackground, $borderAttributes, $border->horizontal);
                $frame->putChar($tabRight, $cell->y, $border->topRight, $borderForeground, $borderBackground, $borderAttributes);

                $frame->putChar($cell->x, $labelRow, $border->vertical, $borderForeground, $borderBackground, $borderAttributes);
                $frame->putChar($cell->x + 1, $labelRow, ' ', $borderForeground, $borderBackground);
                $frame->putStyled($cell->x + 2, $labelRow, $tab->label, $theme->titleStyle);
                $frame->putChar($tabRight - 1, $labelRow, ' ', $borderForeground, $borderBackground);
                $frame->putChar($tabRight, $labelRow, $border->vertical, $borderForeground, $borderBackground, $borderAttributes);
            } else {
                $frame->putStyled($cell->x + 2, $labelRow, $tab->label, $theme->dim);
            }
        }

        $this->renderTabHint($frame, $area, $layout->columnAfterLastTab(), $theme, $focused);
        $this->renderBaseline($frame, $area, $layout, $border, $borderStyle);
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

        $isFirstActive = $this->selectedTab === 0;
        $baselineLeft = $isFirstActive ? $border->vertical : $border->topLeft;
        $frame->putChar($area->x, $baselineY, $baselineLeft, $foreground, $background, $attributes);
        $frame->fillRect($area->x + 1, $baselineY, $area->width - 2, 1, $foreground, $background, $attributes, $border->horizontal);
        $frame->putChar($area->right() - 1, $baselineY, $border->topRight, $foreground, $background, $attributes);

        $cell = $layout->rectOf($this->selectedTab);
        if ($cell === null) {
            return;
        }

        $frame->putChar(
            $cell->x,
            $baselineY,
            $this->selectedTab === 0 ? $border->vertical : $border->bottomRight,
            $foreground,
            $background,
            $attributes,
        );

        $tabRight = $cell->right() - 1;
        $frame->putChar($tabRight, $baselineY, $border->bottomLeft, $foreground, $background, $attributes);

        $frame->fillRect($cell->x + 1, $baselineY, $tabRight - $cell->x - 1, 1, 0, $background);
    }

    private function renderTabHint(Frame $frame, Rect $area, int $afterTabsX, Theme $theme, bool $focused): void
    {
        $rightEdge = $area->right() - 1;
        $text = $this->hintWithin($rightEdge - $afterTabsX, $focused);

        if ($text === null) {
            return;
        }

        $frame->putStyled($rightEdge - CharWidth::stringWidth($text) - 1, $area->y + 1, $text, $theme->dim);
    }

    private function hintWithin(int $availableWidth, bool $focused): ?string
    {
        if ($this->hint !== null) {
            return match (true) {
                self::fits($this->hint, $availableWidth) => $this->hint,
                $this->shortHint !== null && self::fits($this->shortHint, $availableWidth) => $this->shortHint,
                default => null,
            };
        }

        // An unfocused bar ignores keys, so advertising them would be a lie.
        // Its hints() are still there for a footer to carry.
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
        $previous = $bindings->get(Actions::TAB_PREVIOUS)[0] ?? null;
        $next = $bindings->get(Actions::TAB_NEXT)[0] ?? null;

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

        if (!isset($this->tabs[$this->selectedTab])) {
            return;
        }

        $this->activePane()->render($frame, $contentArea, $context);
    }

    /**
     * A tab bar needs room for its strip, its baseline and a row of content
     * under them; below that it draws nothing, so it takes no input either.
     */
    private function fitsIn(Rect $area): bool
    {
        return $this->tabs !== [] && $area->width >= 5 && $area->height >= 5;
    }

    private function contentArea(Rect $area): Rect
    {
        return (new Inset(
            margin: Spacing::only(top: self::TAB_STRIP_HEIGHT - 1),
            border: true,
            padding: $this->padding,
        ))->inner($area);
    }
}
