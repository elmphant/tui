<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Filter\FilterableCheckboxGroup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableCheckboxGroupRenderTest extends TestCase
{
    #[Test]
    public function drawsTheFieldAboveOnlyTheBoxesTheQueryLeft(): void
    {
        $group = FilterableCheckboxGroup::new(['app.log', 'deprecations', 'sync_log', 'sync_trail']);
        $area = Rect::fromSize(30, 8);

        $narrowed = $group->update(new KeyMsg('d'), $area)->model;
        self::assertInstanceOf(FilterableCheckboxGroup::class, $narrowed);

        $rows = self::rowsOf($narrowed, $area);

        self::assertStringContainsString('d', $rows[0], 'the query is on the top row, where it was typed');
        self::assertStringContainsString('[ ] deprecations', $rows[2], 'a blank row, then what the query kept');
        self::assertStringNotContainsString('sync_log', implode("\n", $rows), 'and nothing it did not');
    }

    /**
     * @return list<string>
     */
    private static function rowsOf(FilterableCheckboxGroup $group, Rect $area): array
    {
        $frame = new Frame($area->width, $area->height);
        $group->render($frame, $area, new RenderContext(Theme::dark()));

        return explode("\n", ScreenBuffer::fromFrame($frame)->plainText());
    }
}
