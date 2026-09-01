<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\FocusMsg;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\WidgetContainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusArrivalTest extends TestCase
{
    private const AREA_WIDTH = 40;

    private const AREA_HEIGHT = 10;

    #[Test]
    public function aPanelToldTheKeyboardArrivedSeatsItsCursorOnItsFirstChild(): void
    {
        $panel = Panel::new('Form')->add(TextInput::new())->add(TextInput::new())->withFocus(true);
        $moved = self::deliver($panel, new KeyMsg(Key::Tab));
        self::assertSame(['', 'x'], self::fieldValues(self::deliver($moved, new KeyMsg('x'))), 'the cursor left the first child');

        $arrived = self::deliver($moved, new FocusMsg(true));

        self::assertSame(['x', ''], self::fieldValues(self::deliver($arrived, new KeyMsg('x'))));
    }

    #[Test]
    public function aBarToldTheKeyboardArrivedSeatsItsCursorOnItsFirstChild(): void
    {
        $bar = TabBar::new()->addTab('One')->add(TextInput::new())->add(TextInput::new())->withFocus(true);
        $moved = self::deliver($bar, new KeyMsg(Key::Tab));
        self::assertSame(['', 'x'], self::fieldValues(self::deliver($moved, new KeyMsg('x'))), 'the cursor left the first child');

        $arrived = self::deliver($moved, new FocusMsg(true));

        self::assertSame(['x', ''], self::fieldValues(self::deliver($arrived, new KeyMsg('x'))));
    }

    private static function deliver(Interactive $widget, Message $message): Interactive
    {
        $updated = $widget->update($message, self::area())->model;
        self::assertInstanceOf($widget::class, $updated);

        return $updated;
    }

    /**
     * @return list<string>
     */
    private static function fieldValues(Interactive $widget): array
    {
        self::assertInstanceOf(WidgetContainer::class, $widget);
        $values = [];

        foreach ($widget->children() as $child) {
            if ($child instanceof TextInput) {
                $values[] = $child->value();
            }
        }

        return $values;
    }

    private static function area(): Rect
    {
        return Rect::fromSize(self::AREA_WIDTH, self::AREA_HEIGHT);
    }
}
