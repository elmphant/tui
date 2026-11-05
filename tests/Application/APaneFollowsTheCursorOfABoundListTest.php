<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\Table;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class APaneFollowsTheCursorOfABoundListTest extends TestCase
{
    #[Test]
    public function aKeptPaneKeepsTheCursorRowOfTheListInsideOnScreen(): void
    {
        $harness = ModelHarness::for(new KeptPaneAroundAListApp(self::thirtyItems()), 30, 8);

        for ($step = 0; $step < 20; ++$step) {
            $harness->press('down');
        }

        self::assertStringContainsString('item20', $harness->screen()->plainText());
    }

    #[Test]
    public function aPaneBuiltInViewKeepsTheCursorRowOfTheListInsideOnScreen(): void
    {
        $harness = ModelHarness::for(new UnboundPaneAroundAListApp(self::thirtyItems()), 30, 8);

        for ($step = 0; $step < 20; ++$step) {
            $harness->press('down');
        }

        self::assertStringContainsString('item20', $harness->screen()->plainText());
    }

    #[Test]
    public function aPaneBuiltInViewKeepsTheCursorRowOfTheTableInsideOnScreen(): void
    {
        $rows = array_map(static fn (int $row): Row => Row::new('row' . $row), range(0, 29));
        $harness = ModelHarness::for(new UnboundPaneAroundATableApp(Table::new([Column::new('Name', Constraint::flex())], $rows)), 30, 8);

        for ($step = 0; $step < 20; ++$step) {
            $harness->press('down');
        }

        self::assertStringContainsString('row20', $harness->screen()->plainText());
    }

    private static function thirtyItems(): ListView
    {
        return ListView::fromStrings(array_map(static fn (int $item): string => 'item' . $item, range(0, 29)));
    }
}

final readonly class KeptPaneAroundAListApp extends App
{
    public function __construct(private ListView $list, private Panel $pane = new Panel('List'))
    {
    }

    public function view(): Widget
    {
        return Component::bind('pane', $this->pane->withChildren([$this->bind('list')]), static fn (self $app, Panel $pane): self => $app->cloneWith(pane: $pane));
    }
}

final readonly class UnboundPaneAroundATableApp extends App
{
    public function __construct(private Table $table)
    {
    }

    public function view(): Widget
    {
        return Panel::new('Table')->add($this->bind('table'));
    }
}

final readonly class UnboundPaneAroundAListApp extends App
{
    public function __construct(private ListView $list)
    {
    }

    public function view(): Widget
    {
        return Panel::new('List')->add($this->bind('list'));
    }
}
