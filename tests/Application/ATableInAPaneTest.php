<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Table;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ATableInAPaneTest extends TestCase
{
    #[Test]
    public function thePaneScrollsTheTableCursorIntoView(): void
    {
        $harness = ModelHarness::for(TableInAPaneApp::start(), 40, 8)->init();

        $harness->press(...array_fill(0, 9, 'down'));

        self::assertStringContainsString('row 9', $harness->screen()->plainText());
    }

    #[Test]
    public function thePaneScrollsTheListCursorIntoView(): void
    {
        $list = ListView::fromStrings(array_map(static fn (int $row): string => "item $row", range(0, 11)));
        $harness = ModelHarness::for(new TableInAPaneApp(Panel::new('Files')->add(Text::new('Recent'))->add($list)), 40, 8)->init();

        $harness->press(...array_fill(0, 9, 'down'));

        self::assertStringContainsString('item 9', $harness->screen()->plainText());
    }
}

final readonly class TableInAPaneApp extends App
{
    public function __construct(private Panel $pane)
    {
    }

    public static function start(): self
    {
        return new self(Panel::new('Project')
            ->add(Text::new('Task Board'))
            ->add(Table::fromCells([Column::new('Task', Constraint::flex())], array_map(static fn (int $row): array => ["row $row"], range(0, 11)))));
    }

    public function view(): Widget
    {
        return $this->bind('pane');
    }
}
