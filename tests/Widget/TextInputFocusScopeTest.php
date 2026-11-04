<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Field\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextInputFocusScopeTest extends TestCase
{
    /**
     * Draw the caret from tree focus, never from a stale field flag.
     */
    #[Test]
    public function aFieldDrawsItsCaretBecauseTheTreeSaysItHasTheKeyboard(): void
    {
        $frame = new Frame(12, 1);
        $context = new RenderContext(Theme::dark());
        $field = TextInput::new()->withValue('abc');

        $context->focusHere(function () use ($field, $frame, $context): void {
            $field->render($frame, new Rect(0, 0, 12, 1), $context);
        });

        self::assertNotNull($frame->cursorPosition(), 'The field the tree focused must place the caret');
        self::assertSame(3, $frame->cursorPosition()->x);
    }
}
