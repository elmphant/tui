<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\FocusPath;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\RootPass;
use Elmphant\Tui\Widget\Field\TextInput;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusPathTest extends TestCase
{
    /**
     * A tree of components drawn with nothing said about the keyboard is a frame no
     * terminal shows: every field unfocused, and a popup belonging to the focused one
     * missing. Nowhere is an answer; saying nothing is not.
     */
    #[Test]
    public function drawingAComponentNeedsAFocusDecision(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/focusOn/');

        RootPass::draw($pass, self::boundField(), new Frame(20, 3), new Rect(0, 0, 20, 3));
    }

    /**
     * The runtime hands over what it has. Where the tree offers nothing to focus —
     * a modal with no field in it, a pane still being built — that is nobody rather
     * than nothing said, and the frame still has to draw.
     */
    #[Test]
    public function aFocusStateStillWaitingForAnAnswerDrawsAsNobody(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->focusOn(new FocusState());

        RootPass::draw($pass, self::boundField(), new Frame(20, 3), new Rect(0, 0, 20, 3));

        self::assertTrue(true, 'drawing got that far without asking for a decision twice');
    }

    /**
     * A layout or wording test wants a tree drawn with nobody at the keyboard, and it
     * has to be sayable in one breath — including across the frame reset.
     */
    #[Test]
    public function sayingNobodyHoldsTheKeyboardIsEnoughToDrawWith(): void
    {
        $pass = RenderPass::new(Theme::dark(), focus: FocusPath::nowhere());
        $pass->begin(0.0);

        $frame = new Frame(20, 3);
        RootPass::draw($pass, self::boundField(), $frame, new Rect(0, 0, 20, 3));

        self::assertNotSame('', implode('', $frame->characters));
    }

    /**
     * @return Component<TextInput>
     */
    private static function boundField(): Component
    {
        return Component::bind(
            'field',
            TextInput::new()->withValue('typed'),
            static fn (object $model, TextInput $field): object => $model,
        );
    }
}
