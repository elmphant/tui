<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\Table;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AFlashedRowTest extends TestCase
{
    /**
     * @return iterable<string, array{Interactive}>
     */
    public static function keyedWidgets(): iterable
    {
        $columns = [Column::new('Job', Constraint::flex())];
        $rows = [Row::new('build'), Row::new('deploy')];
        $items = [new SimpleListItem('build'), new SimpleListItem('deploy')];

        yield 'table' => [Table::new($columns, $rows)];
        yield 'filterable table' => [FilterableTable::new($columns, $rows)];
        yield 'list' => [ListView::new($items)];
        yield 'filterable list' => [FilterableList::new($items)];
    }

    #[Test]
    #[DataProvider('keyedWidgets')]
    public function aFlashedRowStartsOnTheWarningColourAndEndsPlain(Interactive $jobs): void
    {
        $harness = ModelHarness::for(new FlashingApp($jobs), 30, 8)->init();
        $warning = Theme::dark()->warning->foreground?->value;

        self::assertSame($warning, self::backgroundOf($harness, 'deploy'));

        $harness->advance(2.0);

        self::assertSame(0, self::backgroundOf($harness, 'deploy'), 'plain again');
    }

    #[Test]
    #[DataProvider('keyedWidgets')]
    public function aFlashedRowUnderTheCursorStartsOnTheWarningColourAndFadesBackToTheCursor(Interactive $jobs): void
    {
        $harness = ModelHarness::for(new FlashingApp($jobs, 'build'), 30, 8)->init();
        $theme = Theme::dark();

        self::assertSame($theme->warning->foreground?->value, self::backgroundOf($harness, 'build'));

        $harness->advance(2.0);

        self::assertSame($theme->cursorStyle->background?->value, self::backgroundOf($harness, 'build'));
    }

    #[Test]
    public function aFlashFadesOnTheWay(): void
    {
        $harness = ModelHarness::for(new FlashingApp(Table::new([Column::new('Job', Constraint::flex())], [Row::new('build'), Row::new('deploy')])), 30, 8)->init();
        $start = self::backgroundOf($harness, 'deploy');

        $harness->advance(1.0);

        self::assertNotSame($start, self::backgroundOf($harness, 'deploy'));
    }

    /**
     * @param ModelHarness<FlashingApp> $harness
     */
    private static function backgroundOf(ModelHarness $harness, string $text): int
    {
        $screen = $harness->screen();
        ['row' => $row, 'column' => $column] = $screen->findOrFail($text);

        return $screen->styleAt($column, $row)->background->value ?? 0;
    }
}

final readonly class FlashingApp extends App
{
    public function __construct(public Interactive $jobs, private string $flashed = 'deploy')
    {
    }

    public function view(): Widget
    {
        return $this->bind('jobs');
    }

    protected function setup(): ?Cmd
    {
        return Cmd::flash('jobs', $this->flashed);
    }
}
