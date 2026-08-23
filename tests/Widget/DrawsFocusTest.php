<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Widget\Button;
use Elmphant\Tui\Widget\Checkbox;
use Elmphant\Tui\Widget\CheckboxGroup;
use Elmphant\Tui\Widget\CheckboxRow;
use Elmphant\Tui\Widget\Column;
use Elmphant\Tui\Widget\Dispatch\ScrollPane;
use Elmphant\Tui\Widget\Dropdown;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Panel;
use Elmphant\Tui\Widget\Radio;
use Elmphant\Tui\Widget\RadioRow;
use Elmphant\Tui\Widget\Select;
use Elmphant\Tui\Widget\TabBar;
use Elmphant\Tui\Widget\Table;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every widget that can take the keyboard has to show it from what the tree
 * says, so the highlight cannot contradict where the keys are routed.
 */
final class DrawsFocusTest extends TestCase
{
    #[Test]
    #[DataProvider('focusableWidgets')]
    public function aWidgetTheTreeFocusesDrawsDifferentlyFromOneItDoesNot(Widget $widget): void
    {
        $area = new Rect(0, 0, 24, 6);

        $unfocused = new Frame(24, 6);
        $widget->render($unfocused, $area, new RenderContext(Theme::dark()));

        $focused = new Frame(24, 6);
        $context = new RenderContext(Theme::dark());
        $context->withFocusHere(static function () use ($widget, $focused, $area, $context): void {
            $widget->render($focused, $area, $context);
        });

        self::assertNotSame(
            self::snapshot($unfocused),
            self::snapshot($focused),
            'A widget the tree focused must look different from one it did not',
        );
    }

    /**
     * @return iterable<string, array{Widget}>
     */
    public static function focusableWidgets(): iterable
    {
        yield 'TextInput' => [TextInput::new()->withValue('abc')];
        yield 'Checkbox' => [Checkbox::new('agree')];
        yield 'Button' => [Button::new('ok', new TestMessage('pressed'))];
        yield 'Radio' => [Radio::new(['one', 'two'])];
        yield 'Select' => [Select::new(['one', 'two'])];
        yield 'CheckboxGroup' => [CheckboxGroup::new(['one', 'two'])];
        yield 'ListView' => [ListView::new([new SimpleListItem('one'), new SimpleListItem('two')])];
        yield 'Table' => [Table::fromCells([Column::new('A', Constraint::exact(8))], [['one'], ['two']])];
        yield 'Dropdown' => [Dropdown::new(Select::new(['one', 'two']))];
        yield 'Panel' => [Panel::new('Title')];
        yield 'TabBar' => [TabBar::new()->addTab('One')->addTab('Two')];
        yield 'ScrollPane' => [ScrollPane::of(TextInput::new()->withValue('abc'))];
        yield 'CheckboxRow' => [CheckboxRow::new(['one', 'two'])];
        yield 'RadioRow' => [RadioRow::new(['one', 'two'])];
    }

    private static function snapshot(Frame $frame): string
    {
        return implode('|', $frame->characters)
            . '#' . implode('|', $frame->foregrounds)
            . '#' . implode('|', $frame->backgrounds);
    }
}
