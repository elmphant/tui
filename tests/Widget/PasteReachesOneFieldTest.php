<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Widget\Dispatch\ScrollPane;
use Elmphant\Tui\Widget\Field\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PasteReachesOneFieldTest extends TestCase
{
    #[Test]
    public function aPasteLandsInTheFieldTheKeyboardIsOnAndNowhereElse(): void
    {
        $pane = ScrollPane::of(TextInput::new(), TextInput::new())->withFocusAt(1);

        $pasted = $pane->update(new PasteMsg('hi'), Rect::fromSize(40, 10))->model;

        self::assertInstanceOf(ScrollPane::class, $pasted);
        self::assertSame(['', 'hi'], self::fieldValues($pasted));
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
