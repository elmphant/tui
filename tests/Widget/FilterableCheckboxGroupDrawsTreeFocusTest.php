<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Filter\FilterableCheckboxGroup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableCheckboxGroupDrawsTreeFocusTest extends TestCase
{
    #[Test]
    public function aGroupTheTreeFocusesLooksLikeOneFocusedByItsOwnFlag(): void
    {
        $group = FilterableCheckboxGroup::new(['one', 'two']);
        $area = new Rect(0, 0, 40, 5);

        $byFlag = new Frame(40, 5);
        $group->withFocus(true)->render($byFlag, $area, new RenderContext(Theme::dark()));

        $byTree = new Frame(40, 5);
        $context = new RenderContext(Theme::dark());
        $context->withFocusHere(static function () use ($group, $byTree, $area, $context): void {
            $group->render($byTree, $area, $context);
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
