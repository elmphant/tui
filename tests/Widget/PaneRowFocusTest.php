<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focus\FocusGutter;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A gutter is deliberately not a focus stop, so a pane cycling its children skips
 * it. It still says which of them the keyboard is on, and the field inside that
 * one has to read the pane's keyboard as its own.
 */
final class PaneRowFocusTest extends TestCase
{
    #[Test]
    public function theFieldOnTheHeldRowShowsItsCursor(): void
    {
        $frame = new Frame(30, 6);
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->focusOn(new FocusState(ComponentId::named('pane')));

        (new HeldRowApp())->render($frame, $context);

        self::assertNotNull($frame->cursorPosition, 'the field the gutter marks is the one being typed in');
    }
}

final readonly class HeldRowApp extends App
{
    public function view(): Widget
    {
        return Component::named(
            'pane',
            Panel::new('Pane')->withChildren([
                FocusGutter::beside(Text::new('idle'), false),
                FocusGutter::beside(TextInput::new()->withValue('typed'), true),
            ]),
        );
    }
}
