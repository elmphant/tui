<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Api;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Message\QuitMsg;
use Elmphant\Tui\Rendering\Context\FloatTarget;
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
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Filter\FilterableCheckboxGroup;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Filter\FilterableStaticTable;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use Elmphant\Tui\Widget\Form\ChoiceField;
use Elmphant\Tui\Widget\Form\Form;
use Elmphant\Tui\Widget\Form\FormFoot;
use Elmphant\Tui\Widget\Layout\Breakpoint;
use Elmphant\Tui\Widget\Layout\Flex;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Popup\DropdownPopup;
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
use Elmphant\Tui\Widget\Table;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\StaticTable;
use Elmphant\Tui\Widget\Tree\SimpleTreeNode;
use Elmphant\Tui\Widget\Tree\TreeView;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Verify declared widths against drawn content; distinguish fixed content, fill-only widgets and blank spacers.
 */
final class IntrinsicWidthTest extends TestCase
{
    private const GENEROUS_WIDTH = 60;
    private const GENEROUS_HEIGHT = 12;
    private const NARROW_WIDTH = 17;

    /**
     * Classify every widget so a missing width cannot silently make it disappear from a bar slot.
     */
    #[Test]
    public function everyWidgetIsClassified(): void
    {
        $classified = [];

        $kinds = [
            self::contentSizedWidgets(),
            self::widgetsPreferringAWidth(),
            self::rowTakers(),
            self::proportionalWidgets(),
            self::fillOnlyWidgets(),
            self::spaceReservers(),
        ];

        foreach ($kinds as $kind) {
            foreach ($kind as [$widget]) {
                $classified[$widget::class] = true;
            }
        }

        $unclassified = array_values(array_diff(self::widgetClasses(), array_keys($classified)));
        sort($unclassified);

        self::assertSame([], $unclassified, "Every Widget needs a width kind:\n" . implode("\n", $unclassified));
    }

    #[Test]
    #[DataProvider('contentSizedWidgets')]
    public function aContentSizedWidgetDeclaresTheWidthItPaints(Widget $widget): void
    {
        self::assertSame(
            self::inkWidth($widget, self::GENEROUS_WIDTH),
            self::declaredWidth($widget),
            'declared width must be the width it draws',
        );
    }

    /**
     * Decorations such as button borders stretch beyond content, so slots must use declared content width.
     */
    #[Test]
    #[DataProvider('widgetsPreferringAWidth')]
    public function aWidgetPreferringAWidthStillPaintsWhatItIsGiven(Widget $widget): void
    {
        self::assertGreaterThan(0, self::declaredWidth($widget), 'it has a width it prefers');
        self::assertSame(self::GENEROUS_WIDTH, self::inkWidth($widget, self::GENEROUS_WIDTH));
        self::assertSame(self::NARROW_WIDTH, self::inkWidth($widget, self::NARROW_WIDTH));
    }

    /**
     * @return iterable<string, array{Widget}>
     */
    public static function widgetsPreferringAWidth(): iterable
    {
        yield 'Button' => [Button::new('Run', QuitMsg::Quit)];
    }

    /**
     * Relative widths depend on the available area and cannot define a fixed-width bar slot.
     */
    #[Test]
    #[DataProvider('proportionalWidgets')]
    public function aProportionalWidgetTakesAShareOfTheRoom(Widget $widget): void
    {
        self::assertSame(0, self::declaredWidth($widget), 'it has no width of its own');

        $wide = self::inkWidth($widget, self::GENEROUS_WIDTH);

        self::assertGreaterThan(self::inkWidth($widget, self::NARROW_WIDTH), $wide, 'it grows with the room');
        self::assertLessThan(self::GENEROUS_WIDTH, $wide, 'without ever filling it');
    }

    /**
     * @return iterable<string, array{Widget}>
     */
    public static function proportionalWidgets(): iterable
    {
        yield 'Modal' => [Modal::new('title', Text::new('body'))];
        yield 'Header' => [Header::new('title')];
        yield 'FilterableStaticTable' => [FilterableStaticTable::new(['A'], [['1']])];
    }

