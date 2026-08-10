<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\FocusGutter;
use Elmphant\Tui\Widget\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusGutterTest extends TestCase
{
    #[Test]
    public function aFocusedWidgetGetsABarBesideIt(): void
    {
        $frame = new Frame(12, 1);
        $theme = Theme::dark();

        FocusGutter::around(TextInput::new('')->withFocus(true))
            ->render($frame, new Rect(0, 0, 12, 1), new RenderContext($theme));

        self::assertSame(FocusGutter::BAR, $frame->characters[0], 'the bar says where the keys land');
        self::assertSame($theme->focusedGutterStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function theWidgetItHoldsStartsClearOfTheTrack(): void
    {
        $frame = new Frame(20, 1);

        FocusGutter::around(TextInput::new('type here'))
            ->render($frame, new Rect(0, 0, 20, 1), new RenderContext(Theme::dark()));

        self::assertSame('t', $frame->characters[2], 'the field begins past the track, focused or not');
    }

    #[Test]
    public function anIdleWidgetKeepsAHairlineInTheSameColumn(): void
    {
        $frame = new Frame(12, 1);
        $theme = Theme::dark();

        FocusGutter::around(TextInput::new(''))
            ->render($frame, new Rect(0, 0, 12, 1), new RenderContext($theme));

        self::assertSame(FocusGutter::RAIL, $frame->characters[0], 'the column is a track, never a gap');
        self::assertSame($theme->dim->foregroundValue(), $frame->foregrounds[0]);
    }
}
