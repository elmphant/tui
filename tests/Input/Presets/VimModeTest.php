<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Input\Presets;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Application\ChordListApp;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Table;
use Elmphant\Tui\Widget\Tree\SimpleTreeNode;
use Elmphant\Tui\Widget\Tree\TreePath;
use Elmphant\Tui\Widget\Tree\TreeView;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VimModeTest extends TestCase
{
    #[Test]
    public function bindsJAndKForListNav(): void
    {
        $bindings = VimMode::keybindings();

        self::assertTrue($bindings->matches(new KeyMsg('j'), Actions::CURSOR_DOWN));
        self::assertTrue($bindings->matches(new KeyMsg('k'), Actions::CURSOR_UP));
    }

    #[Test]
    public function bindsHAndLForTheHorizontalMove(): void
    {
        $bindings = VimMode::keybindings();

        self::assertTrue($bindings->matches(new KeyMsg('h'), Actions::CURSOR_LEFT));
        self::assertTrue($bindings->matches(new KeyMsg('l'), Actions::CURSOR_RIGHT));
    }

    #[Test]
    public function bindsShiftedHAndLForTabNav(): void
    {
        $bindings = VimMode::keybindings();

        self::assertTrue($bindings->matches(new KeyMsg('H'), Actions::TAB_PREVIOUS));
        self::assertTrue($bindings->matches(new KeyMsg('L'), Actions::TAB_NEXT));
        self::assertFalse($bindings->matches(new KeyMsg('l'), Actions::TAB_NEXT));
    }

    #[Test]
    public function bindsCtrlUAndCtrlDForPageNav(): void
    {
        $bindings = VimMode::keybindings();

        self::assertTrue($bindings->matches(new KeyMsg('u', ctrl: true), Actions::PAGE_UP));
        self::assertTrue($bindings->matches(new KeyMsg('d', ctrl: true), Actions::PAGE_DOWN));
    }

    #[Test]
    public function bindsJAndKForScroll(): void
    {
        $bindings = VimMode::keybindings();

        self::assertTrue($bindings->matches(new KeyMsg('j'), Actions::SCROLL_DOWN));
        self::assertTrue($bindings->matches(new KeyMsg('k'), Actions::SCROLL_UP));
    }

    #[Test]
    public function ggAndCapitalGJumpAListToItsEnds(): void
    {
        $harness = ModelHarness::for(new ChordListApp(globals: VimMode::keybindings()), 30, 8)->initialize()->press('G');

        self::assertSame(3, $harness->model()->list->cursor());

        $harness->press('g', 'g');

        self::assertSame(0, $harness->model()->list->cursor());
    }

    #[Test]
    public function ggAndCapitalGJumpATableToItsEnds(): void
    {
        $harness = ModelHarness::for(new VimTableApp(), 40, 10)->initialize()->press('G');

        self::assertSame(5, $harness->model()->table->cursor());

        $harness->press('g', 'g');

        self::assertSame(0, $harness->model()->table->cursor());
    }

    #[Test]
    public function gtAndGCapitalTSwitchTabsFromInsideATree(): void
    {
        $harness = ModelHarness::for(new VimTabbedTreeApp(), 40, 12)->initialize()->press('g', 't');

        self::assertSame(1, $harness->model()->bar->selectedTab());

        $harness->press('g', 'T');

        self::assertSame(0, $harness->model()->bar->selectedTab());
    }

    #[Test]
    public function theKeysOfAChordAreTypedIntoAFieldOnATab(): void
    {
        $harness = ModelHarness::for(new VimTabbedTreeApp(), 40, 12)->initialize()->press('L', 'L')->type('ggtG');

        self::assertSame(2, $harness->model()->bar->selectedTab());
        $field = $harness->model()->bar->children()[0];
        self::assertInstanceOf(TextInput::class, $field);
        self::assertSame('ggtG', $field->value());
    }

    #[Test]
    public function aTabBarStillLeadsItsHintsWithCapitalHAndL(): void
    {
        self::assertSame(
            ['H' => 'previous tab', 'L' => 'next tab'],
            TabBar::new()->withGlobalKeybindings(VimMode::keybindings())->hints(),
        );
    }

    #[Test]
    public function isCachedSingleton(): void
    {
        self::assertSame(VimMode::keybindings(), VimMode::keybindings());
    }

    #[Test]
    public function lExpandsTheFocusedTreeAndKeepsTheTab(): void
    {
        $harness = ModelHarness::for(new VimTabbedTreeApp(), 40, 12)->initialize()->press('l');

        self::assertSame(0, $harness->model()->bar->selectedTab());
        self::assertTrue($harness->model()->tree()->isExpanded(new TreePath('dir')));
    }

    #[Test]
    public function hLeavesTheChildForItsParentInTheFocusedTree(): void
    {
        $harness = ModelHarness::for(new VimTabbedTreeApp(), 40, 12)->initialize()->press('l', 'j', 'h');

        self::assertSame(0, $harness->model()->bar->selectedTab());
        self::assertEquals(new TreePath('dir'), $harness->model()->tree()->cursor());
    }

    #[Test]
    public function shiftedLAndHSwitchTabsFromInsideATree(): void
    {
        $harness = ModelHarness::for(new VimTabbedTreeApp(), 40, 12)->initialize()->press('L');

        self::assertSame(1, $harness->model()->bar->selectedTab());

        $harness->press('H');

        self::assertSame(0, $harness->model()->bar->selectedTab());
    }

    #[Test]
    public function hAndLAreTypedIntoAFieldOnATab(): void
    {
        $harness = ModelHarness::for(new VimTabbedTreeApp(), 40, 12)->initialize()->press('L', 'L')->type('hLl');

        self::assertSame(2, $harness->model()->bar->selectedTab());
        $field = $harness->model()->bar->children()[0];
        self::assertInstanceOf(TextInput::class, $field);
        self::assertSame('hLl', $field->value());
    }
}

final readonly class VimTableApp extends CopyableTestApp
{
    public Table $table;

    public function __construct(?Table $table = null)
    {
        $this->table = $table ?? Table::fromCells(
            [Column::new('Name', Constraint::flex())],
            array_map(static fn (int $index): array => ["row $index"], range(0, 5)),
        );
    }

    public function view(): Widget
    {
        return Component::bind('table', $this->table, static fn (self $app, Table $table): self => $app->cloneWith(table: $table));
    }

    protected function globalKeybindings(): Keybindings
    {
        return VimMode::keybindings();
    }
}

final readonly class VimTabbedTreeApp extends CopyableTestApp
{
    public TabBar $bar;

    public function __construct(?TabBar $bar = null)
    {
        $this->bar = $bar ?? TabBar::new()
            ->addTab('Files')->add(TreeView::new([new SimpleTreeNode('dir', 'dir', '', [new SimpleTreeNode('file', 'file')])]))
            ->addTab('Logs')->add(TreeView::new([new SimpleTreeNode('log', 'log')]))
            ->addTab('Notes')->add(TextInput::new());
    }

    public function tree(): TreeView
    {
        $tree = $this->bar->withSelectedTab(0)->children()[0];
        TestCase::assertInstanceOf(TreeView::class, $tree);

        return $tree;
    }

    public function view(): Widget
    {
        return Component::bind('bar', $this->bar, static fn (self $app, TabBar $bar): self => $app->cloneWith(bar: $bar));
    }

    protected function globalKeybindings(): Keybindings
    {
        return VimMode::keybindings();
    }
}
