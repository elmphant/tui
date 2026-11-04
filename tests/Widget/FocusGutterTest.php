<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Widget\Field\Button;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Box;
use Elmphant\Tui\Widget\Structure\FocusGutter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusGutterTest extends TestCase
{
    #[Test]
    public function itExposesWhatItWrapsBesideTheTrack(): void
    {
        $child = Text::new('field');
        $gutter = FocusGutter::beside($child, held: false);

        $placements = iterator_to_array($gutter->placedChildren(new Rect(0, 0, 12, 2)));

        self::assertCount(1, $placements);
        self::assertSame($child, $placements[0][0]);
        self::assertTrue((new Rect(2, 0, 10, 2))->equals($placements[0][1]));
    }

    #[Test]
    public function aBoxGivesAButtonBesideTheTrackEveryRowItsBorderNeeds(): void
    {
        $box = Box::new()->withChildren([FocusGutter::around(Button::new('OK', new TestMessage('ok')))]);

        self::assertSame(5, $box->measureHeight(20), 'three rows of button, and the border above and below them');
    }

    #[Test]
    public function aFocusedWidgetGetsABarBesideIt(): void
    {
        $frame = new Frame(12, 1);
        $theme = Theme::dark();

        FocusGutter::around(TextInput::new())
            ->render($frame, new Rect(0, 0, 12, 1), RenderContext::focused($theme));

        self::assertSame(FocusGutter::BAR, $frame->characters[0], 'the bar says where the keys land');
        self::assertSame($theme->focusedBorderStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function aBlockIsMarkedOnEveryRowWithoutBeingFocusableItself(): void
    {
        $frame = new Frame(20, 2);
        $theme = Theme::dark();

        FocusGutter::beside(Text::new("label\nfield"), held: true)
            ->render($frame, new Rect(0, 0, 20, 2), new RenderContext($theme));

        self::assertSame(FocusGutter::BAR, $frame->characters[0], 'the container knows, so the container says');
        self::assertSame(FocusGutter::BAR, $frame->characters[20], 'and the track runs the height of the block');
    }

    #[Test]
    public function aKeyTheApplicationBoundGloballyStillReachesTheFieldBehindTheTrack(): void
    {
        $field = FocusGutter::around(TextInput::new()->withValue('typed'))
            ->withGlobalKeybindings(new Keybindings([Actions::CLEAR_LINE => ['ctrl+k']]))
            ->content();
        self::assertInstanceOf(TextInput::class, $field);

        $cleared = $field->update(new KeyMsg('k', ctrl: true), new Rect(0, 0, 20, 1))->model;

        self::assertInstanceOf(TextInput::class, $cleared);
        self::assertSame('', $cleared->value(), 'the track must not swallow what the application bound');
    }

    #[Test]
    public function whatAReaderCanPressIsWhatTheFieldBinds(): void
    {
        $gutter = FocusGutter::around(TextInput::new())
            ->withGlobalKeybindings(new Keybindings([Actions::CLEAR_LINE => ['ctrl+k']]));

        $bindings = $gutter->effectiveKeybindings();

        self::assertTrue(
            $bindings->matches(new KeyMsg('k', ctrl: true), Actions::CLEAR_LINE),
            'the track answers with the field it runs beside, never with keys of its own',
        );
        self::assertTrue(
            $bindings->matches(new KeyMsg('u', ctrl: true), Actions::CLEAR_LINE),
            'and what the field bound itself is still there',
        );
    }

    #[Test]
    public function aHostRebindingTheTrackRebindsTheFieldBehindIt(): void
    {
        $field = FocusGutter::around(TextInput::new()->withValue('typed'))
            ->withKeybindings(new Keybindings([Actions::CLEAR_LINE => ['ctrl+w']]))
            ->content();
        self::assertInstanceOf(TextInput::class, $field);

        $cleared = $field->update(new KeyMsg('w', ctrl: true), new Rect(0, 0, 20, 1))->model;

        self::assertInstanceOf(TextInput::class, $cleared);
        self::assertSame('', $cleared->value(), 'a rebinding a host asks for lands on the field, not on the track');
    }

    #[Test]
    public function itSaysWhatItHoldsAndWhereThatLandsForAHostWalkingTheTree(): void
    {
        $field = TextInput::new()->withPlaceholder('type here');

        $gutter = FocusGutter::around($field);

        self::assertSame($field, $gutter->content(), 'the track does not hide the field behind it');
        self::assertSame(2, $gutter->contentArea(new Rect(0, 0, 20, 1))->x, 'nor where the field was drawn');
    }

    #[Test]
    public function theWidgetItHoldsStartsClearOfTheTrack(): void
    {
        $frame = new Frame(20, 1);

        FocusGutter::around(TextInput::new()->withPlaceholder('type here'))
            ->render($frame, new Rect(0, 0, 20, 1), new RenderContext(Theme::dark()));

        self::assertSame('t', $frame->characters[2], 'the field begins past the track, focused or not');
    }

    #[Test]
    public function anIdleWidgetKeepsAHairlineInTheSameColumn(): void
    {
        $frame = new Frame(12, 1);
        $theme = Theme::dark();

        FocusGutter::around(TextInput::new())
            ->render($frame, new Rect(0, 0, 12, 1), new RenderContext($theme));

        self::assertSame(FocusGutter::RAIL, $frame->characters[0], 'the column is a track, never a gap');
        self::assertSame($theme->dim->foregroundValue(), $frame->foregrounds[0]);
    }
}
