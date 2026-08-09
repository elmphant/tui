<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\FilterableCheckboxGroup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableCheckboxGroupPopupTest extends TestCase
{
    #[Test]
    public function itAsksForRoomForItsFieldTheGapAndTheBoxesTheQueryLeft(): void
    {
        $group = FilterableCheckboxGroup::new(['app.log', 'deprecations', 'sync_log', 'sync_trail'])
            ->withFocus(true);
        $area = Rect::fromSize(30, 8);

        self::assertSame(2 + 4, $group->popupHeight(30), 'the field, its gap, and a row per box');
        self::assertSame(mb_strlen('deprecations') + 4, $group->popupWidth(), 'every option is drawn behind a box');

        $narrowed = $group->update(new KeyMsg('d'), $area)->model;
        self::assertInstanceOf(FilterableCheckboxGroup::class, $narrowed);

        self::assertSame(2 + 1, $narrowed->popupHeight(30), 'a query the popup does not shrink for leaves it half empty');
        self::assertSame(
            mb_strlen('deprecations') + 4,
            $narrowed->popupWidth(),
            'the width holds still, or the popup would jump about under the reader as they type',
        );
    }
}
