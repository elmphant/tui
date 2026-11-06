<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Filter\FilterableCheckboxGroup;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Filter\FilterableStaticTable;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Box;
use Elmphant\Tui\Widget\Structure\Container;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AFilterFillsTheBoxAroundItTest extends TestCase
{
    /**
     * @return iterable<string, array{Widget}>
     */
    public static function filters(): iterable
    {
        $names = array_map(static fn (int $number): string => "row {$number}", range(1, 20));

        yield 'a filterable table' => [FilterableTable::new([Column::new('Name', Constraint::flex())], array_map(static fn (string $name): Row => Row::new($name), $names))];
        yield 'a filterable list' => [FilterableList::new(array_map(static fn (string $name): SimpleListItem => new SimpleListItem($name), $names))];
        yield 'a filterable checkbox group' => [FilterableCheckboxGroup::new($names)];
        yield 'a filterable static table' => [FilterableStaticTable::new(['Name'], array_map(static fn (string $name): array => [$name], $names))];
    }

    /**
     * @return iterable<string, array{Widget}>
     */
    public static function nestings(): iterable
    {
        foreach (self::filters() as $name => [$filter]) {
            $box = Box::new('x')->withChildren([$filter]);

            yield "{$name} in a box" => [$box];
            yield "{$name} in a container around a box" => [Container::around($box)];
            yield "{$name} in a box growing in a column" => [Layout::column()->add($box, Constraint::flex())];
            yield "{$name} in a container around a box growing in a column" => [Layout::column()->add(Container::around($box), Constraint::flex())];
        }
    }

    #[Test]
    #[DataProvider('nestings')]
    public function aFilterWithMoreRowsThanABoxHoldsFillsTheBox(Widget $nesting): void
    {
        self::assertStringContainsString('row', $this->lastInnerRow($nesting));
    }

    #[Test]
    #[DataProvider('filters')]
    public function aFilterWithMoreRowsThanAPanelHoldsFillsThePanel(Widget $filter): void
    {
        self::assertStringContainsString('row', $this->lastInnerRow(Panel::new('x')->withChildren([$filter])));
    }

    #[Test]
    public function aLineUnderAFilterInABoxKeepsItsOneRowAtTheBottom(): void
    {
        $filter = FilterableList::new([new SimpleListItem('alpha'), new SimpleListItem('beta')]);

        self::assertStringContainsString('the end', $this->lastInnerRow(Box::new('x')->withChildren([$filter, Text::new('the end')])));
    }

    private function lastInnerRow(Widget $host): string
    {
        $frame = new Frame(30, 10);
        $host->render($frame, Rect::fromSize(30, 10), RenderContext::unfocused(Theme::dark()));

        return implode('', \array_slice($frame->characters, 8 * 30, 30));
    }
}
