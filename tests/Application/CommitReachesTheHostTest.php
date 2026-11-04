<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Message\Chosen;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use Elmphant\Tui\Widget\Listing\ListItem;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\Table;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommitReachesTheHostTest extends TestCase
{
    #[Test]
    public function aHostHearsEveryEnterOnTheSameTableRow(): void
    {
        $harness = ModelHarness::for(OpeningApp::of(Table::fromCells([Column::new('Name', Constraint::flex())], [['alpha'], ['beta']])), 40, 10);

        $harness->press('down', 'enter', 'enter');

        $model = $harness->model();
        self::assertInstanceOf(OpeningApp::class, $model);
        self::assertSame([1, 1], $model->opened);
    }

    #[Test]
    public function aHostHearsEveryEnterOnTheSameListItem(): void
    {
        $harness = ModelHarness::for(OpeningApp::of(ListView::fromStrings(['alpha', 'beta'])), 40, 10);

        $harness->press('enter', 'enter');

        $model = $harness->model();
        self::assertInstanceOf(OpeningApp::class, $model);
        self::assertSame([0, 0], $model->opened);
    }

    #[Test]
    public function aHostHearsEveryClickOnTheSameListItem(): void
    {
        $harness = ModelHarness::for(OpeningApp::of(ListView::fromStrings(['alpha', 'beta'])), 40, 10);

        $harness->click('beta')->click('beta');

        $model = $harness->model();
        self::assertInstanceOf(OpeningApp::class, $model);
        self::assertSame([1, 1], $model->opened);
    }

    #[Test]
    public function aHostHearsEveryClickOnTheSameTableRow(): void
    {
        $harness = ModelHarness::for(OpeningApp::of(Table::fromCells([Column::new('Name', Constraint::flex())], [['alpha'], ['beta']])), 40, 10);

        $harness->click('beta')->click('beta');

        $model = $harness->model();
        self::assertInstanceOf(OpeningApp::class, $model);
        self::assertSame([1, 1], $model->opened);
    }

    #[Test]
    public function aClickOnAFilterableListHandsOnTheItemClicked(): void
    {
        $harness = ModelHarness::for(new ChoosingApp(FilterableList::new(ListView::fromStrings(['alpha', 'beta'])->items())), 40, 10);

        $harness->click('beta');

        $model = $harness->model();
        self::assertInstanceOf(ChoosingApp::class, $model);
        self::assertSame(['beta'], $model->chosen);
    }

    #[Test]
    public function aClickOnAFilterableTableHandsOnTheRowClicked(): void
    {
        $harness = ModelHarness::for(new ChoosingApp(FilterableTable::new([Column::new('Name', Constraint::flex())], [Row::new('alpha'), Row::new('beta')])), 40, 10);

        $harness->click('beta');

        $model = $harness->model();
        self::assertInstanceOf(ChoosingApp::class, $model);
        self::assertSame(['beta'], $model->chosen);
    }

    #[Test]
    public function aClickOnAnOptionInAnOpenDropdownHandsOnThatOption(): void
    {
        $harness = ModelHarness::for(new ChoosingApp(Dropdown::new(Select::new(['json', 'yaml']))), 40, 10);

        $harness->press('enter')->click('yaml');

        $model = $harness->model();
        self::assertInstanceOf(ChoosingApp::class, $model);
        self::assertSame(['yaml'], $model->chosen);
    }
}

final readonly class ChoosingApp extends App
{
    /**
     * @param list<string> $chosen
     */
    public function __construct(public FilterableList|FilterableTable|Dropdown $choices, public array $chosen = [])
    {
    }

    public function view(): Widget
    {
        return $this->bind('choices')->on(Chosen::class, static fn (self $app, Chosen $chosen): self => new self($app->choices, [
            ...$app->chosen,
            match (true) {
                $chosen->choice instanceof Row => $chosen->choice->textAt(0),
                $chosen->choice instanceof ListItem => $chosen->choice->title(),
                is_string($chosen->choice) => $chosen->choice,
                default => '?',
            },
        ]));
    }
}

final readonly class OpeningApp extends App
{
    /**
     * @param list<int> $opened
     */
    public function __construct(public Table|ListView $rows, public array $opened = [])
    {
    }

    public static function of(Table|ListView $rows): self
    {
        return new self($rows);
    }

    public function view(): Widget
    {
        return $this->bind('rows')->on(Chosen::class, static fn (self $app): self => new self($app->rows, [...$app->opened, $app->rows->cursor()]));
    }
}
