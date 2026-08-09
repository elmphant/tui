<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\FilterableCheckboxGroup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableCheckboxGroupCaptionTest extends TestCase
{
    #[Test]
    public function theClosedRowCountsEveryTickAndNotOnlyTheOnesTheQueryLeftShowing(): void
    {
        $group = FilterableCheckboxGroup::new(['app.log', 'deprecations', 'sync_log', 'sync_trail'])
            ->withFocus(true);
        $area = Rect::fromSize(30, 8);

        self::assertSame('0 of 4', $group->caption());
        self::assertFalse($group->hasChoice(), 'nothing ticked is a choice the host has still to be told about');

        $ticked = $group->update(new KeyMsg('d'), $area)->model->update(new KeyMsg(' '), $area)->model;
        self::assertInstanceOf(FilterableCheckboxGroup::class, $ticked);

        self::assertSame('1 of 4', $ticked->caption(), 'the count is of the whole list, not of what the query shows');
        self::assertTrue($ticked->hasChoice());
    }
}
