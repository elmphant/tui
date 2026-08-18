<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Tree;

interface TreeNode
{
    /**
     * Identifies this node among its siblings; a path of keys addresses it in
     * the tree.
     */
    public function key(): string;

    public function label(): string;

    /**
     * The second column — a size, a type, a value. Empty where there is none.
     */
    public function detail(): string;

    /**
     * Whether a twisty is drawn, asked before the children are known.
     */
    public function isExpandable(): bool;

    /**
     * The children, or null where they are not loaded yet.
     *
     * An empty array means expandable-but-empty. Null makes the widget draw a
     * pending row and name itself in pendingExpansions(), so a node that has to
     * go and look answers later.
     *
     * @return list<TreeNode>|null
     */
    public function children(): ?array;
}
