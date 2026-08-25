<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Input\ColorProfile;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Field\TextInput;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RenderContextTest extends TestCase
{
    #[Test]
    public function aContextCarriesTheColourDepthItRendersFor(): void
    {
        $context = new RenderContext(Theme::dark(), ColorProfile::Ansi);

        self::assertSame(ColorProfile::Ansi, $context->colorProfile);
    }

    #[Test]
    public function aContextAssumesTrueColourUntilToldOtherwise(): void
    {
        $context = new RenderContext(Theme::dark());

        self::assertSame(ColorProfile::TrueColor, $context->colorProfile);
    }

    #[Test]
    public function frameTimeDefaultsToZero(): void
    {
        $context = new RenderContext(Theme::dark());

        self::assertSame(0.0, $context->now());
    }

    #[Test]
    public function beginFrameSetsTheFrameTime(): void
    {
        $context = new RenderContext(Theme::dark());

        $context->beginFrame(2.5);

        self::assertSame(2.5, $context->now());
    }

    #[Test]
    public function repaintDelayIsNullWithoutRequests(): void
    {
        $context = new RenderContext(Theme::dark());

        self::assertNull($context->repaintDelay());
    }

    #[Test]
    public function requestRepaintRecordsTheDelay(): void
    {
        $context = new RenderContext(Theme::dark());

        $context->requestRepaint(0.08);

        self::assertSame(0.08, $context->repaintDelay());
    }

    #[Test]
    public function requestRepaintKeepsTheSmallestDelay(): void
    {
        $context = new RenderContext(Theme::dark());

        $context->requestRepaint(0.08);
        $context->requestRepaint(0.05);
        $context->requestRepaint(0.2);

        self::assertSame(0.05, $context->repaintDelay());
    }

    /**
     * A delay is turned into the moment the next frame is due, and a moment
     * nothing can compare stops the animation clock for the whole program —
     * not just for the widget that asked.
     */
    #[Test]
    public function requestRepaintIgnoresADelayThatNamesNoMoment(): void
    {
        $context = new RenderContext(Theme::dark());

        $context->requestRepaint(NAN);

        self::assertNull($context->repaintDelay(), 'a frame due at no moment is a frame that never comes');
    }

    #[Test]
    public function requestRepaintClampsNegativeDelaysToZero(): void
    {
        $context = new RenderContext(Theme::dark());

        $context->requestRepaint(-1.0);

        self::assertSame(0.0, $context->repaintDelay());
    }

    #[Test]
    public function beginFrameClearsPendingRepaintRequests(): void
    {
        $context = new RenderContext(Theme::dark());
        $context->requestRepaint(0.08);

        $context->beginFrame(1.0);

        self::assertNull($context->repaintDelay());
    }

    /**
     * A tree of components drawn with nothing said about the keyboard is a frame no
     * terminal shows: every field unfocused, and a popup belonging to the focused one
     * missing. Nowhere is an answer; saying nothing is not.
     */
    #[Test]
    public function drawingAComponentNeedsAFocusDecision(): void
    {
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/focusOn/');

        $context->renderRoot(self::boundField(), new Frame(20, 3), new Rect(0, 0, 20, 3));
    }

    /**
     * The runtime hands over what it has. Where the tree offers nothing to focus —
     * a modal with no field in it, a pane still being built — that is nobody rather
     * than nothing said, and the frame still has to draw.
     */
    #[Test]
    public function aFocusStateStillWaitingForAnAnswerDrawsAsNobody(): void
    {
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->focusOn(new FocusState());

        $context->renderRoot(self::boundField(), new Frame(20, 3), new Rect(0, 0, 20, 3));

        self::assertTrue(true, 'drawing got that far without asking for a decision twice');
    }

    /**
     * A layout or wording test wants a tree drawn with nobody at the keyboard, and it
     * has to be sayable in one breath — including across the frame reset.
     */
    #[Test]
    public function sayingNobodyHoldsTheKeyboardIsEnoughToDrawWith(): void
    {
        $context = RenderContext::unfocused(Theme::dark());
        $context->beginFrame(0.0);

        $frame = new Frame(20, 3);
        $context->renderRoot(self::boundField(), $frame, new Rect(0, 0, 20, 3));

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
