<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableTableDrawsTreeFocusTest extends TestCase
{
    #[Test]
    public function aTableTheTreeFocusesLooksLikeOneFocusedByItsOwnFlag(): void
    {
        $table = FilterableTable::new(['Name'], [['one'], ['two']]);
        $area = new Rect(0, 0, 40, 6);

        $byFlag = new Frame(40, 6);
        $table->withFocus(true)->render($byFlag, $area, new RenderContext(Theme::dark()));

        $byTree = new Frame(40, 6);
        $context = new RenderContext(Theme::dark());
        $context->withFocusHere(static function () use ($table, $byTree, $area, $context): void {
            $table->render($byTree, $area, $context);
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
