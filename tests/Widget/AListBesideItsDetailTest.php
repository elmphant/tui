<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Bar\Header;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Box;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AListBesideItsDetailTest extends TestCase
{
    #[Test]
    public function theDetailFillsTheBodyAndShowsEveryLineWhenTheFilterLeavesOneRow(): void
    {
        $harness = ModelHarness::for(ListAndDetailApp::start(), 60, 20)->init();

        $harness->type('config');

        self::assertSame(18, $harness->screen()->rowOf('╰'), $harness->screen()->plainText());
        self::assertStringContainsString('three', $harness->screen()->plainText());
    }
}

final readonly class ListAndDetailApp extends App
{
    public function __construct(public FilterableTable $table)
    {
    }

    public static function start(): self
    {
        return new self(FilterableTable::new([Column::new('Name', Constraint::flex())], array_map(Row::new(...), ['symfony/config', 'symfony/console', 'symfony/finder', 'symfony/process', 'symfony/string'])));
    }

    public function view(): Widget
    {
        return Layout::column(
            Header::new('Packages')->withRule(),
            Layout::row(
                $this->bind('table'),
                Box::new('Detail')->withChildren([
                    Paragraph::fromText('Helps you find, load, combine, autofill and validate configuration values of any kind'),
                    Paragraph::fromText('Homepage: https://symfony.com'),
                    Paragraph::fromText('Path: /srv/web/root/vendor/symfony/config'),
                    Text::new('three'),
                ]),
            ),
            Footer::forHints(['q' => 'quit']),
        );
    }
}
