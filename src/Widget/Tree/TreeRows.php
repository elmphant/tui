<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Tree;

/**
 * @internal
 */
final readonly class TreeRows
{
    /**
     * @param list<array{node: TreeNode, path: TreePath, selectable: bool, guides: string, marker: string}> $rows
     * @param array<string, int>                                                                            $indexOfPath selectable rows only, by path key
     */
    public function __construct(public array $rows, public array $indexOfPath)
    {
    }
}
