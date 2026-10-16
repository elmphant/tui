<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Tree;

use ArrayObject;
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
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PublishesHints;

final readonly class TreeView implements Focusable, KeybindingsAware, MeasureHeight, PublishesHints
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;

    private const DETAIL_COLUMN = 19;
    private const MARKER_COLLAPSED = '▸ ';
    private const MARKER_EXPANDED = '▾ ';
    private const MARKER_LEAF = '▪ ';
    private const MARKER_PENDING = '⋯';

    /**
     * @var KeyedCache<ArrayObject<int, array{node: TreeNode, path: TreePath, selectable: bool, guides: string, marker: string}>>
     */
    private KeyedCache $rowsMemo;

    /**
     * @param list<TreeNode>                                                                                                             $roots
     * @param array<string, true>                                                                                                        $expanded keyed by path, so expanding a node above the cursor cannot move it
     * @param KeyedCache<ArrayObject<int, array{node: TreeNode, path: TreePath, selectable: bool, guides: string, marker: string}>>|null $rowsMemo
     *
     * @internal use TreeView::new()
     */
    public function __construct(
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

    public function withGuides(TreeGuides $guides): self
    {
        return $this->cloneWith(guides: $guides, rowsMemo: self::newRowsMemo());
    }

    /**
     * Arrows and enter, and deliberately no letters: which letter means
     * "expand" is the host's to decide, and a tree beside a text field
     * must not swallow one.
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
            $bindings->matches($message, Actions::TOGGLE) => $this->toggle($this->cursor()),
            $bindings->matches($message, Actions::HOME) => $this->first(),
            $bindings->matches($message, Actions::END) => $this->last(),
            default => $this,
        };

        return new UpdateResult($moved->scrolledToCursor($area));
    }

    /**
     * The node a pointer event lands on, or null past the last row.
     */
    public function pathAt(MouseMsg $message, Rect $area): ?TreePath
    {
        $row = $this->rowAt($message, $area);

        return $row === null ? null : $row['path'];
    }

    /**
     * Bring the cursor back into a viewport it has stepped out of.
     */
    public function scrolledToCursor(Rect $area): self
    {
        $rows = $this->visibleRows();
        $index = $this->rowIndexOf($this->cursor(), $rows);
        if ($index === null || $area->height <= 0) {
            return $this;
        }

        return $this->cloneWith(scrollOffset: ScrollWindow::showing(
            $this->scrollOffset,
            $index,
            1,
            $area->height,
            ScrollWindow::lastOffset(count($rows), $area->height),
        ));
    }

    /**
     * The expanded nodes still missing their children, for a host to go and
     * fetch. Derived on every read, so answering one cannot leave a stale entry
     * behind.
     *
     * @return list<TreePath>
     */
    public function pendingExpansions(): array
    {
        $pending = [];
        foreach ($this->visibleRows() as $row) {
            if (!$row['selectable'] && $row['marker'] === self::MARKER_PENDING) {
                $pending[] = $row['path'];
            }
        }

        return $pending;
    }

    public function toggle(TreePath $path): self
    {
        if ($this->isExpanded($path)) {
            return $this->collapse($path);
        }

        $rows = $this->visibleRows();
        $index = $this->rowIndexOf($path, $rows);

        return $index !== null && $rows[$index]['node']->isExpandable() ? $this->expand($path) : $this;
    }

    public function first(): self
    {
        foreach ($this->visibleRows() as $row) {
            if ($row['selectable']) {
                return $this->withCursor($row['path']);
            }
        }

        return $this;
    }

    public function last(): self
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
    public static function new(array $roots): self
    {
        return new self($roots);
    }

    /**
     * Replace the nodes, keeping what is open and where the cursor is — which is
     * how a host answers a pending expansion.
     *
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
     * Where the keyboard is, derived rather than remembered: a stored path that
     * a collapse has taken off the screen would otherwise point at nothing.
     */
    public function cursor(): TreePath
    {
        $rows = $this->visibleRows();
        if ($rows === []) {
            return TreePath::root();
        }
        if ($this->cursor !== null && $this->rowIndexOf($this->cursor, $rows) !== null) {
            return $this->cursor;
        }

        foreach ($rows as $row) {
            if ($row['selectable']) {
                return $row['path'];
            }
        }

        return TreePath::root();
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

    /**
     * Open what the cursor is on, or — where it was already open — step into it.
     */
    public function expandOrEnter(): self
    {
        $cursor = $this->cursor();
        $rows = $this->visibleRows();
        $index = $this->rowIndexOf($cursor, $rows);

        if ($index === null || !$rows[$index]['node']->isExpandable()) {
            return $this;
        }

        if (!$this->isExpanded($cursor)) {
            return $this->expand($cursor);
        }

        return isset($rows[$index + 1]) ? $this->withCursor($rows[$index + 1]['path']) : $this;
    }

    /**
     * Close what the cursor is on, or — where there was nothing to close —
     * climb to the parent.
     */
    public function collapseOrLeave(): self
    {
        $cursor = $this->cursor();
        if ($this->isExpanded($cursor)) {
            return $this->collapse($cursor);
        }

        return $cursor->parent()->isRoot() ? $this : $this->withCursor($cursor->parent());
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    /**
     * Every row the open nodes make visible, so a container can size itself to
     * the tree instead of guessing.
     */
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
        $cursor = $this->cursor();
        $row = 0;

        $rows = $this->visibleRows();

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

            $detailX = $area->x + self::DETAIL_COLUMN;
            $showsDetail = $x <= $detailX && $detailX < $area->right();
            $labelWidth = max(0, ($showsDetail ? $detailX : $area->right()) - $x);
            $frame->putStyled($x, $y, CharWidth::truncateToWidth($visible['node']->label(), $labelWidth), $rowStyle);

            if ($showsDetail) {
                $frame->putStyled(
                    $detailX,
                    $y,
                    CharWidth::truncateToWidth($visible['node']->detail(), max(0, $area->right() - $detailX)),
                    $onCursor ? $rowStyle : $theme->dim,
                );
            }
            ++$row;
        }
    }

    /**
     * The row a pointer event lands on, or null past the last row.
     *
     * @return array{node: TreeNode, path: TreePath, selectable: bool, guides: string, marker: string}|null
     */
    private function rowAt(MouseMsg $message, Rect $area): ?array
    {
        if (!$message->inRect($area)) {
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
     * Where the pointer landed decides what the press means: the name is a
     * handle to open with, the value beside it only somewhere to point at.
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

        $drawsDetail = CharWidth::stringWidth($row['guides']) + CharWidth::stringWidth($row['marker']) <= self::DETAIL_COLUMN;
        $onDetail = $drawsDetail && $message->x - $area->x >= self::DETAIL_COLUMN;

        return $onDetail ? $selected : $selected->toggle($row['path']);
    }

    private function scrolledBy(int $notches, Rect $area): self
    {
        $maxOffset = ScrollWindow::lastOffset(count($this->visibleRows()), $area->height);

        return $this->cloneWith(scrollOffset: ScrollWindow::clamped($this->scrollOffset + $notches, $maxOffset));
    }

    private function cursorMovedBy(int $delta): self
    {
        $rows = $this->visibleRows();
        $index = $this->rowIndexOf($this->cursor(), $rows);
        if ($index === null) {
            return $this;
        }
        $step = $delta <=> 0;
        for ($target = $index + $step; isset($rows[$target]); $target += $step) {
            if ($rows[$target]['selectable']) {
                return $this->withCursor($rows[$target]['path']);
            }
        }

        return $this;
    }

    /**
     * @param list<array{node: TreeNode, path: TreePath, selectable: bool, guides: string, marker: string}> $rows
     */
    private function rowIndexOf(TreePath $path, array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            if ($row['selectable'] && self::keyOf($row['path']) === self::keyOf($path)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Every row the expanded set makes visible, in drawing order.
     *
     * @return list<array{node: TreeNode, path: TreePath, selectable: bool, guides: string, marker: string}>
     */
    private function visibleRows(): array
    {
        // Held across the clones a cursor move makes: the rows depend on the roots,
        // what is open and the guides only, and walking a big tree costs a frame.
        /** @var list<array{node: TreeNode, path: TreePath, selectable: bool, guides: string, marker: string}> */
        return $this->rowsMemo->at('rows', fn (): ArrayObject => new ArrayObject($this->rowsOf($this->roots, TreePath::root(), '')))->getArrayCopy();
    }

    /**
     * @return KeyedCache<ArrayObject<int, array{node: TreeNode, path: TreePath, selectable: bool, guides: string, marker: string}>>
     */
    private static function newRowsMemo(): KeyedCache
    {
        /** @var KeyedCache<ArrayObject<int, array{node: TreeNode, path: TreePath, selectable: bool, guides: string, marker: string}>> $cache */
        $cache = new KeyedCache();

        return $cache;
    }

    /**
     * @param list<TreeNode> $nodes
     *
     * @return list<array{node: TreeNode, path: TreePath, selectable: bool, guides: string, marker: string}>
     */
    private function rowsOf(array $nodes, TreePath $parent, string $inheritedGuides): array
    {
        $rows = [];
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

            $rows = [...$rows, ...$this->rowsOf(
                $children,
                $path,
                $parent->isRoot() ? '' : $inheritedGuides . $this->guides->trunk($isLast),
            )];
        }

        return $rows;
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
