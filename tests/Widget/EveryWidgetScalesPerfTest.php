<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Closure;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\UserInput;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
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
use Elmphant\Tui\Widget\Form\ChoiceField;
use Elmphant\Tui\Widget\Form\Field;
use Elmphant\Tui\Widget\Form\Form;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Layout\Breakpoint;
use Elmphant\Tui\Widget\Layout\Flex;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Popup\PositionedOverlay;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Separator;
use Elmphant\Tui\Widget\Prose\Spacer;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Box;
use Elmphant\Tui\Widget\Structure\Card;
use Elmphant\Tui\Widget\Structure\Container;
use Elmphant\Tui\Widget\Structure\FocusGutter;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\ScrollPane;
use Elmphant\Tui\Widget\Structure\Split;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Structure\TabPage;
use Elmphant\Tui\Widget\Structure\Themed;
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

final class EveryWidgetScalesPerfTest extends TestCase
{
    use PerformanceBudget;

    private const SMALL = 50;
    private const LARGE = 2_000;
    private const GROWTH = self::LARGE / self::SMALL;
    private const LINEAR = 3 * self::GROWTH;
    private const WIDTH = 80;
    private const HEIGHT = 24;

    /**
     * @return iterable<string, array{Closure(int): Widget}>
     */
    public static function widgets(): iterable
    {
        foreach (self::scenarios() as $name => $scenario) {
            yield $name => [$scenario['build']];
        }
    }

    /**
     * @return iterable<string, array{Closure(int): Widget}>
     */
    public static function widgetsThatDrawTheScreen(): iterable
    {
        foreach (self::scenarios() as $name => $scenario) {
            if (!$scenario['framesCostTheContent']) {
                yield $name => [$scenario['build']];
            }
        }
    }

    /**
     * @return iterable<string, array{Closure(int): Widget, Message, float}>
     */
    public static function interactions(): iterable
    {
        foreach (self::scenarios() as $name => $scenario) {
            foreach ($scenario['screenBound'] as $operation => $message) {
                yield "{$name}: {$operation}" => [$scenario['build'], $message, self::MAX_RATIO];
            }
            foreach ($scenario['contentBound'] as $operation => $message) {
                yield "{$name}: {$operation}" => [$scenario['build'], $message, self::LINEAR];
            }
        }
    }

    #[Test]
    #[DataProvider('widgets')]
    public function buildingAndTheFirstFrameGrowNoFasterThanTheContent(Closure $build): void
    {
        self::skipUnderXdebug();

        self::assertRatioBelow(self::LINEAR, static function () use ($build): array {
            $small = self::medianMicroseconds(static fn () => self::draw($build(self::SMALL)), 5, 1);
            $large = self::medianMicroseconds(static fn () => self::draw($build(self::LARGE)), 5, 1);
            $ratio = $large / $small;

            return [$ratio, sprintf('%dx the content cost %.0fx to build and draw (%.0fµs vs %.0fµs)', self::GROWTH, $ratio, $large, $small)];
        });
    }

    #[Test]
    #[DataProvider('widgetsThatDrawTheScreen')]
    public function drawingAgainCostsTheScreenNotTheContent(Closure $build): void
    {
        self::skipUnderXdebug();

        $small = $build(self::SMALL);
        $large = $build(self::LARGE);

        self::assertRatioBelow(self::MAX_RATIO, static function () use ($small, $large): array {
            $short = self::medianMicroseconds(static fn () => self::draw($small), 9, 3);
            $long = self::medianMicroseconds(static fn () => self::draw($large), 9, 3);
            $ratio = $long / $short;

            return [$ratio, sprintf('%dx the content cost %.0fx to draw again (%.0fµs vs %.0fµs)', self::GROWTH, $ratio, $long, $short)];
        });
    }

