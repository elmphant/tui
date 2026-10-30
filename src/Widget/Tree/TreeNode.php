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

    public function detail(): string;

    public function isExpandable(): bool;

    /**
     * Null means children are not loaded: show a pending row and include the node in pendingExpansions().
     * An empty list means loaded but empty.
     *
     * @return list<TreeNode>|null
     */
    public function children(): ?array;
}
