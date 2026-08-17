<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Once the tree has said where the keyboard is, a flag left on some other widget
 * must not paint a second one — that is the whole defect this plan exists for.
 */
final class FocusFlagYieldsToTheTreeTest extends TestCase
{
    #[Test]
    public function aStrayFlagPaintsNoFocusTheTreeDidNotGive(): void
    {
        $field = TextInput::new()->withValue('abc');
        $area = new Rect(0, 0, 24, 1);

        $blurred = new Frame(24, 1);
        $field->render($blurred, $area, new RenderContext(Theme::dark()));

        $routed = new RenderContext(Theme::dark());
        $routed->focusOn(new FocusState(ComponentId::named('elsewhere')));

        $stray = new Frame(24, 1);
        $field->withFocus(true)->render($stray, $area, $routed);

        self::assertSame(self::snapshot($blurred), self::snapshot($stray));
    }

    private static function snapshot(Frame $frame): string
    {
        return implode('|', $frame->characters)
            . '#' . implode('|', $frame->foregrounds)
            . '#' . implode('|', $frame->backgrounds)
            . '#' . implode('|', $frame->attributes);
    }
}
