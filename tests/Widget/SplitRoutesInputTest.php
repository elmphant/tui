<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Structure\Split;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SplitRoutesInputTest extends TestCase
{
    #[Test]
    public function aKeyReachesTheMarkedPaneAndNoOther(): void
    {
        $area = new Rect(0, 0, 40, 6);
        $split = Split::row(
            SplitState::even(2),
            TextInput::new(),
            TextInput::new()->withFocus(true),
        );

        $typed = $split->update(new KeyMsg('x'), $area)->model;

        self::assertInstanceOf(Split::class, $typed);
        self::assertSame(['', 'x'], self::fieldValues($typed, $area), 'the second pane is the marked one');
    }

    /**
     * @return list<string>
     */
    private static function fieldValues(Split $split, Rect $area): array
    {
        $values = [];

        foreach ($split->placedChildren($area) as [$child]) {
            if ($child instanceof TextInput) {
                $values[] = $child->value();
            }
        }

        return $values;
    }
}
