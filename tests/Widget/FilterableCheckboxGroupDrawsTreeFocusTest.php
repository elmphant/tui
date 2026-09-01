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
    private const WIDTH = 40;

    private const CURSOR_ROW = 2;

    #[Test]
    public function aGroupTheTreeFocusesFillsTheRowItsCursorIsOn(): void
    {
        $group = FilterableCheckboxGroup::new(['one', 'two']);
        $area = new Rect(0, 0, self::WIDTH, 5);

        $focused = new Frame(self::WIDTH, 5);
        $group->render($focused, $area, RenderContext::focused(Theme::dark()));

        $plain = new Frame(self::WIDTH, 5);
        $group->render($plain, $area, RenderContext::unfocused(Theme::dark()));

        self::assertNotSame(0, $focused->backgrounds[self::CURSOR_ROW * self::WIDTH], 'the cursor row is filled where the keyboard is');
        self::assertSame(0, $plain->backgrounds[self::CURSOR_ROW * self::WIDTH], 'and flat where it is not');
    }
}
