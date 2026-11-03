<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\Presets\VimMode;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableTableInVimModeTest extends TestCase
{
    #[Test]
    public function theCaretShowsOnlyWhileTheFieldHoldsTheKeys(): void
    {
        $harness = ModelHarness::for(new VimTableApp(FilterableTable::new(
            [Column::new('Route', Constraint::flex())],
            array_map(static fn (int $index): Row => Row::new("route $index"), range(1, 20)),
        )), 40, 10)->initialize();

        self::assertNull($harness->renderFrame()->cursorPosition());
        self::assertNotNull($harness->press('/')->renderFrame()->cursorPosition());
        self::assertNull($harness->press('enter')->renderFrame()->cursorPosition());
    }
}

final readonly class VimTableApp extends CopyableTestApp
{
    public function __construct(public FilterableTable $table)
    {
    }

    public function view(): Widget
    {
        return Component::bind('routes', $this->table, static fn (self $app, FilterableTable $table): self => new self($table));
    }

    protected function globalKeybindings(): Keybindings
    {
        return VimMode::keybindings();
    }
}
