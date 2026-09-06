<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Filter\FilterableCheckboxGroup;
use Elmphant\Tui\Widget\Popup\Dropdown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableCheckboxGroupInDropdownTest extends TestCase
{
    #[Test]
    public function aDropdownHoldsItAndReadsTheClosedRowFromIt(): void
    {
        $dropdown = Dropdown::new(
            FilterableCheckboxGroup::new(['app.log', 'deprecations', 'sync_log', 'sync_trail']),
        );
        $area = Rect::fromSize(40, 12);

        $ticked = $dropdown;
        foreach ([new KeyMsg(Key::Enter), new KeyMsg('d'), new KeyMsg(' ')] as $key) {
            $ticked = $ticked->update($key, $area)->model;
        }

        self::assertTrue($ticked->isOpen(), 'ticking a box is not choosing, so the list stays open');
        self::assertStringContainsString(
            '1 of 4',
            $ticked->caption(),
            'and the row a host draws when it is shut reads the count off the boxes',
        );
    }
}
