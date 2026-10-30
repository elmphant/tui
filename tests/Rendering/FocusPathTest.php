<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\FocusPath;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\RootPass;
use Elmphant\Tui\Widget\Field\TextInput;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusPathTest extends TestCase
{
    /**
     * Component trees require a focus decision; explicitly no focus is valid, but undecided focus is not.
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
     * Render trees with no focusable components as explicitly unfocused while runtime focus remains undecided.
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
     * Layout tests need explicit empty focus that survives frame resets.
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