    #[Test]
    #[DataProvider('interactions')]
    public function aKeyOrAClickAndTheFrameAfterItStayWithinTheirBudget(Closure $build, Message $message, float $budget): void
    {
        self::skipUnderXdebug();

        $small = $build(self::SMALL);
        $large = $build(self::LARGE);
        self::assertInstanceOf(Interactive::class, $small);
        self::assertInstanceOf(Interactive::class, $large);
        $answer = static fn (Interactive $widget) => self::draw($widget->update($message, self::area())->model);

        self::assertRatioBelow($budget, static function () use ($answer, $small, $large): array {
            $short = self::medianMicroseconds(static fn () => $answer($small), 9, 3);
            $long = self::medianMicroseconds(static fn () => $answer($large), 9, 3);
            $ratio = $long / $short;

            return [$ratio, sprintf('%dx the content made it cost %.0fx (%.0fµs vs %.0fµs)', self::GROWTH, $ratio, $long, $short)];
        });
    }

    private static function draw(Widget $widget): void
    {
        $widget->render(new Frame(self::WIDTH, self::HEIGHT), self::area(), RenderContext::focused(Theme::dark()));
    }

    private static function area(): Rect
    {
        return Rect::fromSize(self::WIDTH, self::HEIGHT);
    }

    /**
     * Each widget is drawn on an 80x24 screen with more and more content. A filter checks every item,
     * a stacker lays out every child, a form rebuilds every row and a choice field measures every
     * option. Those may cost the content, never more.
     *
     * @return array<string, array{build: Closure(int): Widget, screenBound: array<string, Message>, contentBound: array<string, Message>, framesCostTheContent: bool}>
     */
    private static function scenarios(): array
    {
        $cursor = [
            'down' => UserInput::key('down'),
            'page down' => UserInput::key('pagedown'),
            'end' => UserInput::key('end'),
            'wheel' => UserInput::wheel(5, 5, 3),
            'click' => UserInput::press(5, 5),
        ];
        $caret = [
            'typing' => UserInput::key('x'),
            'left' => UserInput::key('left'),
            'home' => UserInput::key('home'),
            'click' => UserInput::press(5, 0),
        ];
        $scroll = [
            'page down' => UserInput::key('pagedown'),
            'end' => UserInput::key('end'),
            'wheel' => UserInput::wheel(5, 5, 3),
        ];
        $row = ['right' => UserInput::key('right'), 'end' => UserInput::key('end'), 'space' => UserInput::key('space')];
        $filtering = ['typing' => UserInput::key('9')];

        return [
            'ActivityBar' => self::scenario(static fn (int $size) => ActivityBar::new(self::words($size))),
            'Alert' => self::scenario(static fn (int $size) => Alert::warning(self::words($size))),
            'Box' => self::scenario(static fn (int $size) => Box::new('box')->withChildren(self::paragraphs($size)), framesCostTheContent: true),
            'Breakpoint' => self::scenario(static fn (int $size) => Breakpoint::at(60, self::list($size), Paragraph::fromText(self::lines($size)))),
            'Button' => self::scenario(static fn (int $size) => Button::new(self::words($size)), ['enter' => UserInput::key('enter'), 'click' => UserInput::press(2, 0)]),
            'Card' => self::scenario(static fn (int $size) => Card::new('card', self::lines($size))),
            'Checkbox' => self::scenario(static fn (int $size) => Checkbox::new(self::words($size)), ['space' => UserInput::key('space'), 'click' => UserInput::press(1, 0)]),
            'CheckboxGroup' => self::scenario(static fn (int $size) => CheckboxGroup::new(self::labels($size)), $cursor + ['space' => UserInput::key('space')]),
            'CheckboxRow' => self::scenario(static fn (int $size) => CheckboxRow::new(self::labels($size)), $row),
            'ChoiceField' => self::scenario(static fn (int $size) => ChoiceField::new(self::options($size)), contentBound: ['down' => UserInput::key('down'), 'enter' => UserInput::key('enter')], framesCostTheContent: true),
            'Container' => self::scenario(static fn (int $size) => Container::around(Paragraph::fromText(self::lines($size)))),
            'Dropdown' => self::scenario(static fn (int $size) => Dropdown::new(Select::new(self::labels($size))), ['enter' => UserInput::key('enter'), 'click' => UserInput::press(2, 0)]),
            'FilterableCheckboxGroup' => self::scenario(static fn (int $size) => FilterableCheckboxGroup::new(self::labels($size)), $cursor, $filtering),
            'FilterableList' => self::scenario(static fn (int $size) => FilterableList::new(self::items($size)), $cursor, $filtering),
            'FilterableStaticTable' => self::scenario(static fn (int $size) => FilterableStaticTable::new(['name', 'detail'], self::cells($size)), $scroll, $filtering),
            'FilterableTable' => self::scenario(static fn (int $size) => FilterableTable::new(self::columns(), self::rows($size)), $cursor, $filtering),
            'Flex' => self::scenario(static fn (int $size) => array_reduce(self::paragraphs($size), static fn (Flex $flex, Widget $child) => $flex->add($child), Flex::column()), framesCostTheContent: true),
            'FocusGutter' => self::scenario(static fn (int $size) => FocusGutter::around(self::list($size)), $cursor),
            'FocusHints' => self::scenario(static fn () => FocusHints::new()),
            'Footer' => self::scenario(static fn (int $size) => Footer::forHints(self::hints($size))),
            'Form' => self::scenario(static fn (int $size) => array_reduce(range(1, $size), static fn (Form $form, int $index) => $form->withField(Field::text("field{$index}", "Field {$index}")), Form::new('form')), contentBound: $scroll, framesCostTheContent: true),
            'Header' => self::scenario(static fn (int $size) => Header::new(self::words($size))),
            'Layout' => self::scenario(static fn (int $size) => Layout::column(...self::paragraphs($size)), framesCostTheContent: true),
            'ListView' => self::scenario(static fn (int $size) => self::list($size), $cursor),
            'Modal' => self::scenario(static fn (int $size) => Modal::new('modal', self::list($size)), ['escape' => UserInput::key('escape'), 'click' => UserInput::press(0, 0)]),
            'Overlay' => self::scenario(static fn (int $size) => Overlay::new(Paragraph::fromText(self::lines($size)), self::list($size))),
            'Panel' => self::scenario(static fn (int $size) => Panel::new('panel')->withChildren(self::paragraphs($size)), $scroll),
            'Paragraph' => self::scenario(static fn (int $size) => Paragraph::fromText(self::lines($size))),
            'PositionedOverlay' => self::scenario(static fn (int $size) => PositionedOverlay::at(2, 2)->withSize(30, 10)->withContent(self::list($size))),
            'ProgressBar' => self::scenario(static fn (int $size) => ProgressBar::new(0.5)->withLabel(self::words($size))),
            'Radio' => self::scenario(static fn (int $size) => Radio::new(self::labels($size)), $cursor + ['enter' => UserInput::key('enter')]),
            'RadioRow' => self::scenario(static fn (int $size) => RadioRow::new(self::labels($size)), $row),
            'ScrollPane' => self::scenario(static fn (int $size) => ScrollPane::of(...self::paragraphs($size)), $scroll),
            'Select' => self::scenario(static fn (int $size) => Select::new(self::labels($size)), $cursor + ['enter' => UserInput::key('enter')]),
            'Separator' => self::scenario(static fn () => Separator::new()),
            'Spacer' => self::scenario(static fn (int $size) => Spacer::new($size)),
            'Spinner' => self::scenario(static fn (int $size) => Spinner::new(self::words($size))),
            'Split' => self::scenario(static fn (int $size) => Split::row(SplitState::even(2), self::list($size), Paragraph::fromText(self::lines($size))), ['press on the divider' => UserInput::press(40, 5)]),
            'StaticTable' => self::scenario(static fn (int $size) => StaticTable::new(['name', 'detail'], self::cells($size)), framesCostTheContent: true),
            'StatusBar' => self::scenario(static fn (int $size) => StatusBar::new(self::words($size))),
            'TabBar' => self::scenario(static fn (int $size) => self::tabs($size), ['right' => UserInput::key('right'), 'end' => UserInput::key('end'), 'click' => UserInput::press(3, 1)]),
            'Table' => self::scenario(static fn (int $size) => Table::new(self::columns(), self::rows($size)), $cursor),
            'Text' => self::scenario(static fn (int $size) => Text::new(self::words($size))),
            'TextArea' => self::scenario(static fn (int $size) => TextArea::new(self::lines($size)), $caret + ['down' => UserInput::key('down'), 'page down' => UserInput::key('pagedown')]),
            'TextInput' => self::scenario(static fn (int $size) => TextInput::new(self::words($size)), $caret),
            'Themed' => self::scenario(static fn (int $size) => Themed::around(self::list($size), static fn (Theme $theme): Theme => $theme)),
            'TreeView' => self::scenario(static fn (int $size) => self::tree($size), $cursor + ['expand' => UserInput::key('right')]),
        ];
    }

