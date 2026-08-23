<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Api;

use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Mount;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Flex;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\QuitMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Bar\Header;
use Elmphant\Tui\Widget\Bar\StatusBar;
use Elmphant\Tui\Widget\Box;
use Elmphant\Tui\Widget\Button;
use Elmphant\Tui\Widget\Card;
use Elmphant\Tui\Widget\Checkbox;
use Elmphant\Tui\Widget\CheckboxGroup;
use Elmphant\Tui\Widget\CheckboxRow;
use Elmphant\Tui\Widget\Container;
use Elmphant\Tui\Widget\Dispatch\ScrollPane;
use Elmphant\Tui\Widget\Feedback\ActivityBar;
use Elmphant\Tui\Widget\Feedback\Alert;
use Elmphant\Tui\Widget\Feedback\ProgressBar;
use Elmphant\Tui\Widget\Feedback\Spinner;
use Elmphant\Tui\Widget\Filter\FilterableCheckboxGroup;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use Elmphant\Tui\Widget\Focus\FocusGutter;
use Elmphant\Tui\Widget\Focus\FocusHints;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Panel;
use Elmphant\Tui\Widget\Paragraph;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Popup\DropdownPopup;
use Elmphant\Tui\Widget\Popup\FloatTarget;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Popup\PositionedOverlay;
use Elmphant\Tui\Widget\Radio;
use Elmphant\Tui\Widget\RadioRow;
use Elmphant\Tui\Widget\Select;
use Elmphant\Tui\Widget\Separator;
use Elmphant\Tui\Widget\Spacer;
use Elmphant\Tui\Widget\Split;
use Elmphant\Tui\Widget\TabBar;
use Elmphant\Tui\Widget\Table;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\StaticTable;
use Elmphant\Tui\Widget\TabPage;
use Elmphant\Tui\Widget\Text;
use Elmphant\Tui\Widget\TextInput;
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
 * A bar slot is placed at the width its content declares, so a widget that
 * paints its own ink has to name that width. Panes and fill-only widgets have
 * none to name — they paint whatever they are given — and saying so is the
 * point: the declaration is checked against what the widget actually draws.
 */
final class IntrinsicWidthTest extends TestCase
{
    private const GENEROUS_WIDTH = 60;
    private const GENEROUS_HEIGHT = 12;
    private const NARROW_WIDTH = 17;

    /**
     * The classification is only worth anything if it is exhaustive: an
     * unclassified widget reports no width and would silently vanish from a bar
     * slot. So the lists have to cover every widget in src, and a new one fails
     * here until somebody decides which kind it is.
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
     * A width it would rather have, and decoration that stretches to whatever it
     * is actually given — a Button's border reaches the edge however wide the
     * area is. So its ink is not its content, and a slot placing it reads the
     * declaration instead.
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
     * A share of the room rather than all of it or a fixed amount of it, so it
     * grows with the area without ever reaching the edge. A slot cannot place one
     * by width: there is no width until the area is known.
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
        yield 'FilterableTable' => [FilterableTable::new(['A'], [['1']])];
    }

    /**
     * A field takes the row it is given and writes into part of it. Sizing one to
     * its own text is not an option: the caret sits a column past the last
     * character, so a field exactly as wide as its content scrolls by one and
     * hides its own first letter. Its ink therefore does not grow with the room,
     * which is what separates it from a widget that fills.
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
        yield 'FocusGutter' => [FocusGutter::around(TextInput::new()->withValue('typed'))];
        yield 'Table' => [Table\Table::fromCells([Column::new('A', Constraint::flex())], [['x']])];
        yield 'FilterableCheckboxGroup' => [FilterableCheckboxGroup::new(['alpha'])];
        yield 'Footer' => [Footer::new()];
        yield 'ListView' => [ListView::new([new SimpleListItem('alpha')])];
        yield 'TreeView' => [TreeView::new([new SimpleTreeNode('alpha', 'alpha')])];
        yield 'FilterableList' => [FilterableList::new([new SimpleListItem('alpha')])];
        yield 'FocusHints' => [FocusHints::new()];
        yield 'Component' => [Component::bind('field', TextInput::new()->withValue('typed'), static fn (object $app, object $widget): object => $app)];
        yield 'Mount' => [Mount::of(
            new MeasuredSubModel(),
            TextInput::new()->withValue('typed'),
            static fn (object $app, object $model): object => $app,
        )];
    }

    /**
     * A widget with no width of its own paints whatever it is given, so it
     * declares nothing — and has to prove it fills, or the category becomes a
     * home for widgets nobody classified. Placed in a bar slot it is not drawn:
     * a slot is as wide as its content asked to be, and this asked for nothing.
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
    }

    /**
     * The third kind, and the reason the rule is not simply "ink equals width":
     * a spacer's whole job is to hold columns open without drawing in them, so
     * it declares a width it never paints. Placed in a bar slot it would reserve
     * blank room, which is what asking for one means.
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
        yield 'Spacer' => [Spacer::of(4)];
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
        yield 'Dropdown' => [Dropdown::new(Select::new(['short', 'a much longer option']))];
        yield 'Dropdown showing a selection' => [Dropdown::new(Select::new(['short', 'a much longer option'])->withSelected(1))];
        yield 'Container' => [Container::around(Text::new('a label'))];
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

    /**
     * The widest row it actually paints. Rows, not one row: an option list is
     * as wide as its longest entry, which is not necessarily the first.
     */
    private static function inkWidth(Widget $widget, int $width): int
    {
        $frame = new Frame($width, self::GENEROUS_HEIGHT);
        $context = new RenderContext(Theme::dark());
        $context->focusOn(FocusState::nowhere());
        $widget->render($frame, Rect::fromSize($width, self::GENEROUS_HEIGHT), $context);

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
