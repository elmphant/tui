<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ALineOfFixedRowsTakesItsRowsTest extends TestCase
{
    #[Test]
    public function aRowOfOneRowWidgetsTakesOneRow(): void
    {
        $screen = ModelHarness::for(new StackedLines(Layout::row(Text::new('GET'), Text::new('http://example.com'))), width: 40, height: 20)->screen();

        self::assertSame(1, $screen->rowOf('one'));
    }

    #[Test]
    public function aColumnOfOneRowWidgetsTakesTwoRows(): void
    {
        $screen = ModelHarness::for(new StackedLines(Layout::column(Text::new('name'), Text::new('status'))), width: 40, height: 20)->screen();

        self::assertSame(2, $screen->rowOf('one'));
    }

    #[Test]
    public function aRowHoldingAListStillFillsTheRowsLeft(): void
    {
        $screen = ModelHarness::for(new StackedLines(Layout::row(Text::new('side'), ListView::fromStrings(['inner']))), width: 40, height: 20)->screen();

        self::assertSame(19, $screen->rowOf('footer'));
    }
}

final readonly class StackedLines extends App
{
    public function __construct(private Widget $line)
    {
    }

    public function view(): Widget
    {
        return Layout::column($this->line, ListView::fromStrings(['one', 'two']), Text::new('footer'));
    }
}
