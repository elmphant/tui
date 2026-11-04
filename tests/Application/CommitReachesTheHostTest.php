<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Table\Column;
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
        return Component::bind(
            'rows',
            $this->rows,
            static fn (self $app, Table|ListView $rows, Message $message): self => new self(
                $rows,
                $rows->outcomeOf($message) !== null ? [...$app->opened, $rows->cursor()] : $app->opened,
            ),
        );
    }
}
