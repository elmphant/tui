<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\FilterableList;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableListDrawsTreeFocusTest extends TestCase
{
    #[Test]
    public function aListTheTreeFocusesLooksLikeOneFocusedByItsOwnFlag(): void
    {
        $list = FilterableList::new([new SimpleListItem('one'), new SimpleListItem('two')]);
        $area = new Rect(0, 0, 24, 5);

        $byFlag = new Frame(24, 5);
        $list->withFocus(true)->render($byFlag, $area, new RenderContext(Theme::dark()));

        $byTree = new Frame(24, 5);
        $context = new RenderContext(Theme::dark());
        $context->withFocusHere(static function () use ($list, $byTree, $area, $context): void {
            $list->render($byTree, $area, $context);
        });

        self::assertSame(self::snapshot($byFlag), self::snapshot($byTree));
    }

    private static function snapshot(Frame $frame): string
    {
        return implode('|', $frame->characters)
            . '#' . implode('|', $frame->foregrounds)
            . '#' . implode('|', $frame->backgrounds)
            . '#' . implode('|', $frame->attributes);
    }
}
