<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget\Tree;

use Elmphant\Tui\Widget\Tree\SimpleTreeNode;
use Elmphant\Tui\Widget\Tree\TreeNode;
use Elmphant\Tui\Widget\Tree\TreePath;
use Elmphant\Tui\Widget\Tree\TreeView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TreeViewRowsTest extends TestCase
{
    #[Test]
    public function movingTheCursorDoesNotWalkTheTreeAgain(): void
    {
        $parent = new CountingTreeNode([new SimpleTreeNode('a', 'a'), new SimpleTreeNode('b', 'b')]);
        $tree = TreeView::new([$parent])->expand(TreePath::root()->child('parent'));
        $tree->cursor();
        $walks = $parent->childrenAsked;

        $tree->withCursor(TreePath::root()->child('parent')->child('b'))->cursor();

        self::assertSame($walks, $parent->childrenAsked);
    }
}

final class CountingTreeNode implements TreeNode
{
    public int $childrenAsked = 0;

    /**
     * @param list<TreeNode> $children
     */
    public function __construct(private readonly array $children)
    {
    }

    public function key(): string
    {
        return 'parent';
    }

    public function label(): string
    {
        return 'parent';
    }

    public function detail(): string
    {
        return '';
    }

    public function isExpandable(): bool
    {
        return true;
    }

    public function children(): array
    {
        ++$this->childrenAsked;

        return $this->children;
    }
}
