<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget\Tree;

use Elmphant\Tui\Widget\Tree\TreePath;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TreePathTest extends TestCase
{
    #[Test]
    public function aChildPathLeadsBackToItsParent(): void
    {
        $child = TreePath::root()->child('order')->child('lines');

        self::assertSame(['order', 'lines'], $child->keys);
        self::assertSame(['order'], $child->parent()->keys);
        self::assertTrue($child->parent()->parent()->isRoot());
    }
}
