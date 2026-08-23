<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\Dispatch\ScrollPane;
use Elmphant\Tui\Widget\Structure\TabPage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TabPageTest extends TestCase
{
    #[Test]
    public function defaultScrollOffsetIsZero(): void
    {
        $page = new TabPage(label: 'Test');

        self::assertSame(0, $page->pane->scrollOffset());
    }

    #[Test]
    public function defaultChildrenAreEmpty(): void
    {
        $page = new TabPage(label: 'Test');

        self::assertSame([], [...$page->pane->children()]);
    }

    #[Test]
    public function defaultPaneIsEmpty(): void
    {
        $page = new TabPage(label: 'Test');

        self::assertInstanceOf(ScrollPane::class, $page->pane);
    }

    #[Test]
    public function labelIsStored(): void
    {
        $page = new TabPage(label: 'Settings');

        self::assertSame('Settings', $page->label);
    }
}