    /**
     * Fields need a column after the text for the caret; sizing exactly to the text would scroll the first letter away.
     */
    #[Test]
    #[DataProvider('rowTakers')]
    public function aRowTakerDeclaresNothingAndItsInkIgnoresTheRoom(Widget $widget): void
    {
        self::assertSame(0, self::declaredWidth($widget), 'it has no width of its own');
        self::assertSame(
            self::inkWidth($widget, self::NARROW_WIDTH),
            self::inkWidth($widget, self::GENEROUS_WIDTH),
            'and paints the same either way',
        );
    }

    /**
     * @return iterable<string, array{Widget}>
     */
    public static function rowTakers(): iterable
    {
        yield 'TextInput' => [TextInput::new()->withValue('typed')];
        yield 'FormFoot' => [new FormFoot('run it', 7)];
        yield 'FocusGutter' => [FocusGutter::around(TextInput::new()->withValue('typed'))];
        yield 'Table' => [Table\Table::fromCells([Column::new('A', Constraint::flex())], [['x']])];
        yield 'FilterableCheckboxGroup' => [FilterableCheckboxGroup::new(['alpha'])];
        yield 'Footer' => [Footer::new()];
        yield 'ListView' => [ListView::new([new SimpleListItem('alpha')])];
        yield 'TreeView' => [TreeView::new([new SimpleTreeNode('alpha', 'alpha')])];
        yield 'FilterableList' => [FilterableList::new([new SimpleListItem('alpha')])];
        yield 'FocusHints' => [FocusHints::new()];
        yield 'Component' => [Component::bind('field', TextInput::new()->withValue('typed'), static fn (object $app, object $widget): object => $app)];
        yield 'MeasuredComponent' => [Component::bind('list', ListView::new([new SimpleListItem('alpha')]), static fn (object $app, object $widget): object => $app)];
        yield 'Mount' => [Mount::named(
            'measured',
            new MeasuredSubModel(),
            TextInput::new()->withValue('typed'),
            static fn (object $app, object $model): object => $app,
        )];
    }

    /**
     * Fill widgets declare no intrinsic width; verify they actually fill their area rather than hiding a missing declaration.
     */
    #[Test]
    #[DataProvider('fillOnlyWidgets')]
    public function aFillOnlyWidgetDeclaresNothingAndPaintsToTheEdge(Widget $widget): void
    {
        self::assertSame(0, self::declaredWidth($widget), 'it has no width of its own');
        self::assertSame(self::GENEROUS_WIDTH, self::inkWidth($widget, self::GENEROUS_WIDTH));
        self::assertSame(self::NARROW_WIDTH, self::inkWidth($widget, self::NARROW_WIDTH));
    }

    /**
     * @return iterable<string, array{Widget}>
     */
    public static function fillOnlyWidgets(): iterable
    {
        yield 'Separator' => [Separator::new()];
        yield 'FilterableTable' => [FilterableTable::new([Column::new('A', Constraint::flex())], [Table\Row::new('x')])];
        yield 'Panel' => [Panel::new()];
        yield 'Box' => [Box::new()];
        yield 'Card' => [Card::new('title', 'body')];
        yield 'ProgressBar' => [ProgressBar::new()];
        yield 'TabBar' => [TabBar::new()->withTabs([new TabPage('one')])];
        yield 'PositionedOverlay' => [PositionedOverlay::at(0, 0)->withContent(Separator::new())];
        yield 'DropdownPopup' => [DropdownPopup::of(Select::new(['alpha']), new FloatTarget())];
        yield 'Layout' => [Layout::vertical(Separator::new())];
        yield 'Flex' => [Flex::row()->add(Separator::new())];
        yield 'ScrollPane' => [ScrollPane::of(Separator::new())];
        yield 'Overlay' => [Overlay::new(Separator::new(), Separator::new())];
        yield 'Split' => [Split::row(new SplitState([1, 1]), Separator::new(), Separator::new())];
        yield 'Breakpoint' => [Breakpoint::at(10, Separator::new(), Separator::new())];
        yield 'Form' => [Form::new('title')];
    }

