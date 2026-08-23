<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PanelFocusScopeTest extends TestCase
{
    /**
     * A focused pane holds the keyboard for one child. The others are inside the
     * same subtree, so they have to be told they do not have it.
     */
    #[Test]
    public function onlyTheActiveChildOfAFocusedPaneShowsTheCaret(): void
    {
        $panel = Panel::new('Form')
            ->withChildren([TextInput::new()->withValue('aa'), TextInput::new()->withValue('bb')])
            ->withFocus(true);

        $frame = new Frame(20, 8);
        $context = new RenderContext(Theme::dark());
        $context->withFocusHere(static function () use ($panel, $frame, $context): void {
            $panel->render($frame, new Rect(0, 0, 20, 8), $context);
        });

        self::assertNotNull($frame->cursorPosition);
        self::assertSame(
            1,
            $frame->cursorPosition->row,
            'The caret belongs to the first child, not to the last one drawn',
        );
    }
}
