<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\FocusMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Widget\Dispatch\ScrollPane;
use Elmphant\Tui\Widget\Field\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusArrivesOnceTest extends TestCase
{
    #[Test]
    public function aPaneToldTheKeyboardArrivedSeatsItsCursorOnItsFirstChild(): void
    {
        $pane = ScrollPane::of(TextInput::new(), TextInput::new())->withFocusAt(1);

        $arrived = self::deliver($pane, new FocusMsg(true));

        self::assertSame(['x', ''], self::fieldValues(self::deliver($arrived, new KeyMsg('x'))));
    }

    private static function deliver(ScrollPane $pane, Message $message): ScrollPane
    {
        $updated = $pane->update($message, Rect::fromSize(40, 10))->model;
        self::assertInstanceOf(ScrollPane::class, $updated);

        return $updated;
    }

    /**
     * @return list<string>
     */
    private static function fieldValues(ScrollPane $pane): array
    {
        $values = [];

        foreach ($pane->authoredChildren() as $child) {
            if ($child instanceof TextInput) {
                $values[] = $child->value();
            }
        }

        return $values;
    }
}
