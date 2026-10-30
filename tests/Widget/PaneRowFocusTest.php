<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\FocusGutter;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A focus gutter adds no tab stop, but must pass the pane's focus to its wrapped field.
 */
final class PaneRowFocusTest extends TestCase
{
    #[Test]
    public function theFieldOnTheHeldRowShowsItsCursor(): void
    {
        $frame = new Frame(30, 6);
        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->focusOn(new FocusState(ComponentId::named('pane')));

        (new HeldRowApp())->render($frame, $pass);

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
