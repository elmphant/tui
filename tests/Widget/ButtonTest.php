<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\Cmd\MessageCmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\AnsiColor;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Widget\Field\Button;
use Elmphant\Tui\Widget\Field\ButtonTone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ButtonTest extends TestCase
{
    #[Test]
    public function itNamesTheKeysItAnswers(): void
    {
        self::assertSame(
            ['Enter' => 'press'],
            Button::new('Run', new TestMessage('run'))->hints(),
        );
    }

    #[Test]
    public function enterEmitsTheMessage(): void
    {
        $pressed = new TestMessage('run');
        $button = Button::new('Run', $pressed);

        $result = $button->update(new KeyMsg(Key::Enter), new Rect(0, 0, 10, 3));

        self::assertEmitted($pressed, $result);
    }

    #[Test]
    public function spaceEmitsTheMessage(): void
    {
        $pressed = new TestMessage('run');
        $button = Button::new('Run', $pressed);

        $result = $button->update(new KeyMsg(' '), new Rect(0, 0, 10, 3));

        self::assertEmitted($pressed, $result);
    }

    #[Test]
    public function aPressAndAReleaseOverTheButtonRunsIt(): void
    {
        $wanted = new TestMessage('run');
        $area = new Rect(0, 0, 10, 3);
        $button = Button::new('Run', $wanted);

        $armed = $button->update(new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Button::class, $armed);

        $result = $armed->update(new MouseMsg(5, 1, MouseAction::Release, MouseButton::Left), $area);

        self::assertEmitted($wanted, $result);
    }

    #[Test]
    public function aPressOnItsOwnCommitsNothing(): void
    {
        $button = Button::new('Run', new TestMessage('run'));

        $result = $button->update(
            new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left),
            new Rect(0, 0, 10, 3),
        );

        self::assertNull($result->command, 'a reader who has not let go can still drag off to cancel');
    }

    #[Test]
    public function aReleaseTheButtonNeverSawPressedIsIgnored(): void
    {
        $button = Button::new('Run', new TestMessage('run'));

        $result = $button->update(
            new MouseMsg(5, 1, MouseAction::Release, MouseButton::Left),
            new Rect(0, 0, 10, 3),
        );

        self::assertNull($result->command, 'the press that would have armed it happened elsewhere');
    }

    #[Test]
    public function lettingGoAwayFromTheButtonCancelsTheRun(): void
    {
        $area = new Rect(0, 0, 10, 3);
        $armed = Button::new('Run', new TestMessage('run'))
            ->update(new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Button::class, $armed);

        $result = $armed->update(new MouseMsg(50, 20, MouseAction::Release, MouseButton::Left), $area);

        self::assertNull($result->command, 'a reader who thought better of it dragged off first');
    }

    #[Test]
    public function aCancelledPressLeavesNothingArmedForTheNextRelease(): void
    {
        $area = new Rect(0, 0, 10, 3);
        $armed = Button::new('Run', new TestMessage('run'))
            ->update(new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Button::class, $armed);

        $cancelled = $armed->update(new MouseMsg(50, 20, MouseAction::Release, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Button::class, $cancelled);

        $result = $cancelled->update(new MouseMsg(5, 1, MouseAction::Release, MouseButton::Left), $area);

        self::assertNull($result->command);
    }

    #[Test]
    public function theButtonSaysWhetherItIsBeingHeldDown(): void
    {
        $area = new Rect(0, 0, 10, 3);
        $button = Button::new('Run', new TestMessage('run'));

        self::assertFalse($button->isArmed());

        $armed = $button->update(new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Button::class, $armed);

        self::assertTrue($armed->isArmed(), 'so a host that draws it need not track the press itself');
    }

    #[Test]
    public function aClickOutsideTheAreaIsIgnored(): void
    {
        $button = Button::new('Run', new TestMessage('run'));

        $result = $button->update(
            new MouseMsg(50, 20, MouseAction::Press, MouseButton::Left),
            new Rect(0, 0, 10, 3),
        );

        self::assertNull($result->command);
    }

    #[Test]
    public function aDisabledButtonIgnoresEnter(): void
    {
        $button = Button::new('Run', new TestMessage('run'))
            ->withDisabled();

        $result = $button->update(new KeyMsg(Key::Enter), new Rect(0, 0, 10, 3));

        self::assertNull($result->command);
    }

    #[Test]
    public function theLabelIsCentredInsideTheBorder(): void
    {
        $frame = new Frame(11, 3);

        Button::new('Run', new TestMessage('run'))
            ->render($frame, new Rect(0, 0, 11, 3), new RenderContext(Theme::dark()));

        self::assertSame('Run', implode('', array_slice($frame->characters, 11 + 4, 3)));
    }

    #[Test]
    public function aBorderSurroundsTheLabel(): void
    {
        $frame = new Frame(11, 3);

        Button::new('Run', new TestMessage('run'))
            ->render($frame, new Rect(0, 0, 11, 3), new RenderContext(Theme::dark()));

        self::assertSame('╭', $frame->characters[0], 'top-left');
        self::assertSame('╮', $frame->characters[10], 'top-right');
        self::assertSame('╰', $frame->characters[22], 'bottom-left');
        self::assertSame('╯', $frame->characters[32], 'bottom-right');
    }

    #[Test]
    public function aDangerButtonTakesItsBorderColourFromTheThemeError(): void
    {
        $frame = new Frame(11, 3);
        $theme = Theme::dark();

        Button::new('Delete', new TestMessage('delete'))
            ->withTone(ButtonTone::Danger)
            ->render($frame, new Rect(0, 0, 11, 3), new RenderContext($theme));

        self::assertSame($theme->error->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function aFocusedButtonFillsBehindItsLabel(): void
    {
        $frame = new Frame(11, 3);
        $theme = Theme::dark();

        Button::new('Run', new TestMessage('run'))
            ->withTone(ButtonTone::Danger)
            ->render($frame, new Rect(0, 0, 11, 3), RenderContext::focused($theme));

        self::assertSame(' ', $frame->characters[11 + 2], 'a cell the label does not reach');
        self::assertSame($theme->error->foregroundValue(), $frame->backgrounds[11 + 2]);
    }

    #[Test]
    public function aFocusedButtonIsOutlinedInAHeavierStrokeThanAnIdleOne(): void
    {
        $idle = new Frame(11, 3);
        $focused = new Frame(11, 3);
        $area = new Rect(0, 0, 11, 3);

        Button::new('Run', new TestMessage('run'))->render($idle, $area, RenderContext::unfocused(Theme::dark()));
        Button::new('Run', new TestMessage('run'))->render($focused, $area, RenderContext::focused(Theme::dark()));

        self::assertSame('╭', $idle->characters[0], 'idle keeps the rounded corner every other box uses');
        self::assertSame('┏', $focused->characters[0], 'and focus thickens the whole outline');
    }

    #[Test]
    public function aFocusedButtonOutlinesItselfInTheThemesFocusedBorderStyle(): void
    {
        $frame = new Frame(11, 3);
        $theme = Theme::dark();

        Button::new('Run', new TestMessage('run'))
            ->render($frame, new Rect(0, 0, 11, 3), RenderContext::focused($theme));

        self::assertSame($theme->focusedBorderStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function aFocusedButtonOfNoToneIsOutlinedOnlyWithNothingFilledBehindIt(): void
    {
        $frame = new Frame(11, 3);

        Button::new('Run', new TestMessage('run'))
            ->render($frame, new Rect(0, 0, 11, 3), new RenderContext(Theme::dark()));

        self::assertSame(0, $frame->backgrounds[11 + 2], 'beside the label');
        self::assertSame(0, $frame->backgrounds[11 + 4], 'and behind it');
    }

    #[Test]
    public function aButtonHeldDownIsBoldEvenWhereTheThemesFocusedBorderIsNot(): void
    {
        $frame = new Frame(11, 3);
        $theme = Theme::dark()->withFocusedBorderStyle(new Style(foreground: Color::ansi(AnsiColor::Blue)));
        $button = Button::new('Run', new TestMessage('run'));

        self::heldDown($button)->render($frame, new Rect(0, 0, 11, 3), new RenderContext($theme));
        $idle = new Frame(11, 3);
        $button->render($idle, new Rect(0, 0, 11, 3), RenderContext::focused($theme));

        self::assertSame(Attr::BOLD, $frame->attributes[0] & Attr::BOLD);
        self::assertSame(0, $idle->attributes[0] & Attr::BOLD, 'focus alone takes the theme as it is');
    }

    #[Test]
    public function anUnfocusedButtonOfNoToneIsOutlinedInThePlainBorder(): void
    {
        $frame = new Frame(11, 3);
        $theme = Theme::dark();

        Button::new('Run', new TestMessage('run'))->render($frame, new Rect(0, 0, 11, 3), RenderContext::unfocused($theme));

        self::assertSame($theme->borderStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function aReleaseThatRanTheButtonLeavesItNothingToRunAgain(): void
    {
        $area = new Rect(0, 0, 10, 3);
        $armed = Button::new('Run', new TestMessage('run'))
            ->update(new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Button::class, $armed);
        $ran = $armed->update(new MouseMsg(5, 1, MouseAction::Release, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Button::class, $ran);

        self::assertNull($ran->update(new MouseMsg(5, 1, MouseAction::Release, MouseButton::Left), $area)->command);
    }

    #[Test]
    public function thePointerMovingOverAHeldButtonKeepsItHeld(): void
    {
        $wanted = new TestMessage('run');
        $area = new Rect(0, 0, 10, 3);
        $armed = Button::new('Run', $wanted)
            ->update(new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Button::class, $armed);
        $motion = $armed->update(new MouseMsg(6, 1, MouseAction::Motion, MouseButton::Left), $area);
        $moved = $motion->model;
        self::assertInstanceOf(Button::class, $moved);

        self::assertNull($motion->command);
        self::assertEmitted($wanted, $moved->update(new MouseMsg(6, 1, MouseAction::Release, MouseButton::Left), $area));
    }

    #[Test]
    public function aKeyItDoesNotAnswerLeavesItAsItWas(): void
    {
        $button = Button::new('Run', new TestMessage('run'));

        $result = $button->update(new KeyMsg('x'), new Rect(0, 0, 10, 3));

        self::assertSame([$button, null], [$result->model, $result->command]);
    }

    #[Test]
    public function aButtonHeldDownStandsOutEvenWithoutTheFocus(): void
    {
        $frame = new Frame(11, 3);
        $theme = Theme::dark();

        self::heldDown(Button::new('Run', new TestMessage('run')))
            ->render($frame, new Rect(0, 0, 11, 3), new RenderContext($theme));

        self::assertSame($theme->focusedBorderStyle->foregroundValue(), $frame->foregrounds[0], 'the press is loud');
        self::assertSame(Attr::BOLD, $frame->attributes[0] & Attr::BOLD, 'and louder than the focus alone');
    }

    #[Test]
    public function aDisabledButtonDrawsDimWhateverItsToneAndFocus(): void
    {
        $frame = new Frame(11, 3);
        $theme = Theme::dark();

        Button::new('Delete', new TestMessage('delete'))
            ->withTone(ButtonTone::Danger)
            ->withDisabled()
            ->render($frame, new Rect(0, 0, 11, 3), new RenderContext($theme));

        self::assertSame($theme->dim->foregroundValue(), $frame->foregrounds[0], 'the border ignores the tone');
        self::assertSame(0, $frame->backgrounds[11 + 2], 'and focus never fills it');
    }

    #[Test]
    public function theWantedWidthCoversTheLabelBorderAndPadding(): void
    {
        self::assertSame(7, Button::new('Run', new TestMessage('run'))->sizing()->columns->basis);
    }

    #[Test]
    public function aLabelWiderThanTheButtonIsTruncatedToTheInnerWidth(): void
    {
        $frame = new Frame(8, 3);

        Button::new('Elimination', new TestMessage('run'))
            ->render($frame, new Rect(0, 0, 8, 3), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[8 + 1], 'the padding column stays clear');
        self::assertSame('Elim', implode('', array_slice($frame->characters, 8 + 2, 4)));
    }

    #[Test]
    public function constraintReturnsExactThree(): void
    {
        $constraint = Button::new('Run', new TestMessage('run'))->sizing()->rows;

        self::assertSame(3, $constraint->min);
        self::assertSame(3, $constraint->max);
    }

    #[Test]
    public function anAreaTooNarrowForTheBorderDrawsNothing(): void
    {
        $frame = new Frame(3, 3);

        Button::new('Run', new TestMessage('run'))
            ->render($frame, new Rect(0, 0, 3, 3), new RenderContext(Theme::dark()));

        self::assertSame(str_repeat(' ', 9), implode('', $frame->characters));
    }

    #[Test]
    public function theNarrowestAreaThatFitsStillDrawsTheBorder(): void
    {
        $frame = new Frame(4, 3);

        Button::new('Run', new TestMessage('run'))
            ->render($frame, new Rect(0, 0, 4, 3), new RenderContext(Theme::dark()));

        self::assertSame('╭', $frame->characters[0], 'four columns is exactly enough');
    }

    #[Test]
    public function aPrimaryButtonTakesItsBorderColourFromTheThemeAccent(): void
    {
        $frame = new Frame(11, 3);
        $theme = Theme::dark();

        Button::new('Run', new TestMessage('run'))
            ->withTone(ButtonTone::Primary)
            ->render($frame, new Rect(0, 0, 11, 3), new RenderContext($theme));

        self::assertSame($theme->accent->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function aDisabledButtonDrawsItsLabelDim(): void
    {
        $frame = new Frame(11, 3);
        $theme = Theme::dark();

        Button::new('Run', new TestMessage('run'))
            ->withDisabled()
            ->render($frame, new Rect(0, 0, 11, 3), new RenderContext($theme));

        self::assertSame($theme->dim->foregroundValue(), $frame->foregrounds[11 + 4], 'the first label cell');
    }

    /**
     * Disabling a pressed button must clear its state, since it may never receive the release.
     */
    #[Test]
    public function aReleaseWithNoPressBehindItStartsNothing(): void
    {
        $area = new Rect(0, 0, 12, 3);
        $press = new MouseMsg(2, 1, MouseAction::Press, MouseButton::Left);
        $release = new MouseMsg(2, 1, MouseAction::Release, MouseButton::Left);

        $held = Button::new('Run', new TestMessage('run'))->update($press, $area)->model;
        $whileDisabled = $held->withDisabled(true)->update($release, $area)->model;

        self::assertNull($whileDisabled->withDisabled(false)->update($release, $area)->command);
    }

    /**
     * @param UpdateResult<Reducer> $result
     */
    private static function assertEmitted(Message $expected, UpdateResult $result): void
    {
        $command = $result->command;

        self::assertInstanceOf(MessageCmd::class, $command);
        self::assertSame($expected, $command->message);
    }

    private static function heldDown(Button $button): Button
    {
        $pressed = $button->update(new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left), new Rect(0, 0, 11, 3))->model;
        self::assertInstanceOf(Button::class, $pressed);
        self::assertTrue($pressed->isArmed());

        return $pressed;
    }
}
