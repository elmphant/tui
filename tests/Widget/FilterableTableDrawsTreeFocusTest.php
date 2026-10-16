<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilterableTableDrawsTreeFocusTest extends TestCase
{
    #[Test]
    public function aTableTheTreeFocusesUnderlinesItsField(): void
    {
        $table = FilterableTable::new(['Name'], [['one'], ['two']]);
        $area = new Rect(0, 0, 40, 6);

        $focused = new Frame(40, 6);
        $table->render($focused, $area, RenderContext::focused(Theme::dark()));

        $plain = new Frame(40, 6);
        $table->render($plain, $area, RenderContext::unfocused(Theme::dark()));

        self::assertTrue(self::underlinesAnything($focused), 'the field is underlined where the keyboard is');
        self::assertFalse(self::underlinesAnything($plain), 'and plain where it is not');
    }

    private static function underlinesAnything(Frame $frame): bool
    {
        foreach ($frame->attributes as $attributes) {
            if (($attributes & Attr::UNDERLINE) !== 0) {
                return true;
            }
        }

        return false;
    }
}
