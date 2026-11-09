<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Bar\FocusHints;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Bar\Header;
use Elmphant\Tui\Widget\Bar\StatusBar;
use Elmphant\Tui\Widget\Choice\Checkbox;
use Elmphant\Tui\Widget\Choice\CheckboxGroup;
use Elmphant\Tui\Widget\Choice\CheckboxRow;
use Elmphant\Tui\Widget\Choice\Radio;
use Elmphant\Tui\Widget\Choice\RadioRow;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Feedback\ActivityBar;
use Elmphant\Tui\Widget\Feedback\Alert;
use Elmphant\Tui\Widget\Feedback\ProgressBar;
use Elmphant\Tui\Widget\Feedback\Spinner;
use Elmphant\Tui\Widget\Field\Button;
use Elmphant\Tui\Widget\Field\TextArea;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Filter\FilterableCheckboxGroup;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Filter\FilterableStaticTable;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use Elmphant\Tui\Widget\Form\Field;
use Elmphant\Tui\Widget\Form\Form;
use Elmphant\Tui\Widget\Layout\Flex;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Separator;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Box;
use Elmphant\Tui\Widget\Structure\Card;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\ScrollPane;
use Elmphant\Tui\Widget\Structure\Split;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Structure\TabPage;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\StaticTable;
use Elmphant\Tui\Widget\Table\Table;
use Elmphant\Tui\Widget\Tree\SimpleTreeNode;
use Elmphant\Tui\Widget\Tree\TreeView;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EveryWidgetStaysInsideItsAreaTest extends TestCase
{
    private const LONG = 'a rather long label 中文 that cannot fit';
    private const FRAME_WIDTH = 40;
    private const FRAME_HEIGHT = 12;

    /**
     * @return iterable<string, array{Widget, Rect}>
     */
    public static function widgetsInSmallAreas(): iterable
    {
        foreach (self::widgets() as $name => $widget) {
            foreach ([[1, 1], [3, 1], [5, 3], [12, 4]] as [$width, $height]) {
                yield "{$name} in {$width}x{$height}" => [$widget, new Rect(4, 4, $width, $height)];
            }
        }
    }

    #[Test]
    #[DataProvider('widgetsInSmallAreas')]
    public function aWidgetDrawsNothingOutsideTheAreaItIsGiven(Widget $widget, Rect $area): void
    {
        $frame = new Frame(self::FRAME_WIDTH, self::FRAME_HEIGHT);

        $widget->render($frame, $area, RenderContext::focused(Theme::dark()));

        self::assertSame([], self::cellsTouchedOutside($frame, $area));
    }

    /**
     * @return list<string>
     */
    private static function cellsTouchedOutside(Frame $frame, Rect $area): array
    {
        $touched = [];
        for ($y = 0; $y < $frame->height; ++$y) {
            for ($x = 0; $x < $frame->width; ++$x) {
                $index = $y * $frame->width + $x;
                $drawn = $frame->characters[$index] !== ' '
                    || $frame->foregrounds[$index] !== 0
                    || $frame->backgrounds[$index] !== 0
                    || $frame->attributes[$index] !== 0;
                if ($drawn && !$area->contains($x, $y)) {
                    $touched[] = "{$x},{$y} '{$frame->characters[$index]}'";
                }
            }
        }

        return $touched;
    }

    /**
     * @return array<string, Widget>
     */
    private static function widgets(): array
    {
        $labels = [self::LONG, 'second option'];
        $items = [new SimpleListItem(self::LONG, self::LONG), new SimpleListItem('second', 'detail')];
        $columns = [Column::new('Name', Constraint::exact(14)), Column::new('Detail', Constraint::flex())];
        $rows = [Row::new(self::LONG, self::LONG), Row::new('second', 'detail')];

        return [
            'ActivityBar' => ActivityBar::new(self::LONG),
            'Alert' => Alert::warning(self::LONG),
            'Box' => Box::new(self::LONG)->withChildren([Paragraph::fromText(self::LONG)]),
            'Button' => Button::new(self::LONG),
            'Card' => Card::new(self::LONG, self::LONG),
            'Checkbox' => Checkbox::new(self::LONG),
            'CheckboxGroup' => CheckboxGroup::new($labels),
            'CheckboxRow' => CheckboxRow::new($labels),
            'Dropdown' => Dropdown::new(Select::new($labels)),
            'FilterableCheckboxGroup' => FilterableCheckboxGroup::new($labels),
            'FilterableList' => FilterableList::new($items),
            'FilterableStaticTable' => FilterableStaticTable::new(['name', 'detail'], [[self::LONG, self::LONG]]),
            'FilterableTable' => FilterableTable::new($columns, $rows),
            'Flex row' => Flex::row()->add(Text::new(self::LONG))->add(Text::new(self::LONG)),
            'FocusHints' => FocusHints::new(),
            'Footer' => Footer::forHints(['q' => 'quit', 'enter' => self::LONG]),
            'Form' => Form::new('form')->withField(Field::text('name', self::LONG)),
            'Header' => Header::new(self::LONG),
            'Layout row' => Layout::row(Text::new(self::LONG), Text::new(self::LONG)),
            'ListView' => ListView::new($items),
            'Modal' => Modal::new(self::LONG, Paragraph::fromText(self::LONG)),
            'Panel' => Panel::new(self::LONG)->withChildren([Paragraph::fromText(self::LONG)]),
            'Paragraph' => Paragraph::fromText(self::LONG),
            'ProgressBar' => ProgressBar::new(0.5)->withPercentShown(),
            'ProgressBar with a label' => ProgressBar::new(0.5)->withLabel(self::LONG)->withPercentShown(),
            'Radio' => Radio::new($labels, self::LONG),
            'RadioRow' => RadioRow::new($labels),
            'ScrollPane' => ScrollPane::of(Paragraph::fromText(self::LONG)),
            'Select' => Select::new($labels),
            'Separator' => Separator::new(),
            'Spinner' => Spinner::new(self::LONG),
            'Split' => Split::row(SplitState::even(2), Text::new(self::LONG), Text::new(self::LONG)),
            'StaticTable' => StaticTable::new(['name', 'detail'], [[self::LONG, self::LONG]]),
            'StatusBar' => StatusBar::new(self::LONG),
            'TabBar' => TabBar::new()->withTabs([new TabPage(self::LONG, ScrollPane::of(Text::new(self::LONG))), new TabPage('second', ScrollPane::of(Text::new('page')))]),
            'Table' => Table::new($columns, $rows),
            'Text' => Text::new(self::LONG),
            'TextArea' => TextArea::new(self::LONG . "\n" . self::LONG),
            'TextInput' => TextInput::new(self::LONG),
            'TreeView' => TreeView::new([new SimpleTreeNode('root', self::LONG, children: [new SimpleTreeNode('leaf', self::LONG)])]),
        ];
    }
}
