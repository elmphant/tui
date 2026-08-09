<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Widget\FilterableCheckboxGroup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableCheckboxGroupTest extends TestCase
{
    #[Test]
    public function aTickOutlivesTheQueryThatWasNarrowingTheBoxesWhenItWasMade(): void
    {
        $group = FilterableCheckboxGroup::new(['app.log', 'deprecations', 'sync_log', 'sync_trail'])
            ->withFocus(true);

        $ticked = self::pressed(
            $group,
            new KeyMsg('s'),
            new KeyMsg('y'),
            new KeyMsg('n'),
            new KeyMsg(' '),
            new KeyMsg('u', ctrl: true),
        );

        self::assertSame(
            ['sync_log'],
            $ticked->checkedValues(),
            'a box is ticked by name, so clearing the query cannot hand the tick to whoever holds that row now',
        );
    }

    private static function pressed(FilterableCheckboxGroup $group, Message ...$messages): FilterableCheckboxGroup
    {
        $area = Rect::fromSize(30, 8);

        foreach ($messages as $message) {
            $updated = $group->update($message, $area)->model;
            self::assertInstanceOf(FilterableCheckboxGroup::class, $updated);
            $group = $updated;
        }

        return $group;
    }
}
