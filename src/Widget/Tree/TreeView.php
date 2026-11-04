<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Tree;

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
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Support\KeyedCache;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Text\TextWrap;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PublishesHints;

final readonly class TreeView implements Focusable, KeybindingsAware, MeasureHeight, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    private const MIN_DETAIL_COLUMN = 19;
    private const MARKER_COLLAPSED = '▸ ';
    private const MARKER_EXPANDED = '▾ ';
    private const MARKER_LEAF = '▪ ';
    private const MARKER_PENDING = '⋯';

    /**
     * @var KeyedCache<TreeRows>
     */
    private KeyedCache $rowsMemo;

    /**
     * @param list<TreeNode>            $roots
     * @param array<string, true>       $expanded keyed by path, so expanding a node above the cursor cannot move it
     * @param KeyedCache<TreeRows>|null $rowsMemo
     */
    private function __construct(
        private array $roots = [],
        private array $expanded = [],
        private ?TreePath $cursor = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private int $scrollOffset = 0,
        private TreeGuides $guides = TreeGuides::Square,
        ?KeyedCache $rowsMemo = null,
    ) {
        $this->rowsMemo = $rowsMemo ?? self::newRowsMemo();
    }

    /**
     * @param list<TreeNode> $roots
     */
    public static function new(array $roots): self
    {
        return new self($roots);
    }

    public function withGuides(TreeGuides $guides): self
    {
        return $this->cloneWith(guides: $guides, rowsMemo: self::newRowsMemo());
    }

    /**
     * Leave letter bindings to the host so a tree beside a text field cannot swallow typing.
     */
    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([
            Actions::CURSOR_DOWN => ['down'],
            Actions::CURSOR_UP => ['up'],
            Actions::CURSOR_RIGHT => ['right'],
            Actions::CURSOR_LEFT => ['left'],
            Actions::TOGGLE => ['enter', 'space'],
            Actions::HOME => ['home'],
            Actions::END => ['end'],
        ]);
    }

    public function hints(): array
    {
        return $this->effectiveKeybindings()->hints([
            'move' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN],
            'open or close' => Actions::TOGGLE,
        ]);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg) {
            return new UpdateResult($this->afterPointer($message, $area));
        }
        if (!$message instanceof KeyMsg) {
            return new UpdateResult($this);
        }

        $bindings = $this->effectiveKeybindings();

        $moved = match (true) {
            $bindings->matches($message, Actions::CURSOR_DOWN) => $this->cursorDown(),
            $bindings->matches($message, Actions::CURSOR_UP) => $this->cursorUp(),
            $bindings->matches($message, Actions::CURSOR_RIGHT) => $this->expandOrEnter(),
            $bindings->matches($message, Actions::CURSOR_LEFT) => $this->collapseOrLeave(),
            $bindings->matches($message, Actions::TOGGLE) => $this->toggle($this->shownCursor()),
            $bindings->matches($message, Actions::HOME) => $this->cursorToFirst(),
            $bindings->matches($message, Actions::END) => $this->cursorToLast(),
            default => $this,
        };

        return new UpdateResult($moved->scrolledToCursor($area));
    }

    public function scrolledToCursor(Rect $area): self
    {
        $index = $this->rowIndexOf($this->shownCursor());
        if ($index === null || $area->height <= 0) {
            return $this;
        }

        return $this->cloneWith(scrollOffset: ScrollWindow::showing(
            $this->scrollOffset,
            $index,
            1,
            $area->height,
            ScrollWindow::lastOffset(count($this->visibleRows()), $area->height),
        ));
    }

    /**
     * Include all unloaded expansions, even those already being fetched; pendingSince() returns only new ones.
     *
     * @return list<TreePath>
     */
    public function pendingExpansions(): array
    {
        $pending = [];
        foreach ($this->visibleRows() as $row) {
            if (!$row['selectable']) {
                $pending[] = $row['path'];
            }
        }

        return $pending;
    }

    /**
     * Return newly pending expansions so the host does not request the same load twice.
     *
     * @return list<TreePath>
     */
    public function pendingSince(self $before): array
    {
        $earlier = [];
        foreach ($before->pendingExpansions() as $path) {
            $earlier[self::keyOf($path)] = true;
        }

        return array_values(array_filter(
            $this->pendingExpansions(),
            static fn (TreePath $path): bool => !isset($earlier[self::keyOf($path)]),
        ));
    }

    public function toggle(TreePath $path): self
    {
        if ($this->isExpanded($path)) {
            return $this->collapse($path);
        }

        $rows = $this->visibleRows();
        $index = $this->rowIndexOf($path);

        return $index !== null && $rows[$index]['node']->isExpandable() ? $this->expand($path) : $this;
    }

    public function cursorToFirst(): self
    {
        foreach ($this->visibleRows() as $row) {
            if ($row['selectable']) {
                return $this->withCursor($row['path']);
            }
        }

        return $this;
    }

    public function cursorToLast(): self
    {
        foreach (array_reverse($this->visibleRows()) as $row) {
            if ($row['selectable']) {
                return $this->withCursor($row['path']);
            }
        }

        return $this;
    }

    /**
     * @param list<TreeNode> $roots
     */
    public function withRoots(array $roots): self
    {
        return $this->cloneWith(roots: $roots, rowsMemo: self::newRowsMemo());
    }

    public function expand(TreePath $path): self
    {
        return $this->cloneWith(expanded: $this->expanded + [self::keyOf($path) => true], rowsMemo: self::newRowsMemo());
    }

    public function collapse(TreePath $path): self
    {
        $expanded = $this->expanded;
        unset($expanded[self::keyOf($path)]);

        return $this->cloneWith(expanded: $expanded, rowsMemo: self::newRowsMemo());
    }

    public function isExpanded(TreePath $path): bool
    {
        return isset($this->expanded[self::keyOf($path)]);
    }

    /**
     * A collapsed node moves the cursor to its ancestor; a loading branch preserves it to avoid jumps during reload.
     */
    public function cursor(): TreePath
    {
        $shown = $this->shownCursor();

        return $this->cursor !== null && $this->awaitsChildren($shown) ? $this->cursor : $shown;
    }

    public function withCursor(TreePath $path): self
    {
        return $this->cloneWith(cursor: $path);
    }

    public function cursorDown(): self
    {
        return $this->cursorMovedBy(1);
    }

    public function cursorUp(): self
    {
        return $this->cursorMovedBy(-1);
    }

    public function expandOrEnter(): self
    {
        $cursor = $this->shownCursor();
        $rows = $this->visibleRows();
        $index = $this->rowIndexOf($cursor);

        if ($index === null || !$rows[$index]['node']->isExpandable()) {
            return $this;
        }

        if (!$this->isExpanded($cursor)) {
            return $this->expand($cursor);
        }

        return ($rows[$index]['node']->children() ?? []) !== [] ? $this->withCursor($rows[$index + 1]['path']) : $this;
    }

    public function collapseOrLeave(): self
    {
        $cursor = $this->shownCursor();
        if ($this->isExpanded($cursor)) {
            return $this->collapse($cursor);
        }

        return $cursor->parent()->isRoot() ? $this : $this->withCursor($cursor->parent());
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function measureHeight(int $width): int
    {
        return count($this->visibleRows());
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width <= 0 || $area->height <= 0) {
            return;
        }

        $theme = $context->theme;
        $cursor = $this->shownCursor();
        $row = 0;

        $rows = $this->visibleRows();
        $detailColumn = $this->detailColumn($area->width);

        foreach (array_slice($rows, $this->offsetIn($rows, $area), $area->height) as $visible) {
            if ($row >= $area->height) {
                return;
            }

            $y = $area->y + $row;
            $onCursor = $visible['selectable'] && self::keyOf($visible['path']) === self::keyOf($cursor);
            $rowStyle = $onCursor
                ? ($this->drawsFocused($context) ? $theme->cursorStyle : $theme->selectedStyle)
                : $theme->base;

            if ($onCursor) {
                $frame->putStyled($area->x, $y, str_repeat(' ', $area->width), $rowStyle);
            }

            $x = $area->x;
            $x += $frame->putStyled($x, $y, CharWidth::truncateToWidth($visible['guides'], $area->right() - $x), $onCursor ? $rowStyle : $theme->dim);
            $x += $frame->putStyled($x, $y, CharWidth::truncateToWidth($visible['marker'], max(0, $area->right() - $x)), $onCursor ? $rowStyle : $theme->dim);

            $detailX = $area->x + $detailColumn;
            $showsDetail = $visible['node']->detail() !== '' && $x < $detailX && $detailX < $area->right();
            $labelWidth = max(0, ($showsDetail ? $detailX - 1 : $area->right()) - $x);
            $frame->putStyled($x, $y, TextWrap::ellipsize(self::oneRow($visible['node']->label(), $x - $area->x), $labelWidth), $rowStyle);

            if ($showsDetail) {
                $frame->putStyled(
                    $detailX,
                    $y,
                    CharWidth::truncateToWidth(self::oneRow($visible['node']->detail(), $detailColumn), max(0, $area->right() - $detailX)),
                    $onCursor ? $rowStyle : $theme->dim,
                );
            }
            ++$row;
        }
    }

    /**
     * @return array{node: TreeNode, path: TreePath, selectable: bool, guides: string, marker: string}|null
     */
    private function rowAt(MouseMsg $message, Rect $area): ?array
    {
        if (!$message->isIn($area)) {
            return null;
        }

        $rows = $this->visibleRows();
        $row = $rows[$this->offsetIn($rows, $area) + $message->y - $area->y] ?? null;

        return $row === null || !$row['selectable'] ? null : $row;
    }

    /**
     * @param list<array{node: TreeNode, path: TreePath, selectable: bool, guides: string, marker: string}> $rows
     */
    private function offsetIn(array $rows, Rect $area): int
    {
        return ScrollWindow::clamped($this->scrollOffset, ScrollWindow::lastOffset(count($rows), $area->height));
    }

    /**
     * Clicking the label toggles expansion; clicking the value only moves the cursor.
     */
    private function afterPointer(MouseMsg $message, Rect $area): self
    {
        $scrolled = match ($message->action) {
            MouseAction::WheelDown => $this->scrolledBy($message->repeat, $area),
            MouseAction::WheelUp => $this->scrolledBy(-$message->repeat, $area),
            default => null,
        };
        if ($scrolled !== null) {
            return $scrolled;
        }

        if ($message->action !== MouseAction::Press) {
            return $this;
        }

        $row = $this->rowAt($message, $area);
        if ($row === null) {
            return $this;
        }

        $selected = $this->withCursor($row['path']);

        $detailColumn = $this->detailColumn($area->width);
        $drawsDetail = $row['node']->detail() !== ''
            && CharWidth::stringWidth($row['guides'] . $row['marker']) < $detailColumn;
        $onDetail = $drawsDetail && $message->x - $area->x >= $detailColumn;

        return $onDetail ? $selected : $selected->toggle($row['path']);
    }

    private function detailColumn(int $width): int
    {
        $rows = $this->treeRows();

        return max(self::MIN_DETAIL_COLUMN, min($rows->labelEnd + 1, $width - $rows->detailWidth));
    }

    private function scrolledBy(int $notches, Rect $area): self
    {
        $maxOffset = ScrollWindow::lastOffset(count($this->visibleRows()), $area->height);

        return $this->cloneWith(scrollOffset: ScrollWindow::clamped($this->scrollOffset + $notches, $maxOffset));
    }

    private function cursorMovedBy(int $step): self
    {
        $rows = $this->visibleRows();
        $index = $this->rowIndexOf($this->shownCursor());
        if ($index === null) {
            return $this;
        }
        for ($target = $index + $step; isset($rows[$target]); $target += $step) {
            if ($rows[$target]['selectable']) {
                return $this->withCursor($rows[$target]['path']);
            }
        }

        return $this;
    }

    private function shownCursor(): TreePath
    {
        for ($path = $this->cursor; $path !== null && !$path->isRoot(); $path = $path->parent()) {
            if ($this->rowIndexOf($path) !== null) {
                return $path;
            }
        }

        foreach ($this->visibleRows() as $row) {
            if ($row['selectable']) {
                return $row['path'];
            }
        }

        return TreePath::root();
    }

    private function awaitsChildren(TreePath $path): bool
    {
        $index = $this->rowIndexOf($path);

        return $index !== null && $this->isExpanded($path) && $this->visibleRows()[$index]['node']->children() === null;
    }

    private function rowIndexOf(TreePath $path): ?int
    {
        return $this->treeRows()->indexOfPath[self::keyOf($path)] ?? null;
    }

    /**
     * @return list<array{node: TreeNode, path: TreePath, selectable: bool, guides: string, marker: string}>
     */
    private function visibleRows(): array
    {
        return $this->treeRows()->rows;
    }

    private function treeRows(): TreeRows
    {
        // Reuse visible rows across cursor moves; they depend only on roots, expanded nodes and guides.
        return $this->rowsMemo->at('rows', function (): TreeRows {
            $rows = [];
            $this->appendRowsOf($this->roots, TreePath::root(), '', $rows);
            $indexOfPath = [];
            $labelEnd = 0;
            $detailWidth = 0;
            foreach ($rows as $index => $row) {
                if (!$row['selectable']) {
                    continue;
                }
                $indexOfPath[self::keyOf($row['path'])] = $index;
                $detail = $row['node']->detail();
                if ($detail !== '') {
                    $labelStart = CharWidth::stringWidth($row['guides'] . $row['marker']);
                    $labelEnd = max($labelEnd, $labelStart + CharWidth::stringWidth(self::oneRow($row['node']->label(), $labelStart)));
                    $detailWidth = max($detailWidth, CharWidth::stringWidth(self::oneRow($detail, 0)));
                }
            }

            return new TreeRows($rows, $indexOfPath, $labelEnd, $detailWidth);
        });
    }

    /**
     * @return KeyedCache<TreeRows>
     */
    private static function newRowsMemo(): KeyedCache
    {
        /** @var KeyedCache<TreeRows> $cache */
        $cache = new KeyedCache();

        return $cache;
    }

    /**
     * @param list<TreeNode>                                                                                $nodes
     * @param list<array{node: TreeNode, path: TreePath, selectable: bool, guides: string, marker: string}> $rows
     */
    private function appendRowsOf(array $nodes, TreePath $parent, string $inheritedGuides, array &$rows): void
    {
        $lastIndex = count($nodes) - 1;

        foreach ($nodes as $index => $node) {
            $path = $parent->child($node->key());
            $isLast = $index === $lastIndex;
            $expanded = $this->isExpanded($path);

            $rows[] = [
                'node' => $node,
                'path' => $path,
                'selectable' => true,
                'guides' => $parent->isRoot() ? '' : $inheritedGuides . $this->guides->branch($isLast),
                'marker' => match (true) {
                    !$node->isExpandable() => self::MARKER_LEAF,
                    $expanded => self::MARKER_EXPANDED,
                    default => self::MARKER_COLLAPSED,
                },
            ];

            if (!$expanded) {
                continue;
            }

            $children = $node->children();
            if ($children === null) {
                $rows[] = [
                    'node' => new SimpleTreeNode($node->key(), '', ''),
                    'path' => $path,
                    'selectable' => false,
                    'guides' => $inheritedGuides
                        . ($parent->isRoot() ? '' : $this->guides->trunk($isLast))
                        . $this->guides->branch(true),
                    'marker' => self::MARKER_PENDING,
                ];
                continue;
            }
            if ($children === []) {
                continue;
            }

            $this->appendRowsOf(
                $children,
                $path,
                $parent->isRoot() ? '' : $inheritedGuides . $this->guides->trunk($isLast),
                $rows,
            );
        }
    }

    /**
     * Expand tabs as Paragraph does; show line breaks as ↵ because a tree row cannot contain them.
     */
    private static function oneRow(string $text, int $column): string
    {
        return CharWidth::expandTabs(str_replace(["\r\n", "\n", "\r"], '↵', $text), $column);
    }

    /**
     * Length-prefixed rather than separated: a key may hold any byte.
     */
    private static function keyOf(TreePath $path): string
    {
        $key = '';
        foreach ($path->keys as $segment) {
            $key .= strlen($segment) . ':' . $segment;
        }

        return $key;
    }
}
