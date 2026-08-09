<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Widget\FilterableCheckboxGroup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableCheckboxGroupPointerTest extends TestCase
{
    #[Test]
    public function aPressNamesTheBoxDrawnOnThatRowOfTheNarrowedList(): void
    {
        $group = FilterableCheckboxGroup::new(['app.log', 'deprecations', 'sync_log', 'sync_trail'])
            ->withFocus(true);
        $area = Rect::fromSize(30, 8);

        $narrowed = $group;
        foreach ([new KeyMsg('s'), new KeyMsg('y'), new KeyMsg('n')] as $key) {
            $narrowed = $narrowed->update($key, $area)->model;
        }
        self::assertInstanceOf(FilterableCheckboxGroup::class, $narrowed);

        $onTheSecondBox = new MouseMsg(0, $area->y + 3, MouseAction::Press, MouseButton::Left);

        self::assertSame(
            'sync_trail',
            $narrowed->optionAt($onTheSecondBox, $area),
            'the query moved sync_trail up a row, and a press answers against the rows on the screen',
        );
        self::assertTrue($narrowed->hasOption('app.log'), 'an option the query is hiding is still one it offers');
        self::assertSame(['sync_trail'], $narrowed->activated('sync_trail')->checkedValues());
    }
}