    /**
     * @param Closure(int): Widget   $build
     * @param array<string, Message> $screenBound
     * @param array<string, Message> $contentBound
     *
     * @return array{build: Closure(int): Widget, screenBound: array<string, Message>, contentBound: array<string, Message>, framesCostTheContent: bool}
     */
    private static function scenario(Closure $build, array $screenBound = [], array $contentBound = [], bool $framesCostTheContent = false): array
    {
        return ['build' => $build, 'screenBound' => $screenBound, 'contentBound' => $contentBound, 'framesCostTheContent' => $framesCostTheContent];
    }

    private static function words(int $size): string
    {
        return implode(' ', array_map(static fn (int $index): string => "word{$index}", range(1, $size)));
    }

    private static function lines(int $size): string
    {
        return implode("\n", array_map(static fn (int $index): string => "line {$index} of the text", range(1, $size)));
    }

    /**
     * @return list<Paragraph>
     */
    private static function paragraphs(int $size): array
    {
        return array_map(static fn (int $index): Paragraph => Paragraph::fromText("paragraph {$index}"), range(1, $size));
    }

    /**
     * @return list<string>
     */
    private static function labels(int $size): array
    {
        return array_map(static fn (int $index): string => "Option {$index}", range(1, $size));
    }

    /**
     * @return array<string, string>
     */
    private static function options(int $size): array
    {
        return array_combine(
            array_map(static fn (int $index): string => "option{$index}", range(1, $size)),
            self::labels($size),
        );
    }

