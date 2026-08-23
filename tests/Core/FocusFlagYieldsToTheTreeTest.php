<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Field\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Once the tree has said where the keyboard is, a flag left on some other widget
 * must not paint a second one — that is the whole defect this plan exists for.
 */
final class FocusFlagYieldsToTheTreeTest extends TestCase
{
    #[Test]
    public function aBoundComponentsStrayFlagPaintsNoFocusTheTreeDidNotGive(): void
    {
        $field = TextInput::new()->withValue('abc');
        $area = new Rect(0, 0, 24, 1);

        $blurred = new Frame(24, 1);
        $field->render($blurred, $area, new RenderContext(Theme::dark()));

        $routed = new RenderContext(Theme::dark());
        $routed->focusOn(new FocusState(ComponentId::named('elsewhere')));

        $stray = new Frame(24, 1);
        Component::bind(
            'field',
            $field->withFocus(true),
            static fn (TextInput $host, TextInput $widget): TextInput => $widget,
        )->render($stray, $area, $routed);

        self::assertSame(self::snapshot($blurred), self::snapshot($stray));
    }

    /**
     * Program installs the focus every frame, so an app that publishes no
     * components installs an empty one. That is no answer to defer to, and its own
     * flags are all it has.
     */
    #[Test]
    public function aFlagStillPaintsWhereNothingWasRouted(): void
    {
        $field = TextInput::new()->withValue('abc')->withFocus(true);
        $area = new Rect(0, 0, 24, 1);

        $alone = new Frame(24, 1);
        $field->render($alone, $area, new RenderContext(Theme::dark()));

        $routedNothing = new RenderContext(Theme::dark());
        $routedNothing->focusOn(new FocusState());

        $installed = new Frame(24, 1);
        $field->render($installed, $area, $routedNothing);

        self::assertSame(self::snapshot($alone), self::snapshot($installed));
    }

    private static function snapshot(Frame $frame): string
    {
        return implode('|', $frame->characters)
            . '#' . implode('|', $frame->foregrounds)
            . '#' . implode('|', $frame->backgrounds)
            . '#' . implode('|', $frame->attributes);
    }
}