    /**
     * Spacers reserve width without painting, so drawn width cannot measure their size.
     */
    #[Test]
    #[DataProvider('spaceReservers')]
    public function aSpaceReserverDeclaresRoomItNeverPaints(Widget $widget): void
    {
        self::assertGreaterThan(0, self::declaredWidth($widget), 'it reserves room');
        self::assertSame(0, self::inkWidth($widget, self::GENEROUS_WIDTH), 'and paints none of it');
    }

    /**
     * @return iterable<string, array{Widget}>
     */
    public static function spaceReservers(): iterable
    {
        yield 'Spacer' => [Spacer::new(4)];
    }

    /**
     * @return iterable<string, array{Widget}>
     */
    public static function contentSizedWidgets(): iterable
    {
        yield 'Text' => [Text::new('a label')];
        yield 'Checkbox' => [Checkbox::new('a label')];
        yield 'StatusBar' => [StatusBar::new('ready')];
        yield 'Spinner' => [Spinner::new('loading')];
        yield 'Select' => [Select::new(['short', 'a much longer option'])];
        yield 'Alert' => [Alert::warning('disk almost full')];
        yield 'Radio' => [Radio::new(['short', 'a much longer option'])];
        yield 'Radio with a label wider than its options' => [Radio::new(['yes', 'no'], 'a considerably longer label')];
        yield 'Paragraph' => [Paragraph::fromText("short\na much longer line")];
        yield 'CheckboxGroup' => [CheckboxGroup::new(['short', 'a much longer option'])];
        yield 'CheckboxRow' => [CheckboxRow::new(['yes', 'no'])];
        yield 'RadioRow' => [RadioRow::new(['yes', 'no'])];
        yield 'ChoiceField' => [ChoiceField::new(['a' => 'Alpha', 'b' => 'Beta'])];
        yield 'Dropdown' => [Dropdown::new(Select::new(['short', 'a much longer option']))];
        yield 'Dropdown showing a selection' => [Dropdown::new(Select::new(['short', 'a much longer option'])->withSelectedIndex(1))];
        yield 'Container' => [Container::around(Text::new('a label'))];
        yield 'Themed' => [Themed::around(Text::new('a label'), static fn (Theme $theme): Theme => $theme)];
        yield 'StaticTable' => [StaticTable::new(['A', 'B'], [['1', '2']])];
        yield 'ActivityBar' => [ActivityBar::new()];
    }

    /**
     * @return list<class-string>
     */
    private static function widgetClasses(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $names = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $name = 'Elmphant\\Tui\\' . str_replace('/', '\\', substr($file->getPathname(), strlen($root) + 1, -4));

            if (!class_exists($name)) {
                continue;
            }

            $class = new ReflectionClass($name);

            if ($class->isAbstract() || !$class->implementsInterface(Widget::class)) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    private static function declaredWidth(Widget $widget): int
    {
        $columns = $widget->sizing()->columns;

        return $columns->basis ?? $columns->min;
    }

    private static function inkWidth(Widget $widget, int $width): int
    {
        $frame = new Frame($width, self::GENEROUS_HEIGHT);
        $widget->render($frame, Rect::fromSize($width, self::GENEROUS_HEIGHT), RenderContext::unfocused(Theme::dark()));

        $widest = 0;

        for ($row = 0; $row < self::GENEROUS_HEIGHT; ++$row) {
            for ($x = $width - 1; $x >= $widest; --$x) {
                $character = $frame->characters[$row * $width + $x];
                if ($character !== ' ' && $character !== '') {
                    $widest = $x + 1;
                    break;
                }
            }
        }

        return $widest;
    }
}

final readonly class MeasuredSubModel implements Reducer
{
    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }
}