    /**
     * @return list<SimpleListItem>
     */
    private static function items(int $size): array
    {
        return array_map(static fn (int $index): SimpleListItem => new SimpleListItem("item {$index}", "detail {$index}"), range(1, $size));
    }

    private static function list(int $size): ListView
    {
        return ListView::new(self::items($size));
    }

    /**
     * @return list<list<string>>
     */
    private static function cells(int $size): array
    {
        return array_map(static fn (int $index): array => ["name {$index}", "detail {$index}"], range(1, $size));
    }

    /**
     * @return list<Column>
     */
    private static function columns(): array
    {
        return [Column::new('Name', Constraint::exact(14)), Column::new('Detail', Constraint::flex())];
    }

    /**
     * @return list<Row>
     */
    private static function rows(int $size): array
    {
        return array_map(static fn (int $index): Row => Row::new("name {$index}", "detail {$index}"), range(1, $size));
    }

    /**
     * @return array<string, string>
     */
    private static function hints(int $size): array
    {
        return array_combine(
            array_map(static fn (int $index): string => "k{$index}", range(1, $size)),
            array_map(static fn (int $index): string => "action {$index}", range(1, $size)),
        );
    }

    private static function tabs(int $size): TabBar
    {
        return TabBar::new()->withTabs(array_map(
            static fn (int $index): TabPage => new TabPage("tab {$index}", ScrollPane::of(Text::new("page {$index}"))),
            range(1, $size),
        ));
    }

    private static function tree(int $size): TreeView
    {
        $children = array_map(static fn (int $index): SimpleTreeNode => new SimpleTreeNode("leaf{$index}", "leaf {$index}"), range(1, $size));

        return TreeView::new([new SimpleTreeNode('root', 'root', children: $children)]);
    }
}
