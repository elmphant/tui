<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Closure;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Terminal\CursorShape;
use Elmphant\Tui\Widget\Field\EchoMode;
use Elmphant\Tui\Widget\Field\InputDecoration;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextInputTest extends TestCase
{
    /**
     * Left from a cursor past the end only counts down towards the text, so the
     * field reads as frozen for as many presses as the cursor was out by.
     */
    #[Test]
    public function itNamesNoKeyWhileThereIsNothingToClear(): void
    {
        self::assertSame([], TextInput::new()->hints());
    }

    #[Test]
    public function itNamesTheKeyThatEmptiesWhatWasTyped(): void
    {
        self::assertSame(['Ctrl+U' => 'clear'], TextInput::new()->withValue('typed')->hints());
    }

    #[Test]
    public function aCursorSetPastTheEndOfTheValueSitsAtTheEnd(): void
    {
        self::assertSame(3, TextInput::new()->withValue('abc')->withCursor(99)->cursor());
    }

    #[Test]
    public function aCursorSetBeforeTheStartSitsAtTheStart(): void
    {
        self::assertSame(0, TextInput::new()->withValue('abc')->withCursor(-5)->cursor());
    }

    #[Test]
    public function aPasteInTheMiddleKeepsWhatFollowsTheCaret(): void
    {
        $field = TextInput::new()->withValue('ad')->withCursor(1);

        $pasted = $field->update(new PasteMsg('bc'), Rect::fromSize(20, 1))->model;

        self::assertSame(['abcd', 3], [$pasted->value(), $pasted->cursor()]);
    }

    #[Test]
    public function backspaceAtTheStartLeavesTheTextAlone(): void
    {
        $field = TextInput::new()->withValue('abc')->withCursor(0);

        $result = $field->update(new KeyMsg(Key::Backspace), Rect::fromSize(20, 1))->model;

        self::assertSame(['abc', 0], [$result->value(), $result->cursor()]);
    }

    #[Test]
    public function aWideCharacterThatDoesNotFitHidesWhatComesAfterIt(): void
    {
        $frame = new Frame(10, 1);

        TextInput::new()->withValue('ab全c')->withCursor(0)
            ->render($frame, new Rect(0, 0, 3, 1), RenderContext::unfocused(Theme::dark()));

        self::assertSame(['a', 'b', ' '], array_slice($frame->characters, 0, 3));
    }

    #[Test]
    public function theFieldDecoratesOnlyItsOwnRow(): void
    {
        $frame = new Frame(10, 2);

        TextInput::new()->withValue('abc')->render($frame, new Rect(0, 0, 10, 2), RenderContext::focused(Theme::dark()));

        self::assertSame([Attr::UNDERLINE, 0], [$frame->attributes[0] & Attr::UNDERLINE, $frame->attributes[10] & Attr::UNDERLINE]);
    }

    #[Test]
    public function aFieldMadeWithTextHoldsItAsItsValue(): void
    {
        $field = TextInput::new('https://example.test');

        self::assertSame('https://example.test', $field->value());
        self::assertSame(20, $field->cursor());
    }

    #[Test]
    public function thePlaceholderCanBeReplacedAfterConstruction(): void
    {
        $frame = new Frame(20, 1);
        TextInput::new()->withPlaceholder('type here')->withPlaceholder('or here')
            ->render($frame, new Rect(0, 0, 20, 1), new RenderContext(Theme::dark()));

        $line = '';
        for ($column = 0; $column < 20; ++$column) {
            $line .= $frame->characters[$column];
        }

        self::assertStringContainsString('or here', $line);
        self::assertStringNotContainsString('type here', $line);
    }

    #[Test]
    public function altLeftStopsAtTheStartOfEachWordAndEachRunOfPunctuation(): void
    {
        $input = TextInput::new()->withValue('foo-bar baz');
        $area = Rect::fromSize(40, 1);
        $stops = [];

        for ($step = 0; $step < 4; ++$step) {
            $input = $input->update(new KeyMsg(Key::Left, alt: true), $area)->model;
            $stops[] = $input->cursor();
        }

        self::assertSame([8, 4, 3, 0], $stops);
    }

    #[Test]
    public function altRightStopsAtTheEndOfEachWordAndEachRunOfPunctuation(): void
    {
        $input = TextInput::new()->withValue('foo-bar baz')->withCursor(0);
        $area = Rect::fromSize(40, 1);
        $stops = [];

        for ($step = 0; $step < 4; ++$step) {
            $input = $input->update(new KeyMsg(Key::Right, alt: true), $area)->model;
            $stops[] = $input->cursor();
        }

        self::assertSame([3, 4, 7, 11], $stops);
    }

    #[Test]
    public function aTypedCombiningMarkJoinsTheCharacterBeforeIt(): void
    {
        $area = Rect::fromSize(40, 1);
        $input = TextInput::new()->withValue('cafe');

        $accented = $input->update(new KeyMsg("\u{0301}"), $area)->model;
        $wordLeft = $accented->update(new KeyMsg(Key::Left, alt: true), $area)->model;

        self::assertSame(["cafe\u{0301}", 4, 0], [$accented->value(), $accented->cursor(), $wordLeft->cursor()]);
    }

    #[Test]
    public function aPasteStartingWithACombiningMarkJoinsTheCharacterBeforeIt(): void
    {
        $input = TextInput::new()->withValue('cafe');

        $pasted = $input->update(new PasteMsg("\u{0301}!"), Rect::fromSize(40, 1))->model;

        self::assertSame(["cafe\u{0301}!", 5], [$pasted->value(), $pasted->cursor()]);
    }

    #[Test]
    public function wordMotionStopsAtBothEndsAndReadsAccentedLettersAsLetters(): void
    {
        $area = Rect::fromSize(40, 1);
        $input = TextInput::new()->withValue('naïve café');

        $atStart = $input->withCursor(0)->update(new KeyMsg(Key::Left, alt: true), $area)->model;
        $atEnd = $input->update(new KeyMsg(Key::Right, alt: true), $area)->model;
        $back = $input->update(new KeyMsg(Key::Left, alt: true), $area)->model;

        self::assertSame([0, 10, 6], [$atStart->cursor(), $atEnd->cursor(), $back->cursor()]);
    }

    #[Test]
    public function altBAndAltFMoveByWordAsInReadline(): void
    {
        $area = Rect::fromSize(40, 1);
        $input = TextInput::new()->withValue('one two');

        $back = $input->update(new KeyMsg('b', alt: true), $area)->model;
        $forward = $back->withCursor(0)->update(new KeyMsg('f', alt: true), $area)->model;

        self::assertSame([4, 3], [$back->cursor(), $forward->cursor()]);
    }

    #[Test]
    public function deletingAWordBackwardStopsAtPunctuation(): void
    {
        $input = TextInput::new()->withValue('src/Widget/Field');

        $deleted = $input->update(new KeyMsg(Key::Backspace, ctrl: true), Rect::fromSize(40, 1))->model;

        self::assertSame('src/Widget/', $deleted->value());
    }

    #[Test]
    public function deletingAWordForwardStopsAtPunctuation(): void
    {
        $input = TextInput::new()->withValue('src/Widget/Field')->withCursor(0);

        $deleted = $input->update(new KeyMsg(Key::Delete, ctrl: true), Rect::fromSize(40, 1))->model;

        self::assertSame('/Widget/Field', $deleted->value());
    }

    #[Test]
    public function typingAfterAValueWithMalformedUtf8AppendsToIt(): void
    {
        $input = TextInput::new()->withValue("ab\xFFcd");

        $typed = $input->update(new KeyMsg('x'), Rect::fromSize(40, 1))->model;

        self::assertSame("ab\xFFcdx", $typed->value());
    }

    #[Test]
    public function implementsFocusable(): void
    {
        self::assertInstanceOf(Focusable::class, TextInput::new());
    }

    #[Test]
    public function constraintReturnsExactOne(): void
    {
        $input = TextInput::new();
        $constraint = $input->sizing()->rows;
        self::assertSame(1, $constraint->min);
        self::assertSame(1, $constraint->max);
    }

    #[Test]
    public function valueStartsEmpty(): void
    {
        self::assertSame('', TextInput::new()->value());
    }

    #[Test]
    public function cursorStartsAtZero(): void
    {
        self::assertSame(0, TextInput::new()->cursor());
    }

    #[Test]
    public function typingCharacterAppendsToValue(): void
    {
        $input = TextInput::new();
        $area = Rect::fromSize(40, 1);
        $result = $input->update(new KeyMsg('h'), $area);
        self::assertSame('h', $result->model->value());
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function typingMultipleCharacters(): void
    {
        $input = TextInput::new();
        $area = Rect::fromSize(40, 1);

        $result = $input->update(new KeyMsg('h'), $area);
        $result = $result->model->update(new KeyMsg('i'), $area);
        self::assertSame('hi', $result->model->value());
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function backspaceDeletesCharacterBeforeCursor(): void
    {
        $input = TextInput::new()->withValue('hello');
        $area = Rect::fromSize(40, 1);
        $result = $input->update(new KeyMsg(Key::Backspace), $area);
        self::assertSame('hell', $result->model->value());
        self::assertSame(4, $result->model->cursor());
    }

    #[Test]
    public function backspaceAtStartDoesNothing(): void
    {
        $input = TextInput::new();
        $area = Rect::fromSize(40, 1);
        $result = $input->update(new KeyMsg(Key::Backspace), $area);
        self::assertSame($input, $result->model);
    }

    #[Test]
    public function deleteRemovesCharacterAtCursor(): void
    {
        $input = TextInput::new()->withValue('hello');
        $area = Rect::fromSize(40, 1);

        // Move cursor to position 2 (after "he")
        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;

        $result = $moved->update(new KeyMsg(Key::Delete), $area);
        self::assertSame('helo', $result->model->value());
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function leftArrowMovesCursorBack(): void
    {
        $input = TextInput::new()->withValue('hi');
        $area = Rect::fromSize(40, 1);
        $result = $input->update(new KeyMsg(Key::Left), $area);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function leftArrowAtStartDoesNothing(): void
    {
        $input = TextInput::new()->withValue('hi');
        $area = Rect::fromSize(40, 1);
        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $result = $moved->update(new KeyMsg(Key::Left), $area);
        self::assertSame($moved, $result->model);
    }

    #[Test]
    public function rightArrowMovesCursorForward(): void
    {
        $input = TextInput::new()->withValue('hi');
        $area = Rect::fromSize(40, 1);
        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $result = $moved->update(new KeyMsg(Key::Right), $area);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function rightArrowAtEndDoesNothing(): void
    {
        $input = TextInput::new()->withValue('hi');
        $area = Rect::fromSize(40, 1);
        $result = $input->update(new KeyMsg(Key::Right), $area);
        self::assertSame($input, $result->model);
    }

    #[Test]
    public function homeMovesCursorToStart(): void
    {
        $input = TextInput::new()->withValue('hello');
        $area = Rect::fromSize(40, 1);
        $result = $input->update(new KeyMsg(Key::Home), $area);
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function endMovesCursorToEnd(): void
    {
        $input = TextInput::new()->withValue('hello');
        $area = Rect::fromSize(40, 1);
        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $result = $moved->update(new KeyMsg(Key::End), $area);
        self::assertSame(5, $result->model->cursor());
    }

    #[Test]
    public function withValueSetsCursorToEnd(): void
    {
        $input = TextInput::new()->withValue('hello');
        self::assertSame('hello', $input->value());
        self::assertSame(5, $input->cursor());
    }

    #[Test]
    public function charLimitPreventsExcessInput(): void
    {
        $input = TextInput::new()->withCharLimit(3);
        $area = Rect::fromSize(40, 1);

        $result = $input->update(new KeyMsg('a'), $area);
        $result = $result->model->update(new KeyMsg('b'), $area);
        $result = $result->model->update(new KeyMsg('c'), $area);
        $result = $result->model->update(new KeyMsg('d'), $area);
        self::assertSame('abc', $result->model->value());
    }

    #[Test]
    public function charLimitZeroMeansUnlimited(): void
    {
        $input = TextInput::new()->withCharLimit(0);
        $area = Rect::fromSize(40, 1);

        $text = str_repeat('x', 100);
        $current = $input;
        foreach (mb_str_split($text) as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }
        self::assertSame(100, mb_strlen($current->value()));
    }

    #[Test]
    public function echoModePasswordMasksDisplay(): void
    {
        $input = TextInput::new()->withEchoMode(EchoMode::Password)->withValue('secret');
        self::assertSame(EchoMode::Password, $input->echoMode());
    }

    #[Test]
    public function insertInMiddleOfText(): void
    {
        $input = TextInput::new()->withValue('hllo');
        $area = Rect::fromSize(40, 1);

        // Move cursor to position 1
        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(1, $moved->cursor());

        $result = $moved->update(new KeyMsg('e'), $area);
        self::assertSame('hello', $result->model->value());
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function ctrlBackspaceDeletesWordBackward(): void
    {
        $input = TextInput::new()->withValue('hello world');
        $area = Rect::fromSize(40, 1);

        $result = $input->update(new KeyMsg(Key::Backspace, ctrl: true), $area);
        self::assertSame('hello ', $result->model->value());
    }

    #[Test]
    public function ctrlDeleteDeletesWordForward(): void
    {
        $input = TextInput::new()->withValue('hello world');
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $result = $moved->update(new KeyMsg(Key::Delete, ctrl: true), $area);
        self::assertSame(' world', $result->model->value());
    }

    #[Test]
    public function mouseClickPositionsCursor(): void
    {
        $input = TextInput::new()->withValue('hello world');
        $area = new Rect(0, 0, 40, 1);

        $mouseMessage = new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left);
        $result = $input->update($mouseMessage, $area);
        self::assertSame(5, $result->model->cursor());
    }

    #[Test]
    public function mouseClickBeyondTextPositionsCursorAtEnd(): void
    {
        $input = TextInput::new()->withValue('hi');
        $area = new Rect(0, 0, 40, 1);

        $mouseMessage = new MouseMsg(20, 0, MouseAction::Press, MouseButton::Left);
        $result = $input->update($mouseMessage, $area);
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function mouseClickAtCurrentCursorPositionReturnsSameInstance(): void
    {
        $input = TextInput::new()->withValue('hello world');
        $area = new Rect(0, 0, 40, 1);
        self::assertSame(11, $input->cursor(), 'precondition: cursor is at end after withValue');

        $mouseMessage = new MouseMsg(11, 0, MouseAction::Press, MouseButton::Left);
        $result = $input->update($mouseMessage, $area);
        self::assertSame($input, $result->model, 'clicking at current cursor position must return same instance');
    }

    #[Test]
    public function mouseClickOutsideAreaDoesNothing(): void
    {
        $input = TextInput::new()->withValue('hello');
        $area = new Rect(0, 0, 40, 1);

        $mouseMessage = new MouseMsg(5, 3, MouseAction::Press, MouseButton::Left);
        $result = $input->update($mouseMessage, $area);
        self::assertSame($input, $result->model);
    }

    #[Test]
    public function rendersValueWhenFocused(): void
    {
        $input = TextInput::new()->withValue('hello');
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('h', $frame->characters[0]);
        self::assertSame('e', $frame->characters[1]);
        self::assertSame('l', $frame->characters[2]);
    }

    #[Test]
    public function rendersPlaceholderWhenEmptyAndNotFocused(): void
    {
        $input = TextInput::new()->withPlaceholder('Type here...');
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('T', $frame->characters[0]);
        self::assertSame('y', $frame->characters[1]);
    }

    #[Test]
    public function rendersPlaceholderWhileFocusedAndEmpty(): void
    {
        $input = TextInput::new()->withPlaceholder('Type here...');
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('T', $frame->characters[0], 'A focused empty field must still show what it is for');
        self::assertSame('y', $frame->characters[1]);
    }

    #[Test]
    public function drawsABlockCaretByDefault(): void
    {
        $input = TextInput::new()->withValue('hi');
        $frame = new Frame(20, 1);
        $input->render($frame, new Rect(0, 0, 20, 1), RenderContext::focused(Theme::dark()));

        self::assertNotNull($frame->cursorPosition);
        self::assertSame(CursorShape::BlinkingBlock, $frame->cursorPosition->shape, 'A block caret is easier to spot in a form');
    }

    #[Test]
    public function theCaretShapeCanBeChosen(): void
    {
        $input = TextInput::new()->withValue('hi')->withCursorShape(CursorShape::SteadyBar);
        $frame = new Frame(20, 1);
        $input->render($frame, new Rect(0, 0, 20, 1), RenderContext::focused(Theme::dark()));

        self::assertNotNull($frame->cursorPosition);
        self::assertSame(CursorShape::SteadyBar, $frame->cursorPosition->shape);
    }

    #[Test]
    public function rendersTheCursorOverThePlaceholderWhileFocused(): void
    {
        $input = TextInput::new()->withPlaceholder('Type here...');
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $input->render($frame, $area, RenderContext::focused(Theme::dark()));

        self::assertNotNull($frame->cursorPosition);
        self::assertSame(0, $frame->cursorPosition->col);
    }

    #[Test]
    public function typingReplacesThePlaceholder(): void
    {
        $input = TextInput::new()->withPlaceholder('Type here...')->withValue('x');
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('x', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[1], 'No placeholder remnants after the typed text');
    }

    #[Test]
    public function renderPasswordModeShowsDots(): void
    {
        $input = TextInput::new()
            ->withEchoMode(EchoMode::Password)
            ->withValue('secret')
        ;
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('•', $frame->characters[0]);
        self::assertSame('•', $frame->characters[1]);
    }

    #[Test]
    public function renderWithZeroWidthDoesNotCrash(): void
    {
        $input = TextInput::new()->withValue('test');
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 0, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function viewportScrollsWhenCursorExceedsWidth(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 5, 1);

        $current = $input;
        foreach (mb_str_split('abcdefgh') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        self::assertSame('abcdefgh', $current->value());
        self::assertSame(8, $current->cursor());

        $frame = new Frame(5, 1);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        // Viewport scrolled: positions 4-8 visible, cursor at buffer[4]
        self::assertSame('h', $frame->characters[3]);
    }

    #[Test]
    public function tickBeforeBlinkIntervalDoesNotToggle(): void
    {
        $input = TextInput::new()->withValue('hi');
        $area = Rect::fromSize(20, 1);

        $result = $input->update(new TickMsg(0.1), $area);
        self::assertSame($input, $result->model);
    }

    #[Test]
    public function blinkIgnoredWhenNotFocused(): void
    {
        $input = TextInput::new()->withValue('hi');
        $area = Rect::fromSize(20, 1);

        $result = $input->update(new TickMsg(0.6), $area);
        self::assertSame($input, $result->model);
    }

    #[Test]
    public function styledAppliesBackgroundToFullWidth(): void
    {
        $input = TextInput::new()->withStyled()->withValue('hi');
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        $expectedBg = Theme::dark()->inputStyle->backgroundValue();
        foreach ([0, 1, 2, 3, 5, 9] as $x) {
            self::assertSame(
                $expectedBg,
                $frame->backgrounds[$x],
                "Cell at x=$x should have input background",
            );
        }
    }

    #[Test]
    public function styledAppliesBackgroundToPlaceholder(): void
    {
        $input = TextInput::new()->withPlaceholder('Type here...')->withStyled();
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('T', $frame->characters[0]);
        self::assertSame(Theme::dark()->inputStyle->backgroundValue(), $frame->backgrounds[0]);
    }

    #[Test]
    public function fieldsAreUnderlinedAndNotFilledByDefault(): void
    {
        // Underline OR background — combining both reads as a heavy block and
        // collides with selection and bar backgrounds.
        $theme = Theme::dark();
        $input = TextInput::new()->withValue('hi');
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $input->render($frame, $area, new RenderContext($theme));

        self::assertSame(Attr::UNDERLINE, $frame->attributes[5] & Attr::UNDERLINE, 'The empty tail of the field is still part of the field');
        self::assertSame(0, $frame->backgrounds[5], 'An underlined field is not also filled');
    }

    #[Test]
    public function aFieldGivenAWidthStopsThereHoweverWideItsArea(): void
    {
        $frame = new Frame(30, 1);

        TextInput::new()->withWidth(10)->render($frame, new Rect(0, 0, 30, 1), new RenderContext(Theme::dark()));

        self::assertSame(Attr::UNDERLINE, $frame->attributes[9] & Attr::UNDERLINE);
        self::assertSame(0, $frame->attributes[10] & Attr::UNDERLINE);
    }

    #[Test]
    public function aFieldGivenAWidthIgnoresAClickBesideIt(): void
    {
        $input = TextInput::new()->withValue('hello')->withCursor(0)->withWidth(10);

        $clicked = $input->update(new MouseMsg(20, 0, MouseAction::Press, MouseButton::Left), new Rect(0, 0, 30, 1))->model;

        self::assertSame(0, $clicked->cursor());
    }

    #[Test]
    public function theUnderlineIsOneColourAcrossTheWholeField(): void
    {
        // Terminals draw the underline in the text colour, so the placeholder
        // and the empty tail must share one tone or the rule looks broken.
        $input = TextInput::new()->withPlaceholder('Search commands…');
        $frame = new Frame(40, 1);

        $input->render($frame, new Rect(0, 0, 40, 1), new RenderContext(Theme::dark()));

        self::assertSame($frame->foregrounds[39], $frame->foregrounds[2], 'The underline must not change colour mid-field');
        self::assertSame(
            $frame->attributes[39],
            $frame->attributes[2],
            'Fainting only the placeholder makes the rule dimmer under the text than after it',
        );
    }

    #[Test]
    public function theUnderlineIsOneColourOnceTextIsTyped(): void
    {
        $input = TextInput::new()->withPlaceholder('Search commands…')->withValue('cache');
        $frame = new Frame(40, 1);

        $input->render($frame, new Rect(0, 0, 40, 1), new RenderContext(Theme::dark()));

        self::assertSame($frame->foregrounds[39], $frame->foregrounds[0], 'Typed text and the empty tail share the underline colour');
    }

    #[Test]
    public function filledFieldsAreNotAlsoUnderlined(): void
    {
        $theme = Theme::dark();
        $input = TextInput::new()->withValue('hi')->withDecoration(InputDecoration::Filled);
        $frame = new Frame(10, 1);
        $input->render($frame, new Rect(0, 0, 10, 1), new RenderContext($theme));

        self::assertSame($theme->inputStyle->backgroundValue(), $frame->backgrounds[5]);
        self::assertSame(0, $frame->attributes[5] & Attr::UNDERLINE, 'A filled field is not also underlined');
        self::assertSame(0, $frame->attributes[0] & Attr::UNDERLINE);
    }

    #[Test]
    public function fieldsAreUnderlinedAcrossTheirFullWidth(): void
    {
        // The underline marks the field's extent like a form field, even where a
        // theme's input background matches other highlights.
        $input = TextInput::new()->withValue('hi');
        $frame = new Frame(10, 1);
        $input->render($frame, new Rect(0, 0, 10, 1), new RenderContext(Theme::dark()));

        self::assertSame(Attr::UNDERLINE, $frame->attributes[0] & Attr::UNDERLINE, 'Typed text sits on the underline');
        self::assertSame(Attr::UNDERLINE, $frame->attributes[9] & Attr::UNDERLINE, 'The empty tail is underlined too');
    }

    #[Test]
    public function underliningStopsAtTheFieldBounds(): void
    {
        $input = TextInput::new()->withValue('hi');
        $frame = new Frame(12, 1);
        $input->render($frame, new Rect(0, 0, 10, 1), new RenderContext(Theme::dark()));

        self::assertSame(0, $frame->attributes[10] & Attr::UNDERLINE, 'Nothing outside the field is underlined');
    }

    #[Test]
    public function unstyledFieldsAreNotUnderlined(): void
    {
        $input = TextInput::new()->withValue('hi')->withStyled(false);
        $frame = new Frame(10, 1);
        $input->render($frame, new Rect(0, 0, 10, 1), new RenderContext(Theme::dark()));

        self::assertSame(0, $frame->attributes[0] & Attr::UNDERLINE);
    }

    #[Test]
    public function withoutStyledNoBackgroundIsPainted(): void
    {
        $input = TextInput::new()->withValue('hi')->withStyled(false);
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame(0, $frame->backgrounds[5]);
    }

    // --- DecrementInteger: L28 (scrollOffset default 0), L31 (charLimit default 0) ---

    #[Test]
    public function defaultScrollOffsetIsZero(): void
    {
        $input = TextInput::new()->withValue('abc');
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('a', $frame->characters[0]);
        self::assertSame('b', $frame->characters[1]);
        self::assertSame('c', $frame->characters[2]);
    }

    #[Test]
    public function defaultCharLimitAllowsUnlimitedInput(): void
    {
        $input = TextInput::new();
        $area = Rect::fromSize(200, 1);

        $current = $input;
        for ($i = 0; $i < 50; ++$i) {
            $current = $current->update(new KeyMsg('x'), $area)->model;
        }

        self::assertSame(50, mb_strlen($current->value()));
    }

    // --- MBString mutations: withValue (L61) uses mb_strlen ---

    #[Test]
    public function withValueSetsCursorToEndForMultibyteString(): void
    {
        $input = TextInput::new()->withValue("\u{1F600}\u{1F601}\u{1F602}");
        self::assertSame(3, $input->cursor());
    }

    #[Test]
    public function withValueSetsCursorToEndForCjkCharacters(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{597D}");
        self::assertSame(2, $input->cursor());
    }

    // --- Cursor movement with multibyte: moveCursorRight (L213), moveCursorLeft (L208) ---

    #[Test]
    public function cursorMovesRightByOneCharacterWithMultibyte(): void
    {
        $input = TextInput::new()->withValue("\u{1F600}\u{1F601}");
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        self::assertSame(0, $moved->cursor());

        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(1, $moved->cursor());
    }

    #[Test]
    public function cursorMovesLeftByOneCharacterWithMultibyte(): void
    {
        $input = TextInput::new()->withValue("\u{1F600}\u{1F601}");
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Left), $area)->model;
        self::assertSame(1, $moved->cursor());
    }

    #[Test]
    public function rightArrowAtEndOfMultibyteStringDoesNothing(): void
    {
        $input = TextInput::new()->withValue("\u{1F600}");
        $area = Rect::fromSize(40, 1);

        $result = $input->update(new KeyMsg(Key::Right), $area);
        self::assertSame($input, $result->model);
    }

    // --- Home/End with multibyte (L170-171) ---

    #[Test]
    public function homeKeyWhenAlreadyAtStartDoesNothing(): void
    {
        $input = TextInput::new()->withValue('hello');
        $area = Rect::fromSize(40, 1);
        $atHome = $input->update(new KeyMsg(Key::Home), $area)->model;
        $result = $atHome->update(new KeyMsg(Key::Home), $area);
        self::assertSame($atHome, $result->model);
    }

    #[Test]
    public function endKeyWhenAlreadyAtEndDoesNothing(): void
    {
        $input = TextInput::new()->withValue('hello');
        $area = Rect::fromSize(40, 1);
        $result = $input->update(new KeyMsg(Key::End), $area);
        self::assertSame($input, $result->model);
    }

    #[Test]
    public function endKeyMovesToEndWithMultibyteCharacters(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{597D}\u{4E16}\u{754C}");
        $area = Rect::fromSize(40, 1);

        $atHome = $input->update(new KeyMsg(Key::Home), $area)->model;
        self::assertSame(0, $atHome->cursor());

        $atEnd = $atHome->update(new KeyMsg(Key::End), $area)->model;
        self::assertSame(4, $atEnd->cursor());
    }

    #[Test]
    public function endKeyAtEndOfMultibyteStringReturnsSameInstance(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{597D}");
        $area = Rect::fromSize(40, 1);
        $result = $input->update(new KeyMsg(Key::End), $area);
        self::assertSame($input, $result->model);
    }

    // --- Character insertion with multibyte (L176, L216-225) ---

    #[Test]
    public function insertMultibyteCharacterAtBeginning(): void
    {
        $input = TextInput::new()->withValue('abc');
        $area = Rect::fromSize(40, 1);

        $atHome = $input->update(new KeyMsg(Key::Home), $area)->model;
        $result = $atHome->update(new KeyMsg("\u{1F600}"), $area);

        self::assertSame("\u{1F600}abc", $result->model->value());
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function insertMultibyteCharacterInMiddle(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{754C}");
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(1, $moved->cursor());

        $result = $moved->update(new KeyMsg("\u{597D}"), $area);
        self::assertSame("\u{4F60}\u{597D}\u{754C}", $result->model->value());
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function charLimitCountsMultibyteCharactersNotBytes(): void
    {
        $input = TextInput::new()->withCharLimit(2);
        $area = Rect::fromSize(40, 1);

        $result = $input->update(new KeyMsg("\u{1F600}"), $area);
        $result = $result->model->update(new KeyMsg("\u{1F601}"), $area);
        self::assertSame(2, mb_strlen($result->model->value()));

        $result = $result->model->update(new KeyMsg("\u{1F602}"), $area);
        self::assertSame(2, mb_strlen($result->model->value()));
    }

    #[Test]
    public function keyMsgWithMultipleCodePointsIsIgnored(): void
    {
        $input = TextInput::new();
        $area = Rect::fromSize(40, 1);
        $result = $input->update(new KeyMsg('ab'), $area);
        self::assertSame($input, $result->model);
    }

    #[Test]
    public function ctrlKeyIsNotInserted(): void
    {
        $input = TextInput::new();
        $area = Rect::fromSize(40, 1);
        $result = $input->update(new KeyMsg('a', ctrl: true), $area);
        self::assertSame($input, $result->model);
    }

    #[Test]
    public function altKeyIsNotInserted(): void
    {
        $input = TextInput::new();
        $area = Rect::fromSize(40, 1);
        $result = $input->update(new KeyMsg('a', alt: true), $area);
        self::assertSame($input, $result->model);
    }

    // --- Backspace with multibyte (L228-237) ---

    #[Test]
    public function backspaceDeletesMultibyteCharacter(): void
    {
        $input = TextInput::new()->withValue("\u{1F600}\u{1F601}\u{1F602}");
        $area = Rect::fromSize(40, 1);

        $result = $input->update(new KeyMsg(Key::Backspace), $area);
        self::assertSame("\u{1F600}\u{1F601}", $result->model->value());
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function backspaceInMiddleOfMultibyteString(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{597D}\u{4E16}");
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(2, $moved->cursor());

        $result = $moved->update(new KeyMsg(Key::Backspace), $area);
        self::assertSame("\u{4F60}\u{4E16}", $result->model->value());
        self::assertSame(1, $result->model->cursor());
    }

    // --- Delete forward with multibyte (L240-249) ---

    #[Test]
    public function deleteForwardRemovesMultibyteCharacter(): void
    {
        $input = TextInput::new()->withValue("\u{1F600}\u{1F601}\u{1F602}");
        $area = Rect::fromSize(40, 1);

        $atHome = $input->update(new KeyMsg(Key::Home), $area)->model;
        $result = $atHome->update(new KeyMsg(Key::Delete), $area);
        self::assertSame("\u{1F601}\u{1F602}", $result->model->value());
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function deleteAtEndOfMultibyteStringDoesNothing(): void
    {
        $input = TextInput::new()->withValue("\u{1F600}");
        $area = Rect::fromSize(40, 1);

        $result = $input->update(new KeyMsg(Key::Delete), $area);
        self::assertSame($input, $result->model);
    }

    #[Test]
    public function deleteForwardInMiddleOfMultibyteString(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{597D}\u{4E16}");
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(1, $moved->cursor());

        $result = $moved->update(new KeyMsg(Key::Delete), $area);
        self::assertSame("\u{4F60}\u{4E16}", $result->model->value());
        self::assertSame(1, $result->model->cursor());
    }

    // --- Ctrl+Backspace (deleteWordBackward) with multibyte (L252-266) ---

    #[Test]
    public function ctrlBackspaceDeletesWordWithMultibyteCharacters(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{597D} \u{4E16}\u{754C}");
        $area = Rect::fromSize(40, 1);

        $result = $input->update(new KeyMsg(Key::Backspace, ctrl: true), $area);
        self::assertSame("\u{4F60}\u{597D} ", $result->model->value());
    }

    #[Test]
    public function ctrlBackspaceAtStartDoesNothing(): void
    {
        $input = TextInput::new()->withValue('hello world');
        $area = Rect::fromSize(40, 1);

        $atHome = $input->update(new KeyMsg(Key::Home), $area)->model;
        $result = $atHome->update(new KeyMsg(Key::Backspace, ctrl: true), $area);
        self::assertSame($atHome, $result->model);
    }

    #[Test]
    public function ctrlBackspaceDeletesEntireValueWhenNoSpaces(): void
    {
        $input = TextInput::new()->withValue('hello');
        $area = Rect::fromSize(40, 1);

        $result = $input->update(new KeyMsg(Key::Backspace, ctrl: true), $area);
        self::assertSame('', $result->model->value());
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function ctrlBackspaceDeletesTrailingSpacesThenWord(): void
    {
        $input = TextInput::new()->withValue('hello   ');
        $area = Rect::fromSize(40, 1);

        $result = $input->update(new KeyMsg(Key::Backspace, ctrl: true), $area);
        self::assertSame('', $result->model->value());
        self::assertSame(0, $result->model->cursor());
    }

    // --- Ctrl+Delete (deleteWordForward) with multibyte (L269-287) ---

    #[Test]
    public function ctrlDeleteDeletesWordForwardWithMultibyte(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{597D} \u{4E16}\u{754C}");
        $area = Rect::fromSize(40, 1);

        $atHome = $input->update(new KeyMsg(Key::Home), $area)->model;
        $result = $atHome->update(new KeyMsg(Key::Delete, ctrl: true), $area);
        self::assertSame(" \u{4E16}\u{754C}", $result->model->value());
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function ctrlDeleteAtEndDoesNothing(): void
    {
        $input = TextInput::new()->withValue('hello');
        $area = Rect::fromSize(40, 1);

        $result = $input->update(new KeyMsg(Key::Delete, ctrl: true), $area);
        self::assertSame($input, $result->model);
    }

    #[Test]
    public function ctrlDeleteDeletesEntireValueWhenNoSpaces(): void
    {
        $input = TextInput::new()->withValue('hello');
        $area = Rect::fromSize(40, 1);

        $atHome = $input->update(new KeyMsg(Key::Home), $area)->model;
        $result = $atHome->update(new KeyMsg(Key::Delete, ctrl: true), $area);
        self::assertSame('', $result->model->value());
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function ctrlDeleteDeletesLeadingSpacesAndWord(): void
    {
        $input = TextInput::new()->withValue('   hello world');
        $area = Rect::fromSize(40, 1);

        $atHome = $input->update(new KeyMsg(Key::Home), $area)->model;
        $result = $atHome->update(new KeyMsg(Key::Delete, ctrl: true), $area);
        self::assertSame(' world', $result->model->value());
        self::assertSame(0, $result->model->cursor());
    }

    // --- Mouse click with multibyte (L192-203) ---

    #[Test]
    public function mouseClickPositionsCursorWithMultibyteText(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{597D}\u{4E16}\u{754C}");
        $area = new Rect(0, 0, 40, 1);

        // Display: 你(col 0-1) 好(col 2-3) 世(col 4-5) 界(col 6-7)
        // Click at display x=2 lands on 好 = char index 1
        $mouseMessage = new MouseMsg(2, 0, MouseAction::Press, MouseButton::Left);
        $result = $input->update($mouseMessage, $area);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function mouseClickClampsToTextLength(): void
    {
        $input = TextInput::new()->withValue("\u{1F600}");
        $area = new Rect(0, 0, 40, 1);

        $mouseMessage = new MouseMsg(10, 0, MouseAction::Press, MouseButton::Left);
        $result = $input->update($mouseMessage, $area);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function mouseClickAtSamePositionDoesNothing(): void
    {
        $input = TextInput::new()->withValue('hello');
        $area = new Rect(0, 0, 40, 1);

        $mouseMessage = new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left);
        $result = $input->update($mouseMessage, $area);
        self::assertSame($input, $result->model);
    }

    #[Test]
    public function mouseClickWithOffsetArea(): void
    {
        $input = TextInput::new()->withValue('hello world');
        $area = new Rect(5, 2, 40, 1);

        $mouseMessage = new MouseMsg(8, 2, MouseAction::Press, MouseButton::Left);
        $result = $input->update($mouseMessage, $area);
        self::assertSame(3, $result->model->cursor());
    }

    // --- Rendering with multibyte (L116, L130, L138, L143, L146) ---

    #[Test]
    public function renderMultibyteTextCorrectly(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{597D}");
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame("\u{4F60}", $frame->characters[0]);
    }

    #[Test]
    public function renderPlaceholderTruncatedToAvailableWidth(): void
    {
        $input = TextInput::new()->withPlaceholder('Hello World, this is a long placeholder');
        $frame = new Frame(5, 1);
        $area = new Rect(0, 0, 5, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('H', $frame->characters[0]);
        self::assertSame('e', $frame->characters[1]);
        self::assertSame('l', $frame->characters[2]);
        self::assertSame('l', $frame->characters[3]);
        self::assertSame('o', $frame->characters[4]);
    }

    #[Test]
    public function renderMultibytePlaceholderTruncation(): void
    {
        $input = TextInput::new()->withPlaceholder("\u{4F60}\u{597D}\u{4E16}\u{754C}\u{1F600}");
        $frame = new Frame(3, 1);
        $area = new Rect(0, 0, 3, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame("\u{4F60}", $frame->characters[0]);
    }

    #[Test]
    public function renderDoesNotShowPlaceholderWhenValueExists(): void
    {
        $input = TextInput::new()->withPlaceholder('placeholder')->withValue('x');
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('x', $frame->characters[0]);
    }

    #[Test]
    public function renderCursorPositionAccountsForScrollOffset(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 5, 1);

        $current = $input;
        foreach (mb_str_split('abcdefgh') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        $frame = new Frame(5, 1);
        $current->render($frame, $area, RenderContext::focused(Theme::dark()));

        self::assertNotNull($frame->cursorPosition);
        self::assertSame(4, $frame->cursorPosition->col);
    }

    #[Test]
    public function renderDoesNothingWithZeroHeight(): void
    {
        $input = TextInput::new()->withValue('test');
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 0);
        $input->render($frame, $area, new RenderContext(Theme::dark()));
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function renderPasswordModeWithMultibyteCharacters(): void
    {
        $input = TextInput::new()
            ->withEchoMode(EchoMode::Password)
            ->withValue("\u{4F60}\u{597D}\u{4E16}")
        ;
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('•', $frame->characters[0]);
        self::assertSame('•', $frame->characters[1]);
        self::assertSame('•', $frame->characters[2]);
    }

    #[Test]
    public function renderNoneModeShowsNothing(): void
    {
        $input = TextInput::new()
            ->withEchoMode(EchoMode::None)
            ->withValue('secret')
        ;
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $input->render($frame, $area, RenderContext::focused(Theme::dark()));

        self::assertSame(' ', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[5]);

        self::assertNotNull($frame->cursorPosition);
        self::assertSame(0, $frame->cursorPosition->col);
    }

    #[Test]
    public function renderAtNonZeroAreaPosition(): void
    {
        $input = TextInput::new()->withValue('hi');
        $frame = new Frame(20, 5);
        $area = new Rect(3, 2, 10, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        $index = 2 * 20 + 3;
        self::assertSame('h', $frame->characters[$index]);
        self::assertSame('i', $frame->characters[$index + 1]);
    }

    #[Test]
    public function renderCursorNotVisibleOutsideArea(): void
    {
        $input = TextInput::new()->withValue('hello');
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertNull($frame->cursorPosition);
    }

    #[Test]
    public function renderFocusedUsesBaseStyle(): void
    {
        $input = TextInput::new()->withValue('ab');
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $theme = Theme::dark();
        $input->render($frame, $area, RenderContext::focused($theme));

        self::assertSame($theme->base->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function renderUnfocusedUsesDimStyle(): void
    {
        $input = TextInput::new()->withValue('ab');
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $theme = Theme::dark();
        $input->render($frame, $area, new RenderContext($theme));

        self::assertSame($theme->dim->foregroundValue(), $frame->foregrounds[0]);
    }

    // --- Viewport scrolling (L290-307) ---

    #[Test]
    public function viewportScrollsLeftWhenCursorMovesBeforeOffset(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 5, 1);

        $current = $input;
        foreach (mb_str_split('abcdefgh') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        $current = $current->update(new KeyMsg(Key::Home), $area)->model;

        $frame = new Frame(5, 1);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('a', $frame->characters[0]);
        self::assertSame('b', $frame->characters[1]);
    }

    #[Test]
    public function viewportScrollsRightAsTypingExceedsWidth(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 3, 1);

        $current = $input;
        foreach (mb_str_split('abcde') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        $frame = new Frame(3, 1);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('d', $frame->characters[0]);
        self::assertSame('e', $frame->characters[1]);
    }

    #[Test]
    public function viewportWithZeroAvailableWidthReturnsZero(): void
    {
        $input = TextInput::new()->withValue('abc');
        $frame = new Frame(1, 1);
        $area = new Rect(0, 0, 0, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function viewportScrollsCorrectlyWithMultibyteCharacters(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 3, 1);

        $current = $input;
        foreach (["\u{4F60}", "\u{597D}", "\u{4E16}", "\u{754C}"] as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        self::assertSame("\u{4F60}\u{597D}\u{4E16}\u{754C}", $current->value());
        self::assertSame(4, $current->cursor());

        // Display: 你(0-1) 好(2-3) 世(4-5) 界(6-7), cursor at display col 8
        // Viewport offset = 8 - 3 + 1 = 6, showing display cols 6-8 => 界 + cursor
        $frame = new Frame(3, 1);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame("\u{754C}", $frame->characters[0]);
    }

    #[Test]
    public function scrollOffsetUpdatesOnCursorLeftPastViewport(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 5, 1);

        $current = $input;
        foreach (mb_str_split('abcdefghij') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        for ($i = 0; $i < 10; ++$i) {
            $current = $current->update(new KeyMsg(Key::Left), $area)->model;
        }

        self::assertSame(0, $current->cursor());

        $frame = new Frame(5, 1);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('a', $frame->characters[0]);
    }

    // --- Tick/blink edge cases (L153-162) ---

    #[Test]
    public function tickJustBelowBlinkIntervalDoesNotToggle(): void
    {
        $input = TextInput::new()->withValue('hi');
        $area = Rect::fromSize(20, 1);

        $result = $input->update(new TickMsg(0.529), $area);
        self::assertSame($input, $result->model);
    }

    // --- Concat / ConcatOperandRemoval in insert/delete ---

    #[Test]
    public function insertCharacterConcatenatesCorrectly(): void
    {
        $input = TextInput::new()->withValue('ac');
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        $result = $moved->update(new KeyMsg('b'), $area);

        self::assertSame('abc', $result->model->value());
    }

    #[Test]
    public function deleteCharBackwardConcatenatesCorrectly(): void
    {
        $input = TextInput::new()->withValue('abc');
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(2, $moved->cursor());

        $result = $moved->update(new KeyMsg(Key::Backspace), $area);
        self::assertSame('ac', $result->model->value());
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function deleteCharForwardConcatenatesCorrectly(): void
    {
        $input = TextInput::new()->withValue('abc');
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(1, $moved->cursor());

        $result = $moved->update(new KeyMsg(Key::Delete), $area);
        self::assertSame('ac', $result->model->value());
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function deleteWordBackwardConcatenatesCorrectly(): void
    {
        $input = TextInput::new()->withValue('foo bar baz');
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        for ($i = 0; $i < 7; ++$i) {
            $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        }
        self::assertSame(7, $moved->cursor());

        $result = $moved->update(new KeyMsg(Key::Backspace, ctrl: true), $area);
        self::assertSame('foo  baz', $result->model->value());
        self::assertSame(4, $result->model->cursor());
    }

    #[Test]
    public function deleteWordForwardConcatenatesCorrectly(): void
    {
        $input = TextInput::new()->withValue('foo bar baz');
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        for ($i = 0; $i < 4; ++$i) {
            $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        }
        self::assertSame(4, $moved->cursor());

        $result = $moved->update(new KeyMsg(Key::Delete, ctrl: true), $area);
        self::assertSame('foo  baz', $result->model->value());
        self::assertSame(4, $result->model->cursor());
    }

    #[Test]
    public function blinkTimeResetsOnKeypress(): void
    {
        $input = TextInput::new()->withValue('hi');
        $area = Rect::fromSize(20, 1);

        $afterType = $input->update(new KeyMsg('x'), $area)->model;

        $shortTick = $afterType->update(new TickMsg(0.4), $area);
        self::assertSame($afterType, $shortTick->model);
    }

    // --- Edge cases for viewport computeScrollOffset (L292-306) ---

    #[Test]
    public function scrollOffsetNeverGoesNegative(): void
    {
        $input = TextInput::new()->withValue('a');
        $area = new Rect(0, 0, 10, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;

        $frame = new Frame(10, 1);
        $moved->render($frame, $area, new RenderContext(Theme::dark()));
        self::assertSame('a', $frame->characters[0]);
    }

    #[Test]
    public function viewportScrollsExactlyOneCharacterPastWidth(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 3, 1);

        $current = $input;
        foreach (mb_str_split('abc') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        $frame = new Frame(3, 1);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('b', $frame->characters[0]);
        self::assertSame('c', $frame->characters[1]);
    }

    // --- Render visible text with viewport offset (L138) ---

    #[Test]
    public function renderVisibleTextSlicedByScrollOffset(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 4, 1);

        $current = $input;
        foreach (mb_str_split('abcdefg') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        $current = $current->update(new KeyMsg(Key::Home), $area)->model;
        for ($i = 0; $i < 3; ++$i) {
            $current = $current->update(new KeyMsg(Key::Right), $area)->model;
        }

        $frame = new Frame(4, 1);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('a', $frame->characters[0]);
        self::assertSame('b', $frame->characters[1]);
        self::assertSame('c', $frame->characters[2]);
        self::assertSame('d', $frame->characters[3]);
    }

    #[Test]
    public function cursorNotRenderedWhenScreenPositionBeforeAreaStart(): void
    {
        $input = TextInput::new();
        $area = new Rect(5, 0, 5, 1);

        $current = $input;
        foreach (mb_str_split('abcdefghij') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        $frame = new Frame(20, 1);
        $current->render($frame, $area, RenderContext::focused(Theme::dark()));

        self::assertNotNull($frame->cursorPosition);
        self::assertSame(9, $frame->cursorPosition->col);
    }

    #[Test]
    public function mouseClickWithScrollOffsetAccountsForScroll(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 5, 1);

        $current = $input;
        foreach (mb_str_split('abcdefghij') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        $mouseMessage = new MouseMsg(2, 0, MouseAction::Press, MouseButton::Left);
        $result = $current->update($mouseMessage, $area);

        self::assertSame(8, $result->model->cursor());
    }

    #[Test]
    public function scrollOffsetFormulaCursorMinusWidthPlusOne(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 4, 1);

        $current = $input;
        foreach (mb_str_split('abcde') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        $frame = new Frame(4, 1);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('c', $frame->characters[0]);
        self::assertSame('d', $frame->characters[1]);
        self::assertSame('e', $frame->characters[2]);
    }

    #[Test]
    public function cursorScreenPositionIsAreaXPlusCursorMinusScrollOffset(): void
    {
        $input = TextInput::new();
        $area = new Rect(2, 0, 5, 1);

        $current = $input;
        foreach (mb_str_split('abcdefgh') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        $frame = new Frame(10, 1);
        $current->render($frame, $area, RenderContext::focused(Theme::dark()));

        self::assertNotNull($frame->cursorPosition);
        self::assertSame(6, $frame->cursorPosition->col);
    }

    #[Test]
    public function deleteCharForwardWithMultibyteConcatenatesBeforeAndAfterCursorPlusOne(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{597D}\u{4E16}\u{754C}");
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;

        $result = $moved->update(new KeyMsg(Key::Delete), $area);
        self::assertSame("\u{4F60}\u{597D}\u{754C}", $result->model->value());
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function insertCharacterAtBeginningWithMultibyteSplitsCorrectly(): void
    {
        $input = TextInput::new()->withValue("\u{597D}\u{4E16}");
        $area = Rect::fromSize(40, 1);

        $atHome = $input->update(new KeyMsg(Key::Home), $area)->model;
        self::assertSame(0, $atHome->cursor());

        $result = $atHome->update(new KeyMsg("\u{4F60}"), $area);
        self::assertSame("\u{4F60}\u{597D}\u{4E16}", $result->model->value());
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function deleteBackwardWithMultibyteSplitsCorrectly(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{597D}\u{4E16}");
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;

        $result = $moved->update(new KeyMsg(Key::Backspace), $area);
        self::assertSame("\u{597D}\u{4E16}", $result->model->value());
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function deleteWordBackwardWithMultibyteFindsMbStrrpos(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{597D} \u{4E16}\u{754C} \u{1F600}");
        $area = Rect::fromSize(40, 1);

        $result = $input->update(new KeyMsg(Key::Backspace, ctrl: true), $area);
        self::assertSame("\u{4F60}\u{597D} \u{4E16}\u{754C} ", $result->model->value());
        self::assertSame(6, $result->model->cursor());
    }

    #[Test]
    public function deleteWordForwardWithMultibyteFindsMbStrpos(): void
    {
        $input = TextInput::new()->withValue("\u{4F60} \u{597D} \u{4E16}");
        $area = Rect::fromSize(40, 1);

        $atHome = $input->update(new KeyMsg(Key::Home), $area)->model;
        $result = $atHome->update(new KeyMsg(Key::Delete, ctrl: true), $area);
        self::assertSame(" \u{597D} \u{4E16}", $result->model->value());
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function deleteWordForwardDeleteCountUsesCorrectMbStringLengths(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{597D}  \u{4E16}\u{754C} end");
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;

        $result = $moved->update(new KeyMsg(Key::Delete, ctrl: true), $area);
        self::assertSame("\u{4F60}\u{597D} end", $result->model->value());
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function deleteWordBackwardNewBeforeUsesCorrectMbSubstr(): void
    {
        $input = TextInput::new()->withValue('ab cd ef');
        $area = Rect::fromSize(40, 1);

        $moved = $input->update(new KeyMsg(Key::Home), $area)->model;
        for ($i = 0; $i < 5; ++$i) {
            $moved = $moved->update(new KeyMsg(Key::Right), $area)->model;
        }
        self::assertSame(5, $moved->cursor());

        $result = $moved->update(new KeyMsg(Key::Backspace, ctrl: true), $area);
        self::assertSame('ab  ef', $result->model->value());
        self::assertSame(3, $result->model->cursor());
    }

    #[Test]
    public function renderTextStyleHasInputBgWhenStyled(): void
    {
        $input = TextInput::new()->withStyled()->withValue('ab');
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $theme = Theme::dark();
        $input->render($frame, $area, RenderContext::focused($theme));

        self::assertSame($theme->inputStyle->backgroundValue(), $frame->backgrounds[0]);
        self::assertSame($theme->base->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function renderStyledPlaceholderUsesInputStyleFaint(): void
    {
        $input = TextInput::new()->withPlaceholder('Hello')->withStyled();
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $theme = Theme::dark();
        $input->render($frame, $area, new RenderContext($theme));

        self::assertSame($theme->inputStyle->backgroundValue(), $frame->backgrounds[5]);
    }

    #[Test]
    public function renderStyledPlaceholderKeepsTheInputBackgroundWhileFocused(): void
    {
        $input = TextInput::new()->withPlaceholder('Hello')->withStyled();
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $theme = Theme::dark();
        $input->render($frame, $area, RenderContext::focused($theme));

        self::assertSame('H', $frame->characters[0]);
        self::assertSame($theme->inputStyle->backgroundValue(), $frame->backgrounds[5]);
        self::assertNotNull($frame->cursorPosition);
        self::assertSame(0, $frame->cursorPosition->col);
    }

    #[Test]
    public function renderPlaceholderNotShownWhenValuePresent(): void
    {
        $input = TextInput::new()->withPlaceholder('placeholder text');
        $input = $input->withValue('x')->withCursor(0);
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('x', $frame->characters[0]);
        self::assertNotSame('p', $frame->characters[0]);
    }

    #[Test]
    public function computeViewportCursorLessThanOffset(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 5, 1);

        $current = $input;
        foreach (mb_str_split('abcdefghij') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        for ($i = 0; $i < 5; ++$i) {
            $current = $current->update(new KeyMsg(Key::Left), $area)->model;
        }

        $frame = new Frame(5, 1);
        $current->render($frame, $area, new RenderContext(Theme::dark()));
        self::assertSame('f', $frame->characters[0]);
        self::assertSame('g', $frame->characters[1]);
    }

    #[Test]
    public function computeViewportCursorExactlyAtOffsetPlusWidth(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 5, 1);

        $current = $input;
        foreach (mb_str_split('abcde') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        $frame = new Frame(5, 1);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('b', $frame->characters[0]);
    }

    #[Test]
    public function renderTextLengthUsesCharacterCountNotByteCount(): void
    {
        $input = TextInput::new()
            ->withValue("\u{1F600}\u{1F601}")
            ->withCursor(2)
            ->withEchoMode(EchoMode::Password);
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $context = RenderContext::focused(Theme::dark());
        $input->render($frame, $area, $context);

        self::assertNotNull($frame->cursorPosition);
        self::assertSame(2, $frame->cursorPosition->col);
    }

    #[Test]
    public function placeholderReturnPreventsTextRendering(): void
    {
        $input = TextInput::new()->withPlaceholder('Enter...');
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $context = new RenderContext(Theme::dark());
        $input->render($frame, $area, $context);

        self::assertSame('E', $frame->characters[0]);
        self::assertSame('n', $frame->characters[1]);
        self::assertNull($frame->cursorPosition);
    }

    #[Test]
    public function endKeyWithMultibyteValueGoesToCharacterCount(): void
    {
        $multibyteValue = "\u{00E9}\u{00F1}\u{00FC}";
        $input = TextInput::new()->withValue($multibyteValue)->withCursor(0);
        $area = Rect::fromSize(20, 1);

        $result = $input->update(new KeyMsg(Key::End), $area);
        self::assertSame(3, $result->model->cursor());
        self::assertSame(mb_strlen($multibyteValue), $result->model->cursor());
        self::assertNotSame(strlen($multibyteValue), $result->model->cursor());
    }

    #[Test]
    public function clickAtPositionZeroSetsCursorToZero(): void
    {
        $input = TextInput::new()->withValue('hello')->withCursor(3);
        $area = new Rect(5, 0, 20, 1);
        $click = new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left);

        $result = $input->update($click, $area);
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function deleteWordForwardWithMultibyteCharacters(): void
    {
        $input = TextInput::new()->withValue("\u{00E9}\u{00F1} world")->withCursor(0);
        $area = Rect::fromSize(20, 1);

        $result = $input->update(new KeyMsg(Key::Delete, ctrl: true), $area);
        self::assertSame(' world', $result->model->value());
    }

    #[Test]
    public function deleteWordForwardMultibyteInAfterPortion(): void
    {
        $input = TextInput::new()->withValue("hi \u{00E9}\u{00F1}\u{00FC} end")->withCursor(3);
        $area = Rect::fromSize(20, 1);

        $result = $input->update(new KeyMsg(Key::Delete, ctrl: true), $area);
        self::assertSame('hi  end', $result->model->value());
    }

    /**
     * L32: DecrementInteger — charLimit defaults to 0 (unlimited).
     * If mutated to -1, the check charLimit > 0 && mb_strlen >= charLimit would
     * match -1 > 0 which is false, so it would behave the same... unless we actually set charLimit.
     * We need to verify that charLimit=0 means unlimited (can type infinite characters).
     */
    #[Test]
    public function defaultCharLimitIsZeroMeansUnlimited(): void
    {
        $input = TextInput::new();
        $area = Rect::fromSize(40, 1);
        $current = $input;

        // Type 20 characters — should all work with default limit of 0
        for ($i = 0; $i < 20; ++$i) {
            $current = $current->update(new KeyMsg('x'), $area)->model;
        }
        self::assertSame(20, mb_strlen($current->value()));

        // Now with charLimit = 1, only 1 char allowed
        $limited = TextInput::new()->withCharLimit(1);
        $limited = $limited->update(new KeyMsg('a'), $area)->model;
        self::assertSame('a', $limited->value());
        $limited = $limited->update(new KeyMsg('b'), $area)->model;
        self::assertSame('a', $limited->value()); // blocked by limit
    }

    /**
     * L135: MBString — mb_substr vs substr for placeholder truncation.
     * Multibyte placeholder must be properly truncated by character count, not byte count.
     */
    #[Test]
    public function placeholderTruncatesMultibyteByCharacterCount(): void
    {
        $input = TextInput::new()->withPlaceholder("\u{00FC}\u{00E9}\u{00F1}\u{00E4}");
        $frame = new Frame(3, 1);
        $area = new Rect(0, 0, 3, 1);
        $input->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame("\u{00FC}", $frame->characters[0]);
        self::assertSame("\u{00E9}", $frame->characters[1]);
        self::assertSame("\u{00F1}", $frame->characters[2]);
    }

    /**
     * L136: ReturnRemoval — return after placeholder rendering.
     * Without the return, the text rendering code below would also execute,
     * potentially overwriting the placeholder with cursor styling.
     */
    #[Test]
    public function placeholderReturnPreventsSubsequentCodeExecution(): void
    {
        $theme = Theme::dark();
        $input = TextInput::new()->withPlaceholder('Hello')->withDecoration(InputDecoration::None);
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $input->render($frame, $area, new RenderContext($theme));

        // Placeholder renders with dim style
        self::assertSame($theme->dim->foregroundValue(), $frame->foregrounds[0]);
        // If return is removed, the code below would render '' with base/dim style
        // and the cursor code wouldn't run (not focused), but the textStyle line
        // would overwrite foreground. Verify placeholder style is preserved.
        self::assertSame('H', $frame->characters[0]);
        self::assertSame('e', $frame->characters[1]);
    }

    /**
     * L277: MBString — strlen vs mb_strlen for deleteWordForward boundary check.
     * With multibyte value, strlen gives byte count, mb_strlen gives char count.
     * Cursor at char count should return null; with strlen, cursor < strlen would be true
     * even though we're past the end by character count.
     */
    #[Test]
    public function deleteWordForwardBoundaryUsesCharacterCount(): void
    {
        // Value "\u{00E9}" (1 character, 2 bytes). cursor=1 (at end by char count).
        // mb_strlen = 1, strlen = 2.
        // With mb_strlen: cursor(1) >= textLength(1) => return null (no deletion).
        // With strlen: cursor(1) >= textLength(2) => false => would try to delete.
        $input = TextInput::new()->withValue("\u{00E9}")->withCursor(1);
        $area = Rect::fromSize(20, 1);
        $result = $input->update(new KeyMsg(Key::Delete, ctrl: true), $area);
        // Should return same instance (null from deleteWordForward => no update)
        self::assertSame($input, $result->model);
    }

    #[Test]
    public function viewportScrollWhenCursorEqualsOffset(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 5, 1);

        $current = $input;
        foreach (mb_str_split('abcdefghij') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        for ($i = 0; $i < 10; ++$i) {
            $current = $current->update(new KeyMsg(Key::Left), $area)->model;
        }
        self::assertSame(0, $current->cursor());

        $current = $current->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(1, $current->cursor());

        $frame = new Frame(5, 1);
        $current->render($frame, $area, new RenderContext(Theme::dark()));
        self::assertSame('a', $frame->characters[0]);
    }

    #[Test]
    public function renderWideCharacterCursorPosition(): void
    {
        $input = TextInput::new()->withValue("\u{4F60}\u{597D}");
        $frame = new Frame(10, 1);
        $input->render($frame, Rect::fromSize(10, 1), RenderContext::focused(Theme::dark()));

        self::assertNotNull($frame->cursorPosition);
        self::assertSame(4, $frame->cursorPosition->col);
    }

    #[Test]
    public function scrolledViewportShowsCorrectSubstring(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 5, 1);

        $current = $input;
        foreach (mb_str_split('abcdefghij') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        $frame = new Frame(5, 1);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('g', $frame->characters[0]);
        self::assertSame('h', $frame->characters[1]);
        self::assertSame('i', $frame->characters[2]);
        self::assertSame('j', $frame->characters[3]);
    }

    #[Test]
    public function scrolledViewportTruncatesAtExactWidth(): void
    {
        $input = TextInput::new();
        $area = new Rect(0, 0, 5, 1);

        $current = $input;
        foreach (mb_str_split('abcdefghijklmnopqrst') as $character) {
            $current = $current->update(new KeyMsg($character), $area)->model;
        }

        for ($i = 0; $i < 10; ++$i) {
            $current = $current->update(new KeyMsg(Key::Left), $area)->model;
        }

        $frame = new Frame(10, 1);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('k', $frame->characters[0]);
        self::assertSame('o', $frame->characters[4]);
        self::assertSame(' ', $frame->characters[5]);
    }

    #[Test]
    public function scrolledViewportWithWideCharsRespectsOffset(): void
    {
        $input = TextInput::new()
            ->withValue("ab\u{4E16}\u{754C}cd")
        ;
        $area = new Rect(0, 0, 4, 1);

        $current = $input;
        for ($i = 0; $i < 6; ++$i) {
            $current = $current->update(new KeyMsg(Key::Right), $area)->model;
        }

        $frame = new Frame(4, 1);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('c', $frame->characters[0]);
        self::assertSame('d', $frame->characters[1]);
    }

    #[Test]
    public function backspaceRemovesFullGrapheme(): void
    {
        $input = TextInput::new()->withValue("cafe\u{0301}");
        $result = $input->update(new KeyMsg(key: Key::Backspace), Rect::fromSize(20, 1));
        self::assertSame('caf', $result->model->value());
    }

    #[Test]
    public function renderSetsCursorPositionOnBuffer(): void
    {
        $input = TextInput::new()->withValue('Hello');
        $frame = new Frame(20, 1);
        $input->render($frame, Rect::fromSize(20, 1), RenderContext::focused(Theme::dark()));
        self::assertNotNull($frame->cursorPosition);
        self::assertSame(5, $frame->cursorPosition->col);
        self::assertSame(0, $frame->cursorPosition->row);
    }

    #[Test]
    public function wordDeleteBackwardKeepsWholeComposedCharacters(): void
    {
        $area = Rect::fromSize(40, 1);
        $input = TextInput::new()
                        ->withValue("cafe\u{0301} bar")
            ->withCursor(8);

        $updated = $input->update(new KeyMsg(Key::Backspace, ctrl: true), $area)->model;

        self::assertSame("cafe\u{0301} ", $updated->value());
        self::assertSame(5, $updated->cursor());
    }

    #[Test]
    public function wordDeleteForwardKeepsTheSpaceBehindAWordWithComposedCharacters(): void
    {
        $area = Rect::fromSize(40, 1);
        $input = TextInput::new()
                        ->withValue("a\u{0301}bc def")
            ->withCursor(0);

        $updated = $input->update(new KeyMsg(Key::Delete, ctrl: true), $area)->model;

        self::assertSame(' def', $updated->value());
    }

    public static function textInputKeybindingOverrideProvider(): iterable
    {
        yield Actions::CURSOR_LEFT => [Actions::CURSOR_LEFT,           new KeyMsg(Key::Left),                  new KeyMsg('b', ctrl: true), 'ctrl+b', 'hello',   3, fn (TextInput $t) => $t->cursor() === 2];
        yield Actions::CURSOR_RIGHT => [Actions::CURSOR_RIGHT,          new KeyMsg(Key::Right),                 new KeyMsg('f', ctrl: true), 'ctrl+f', 'hello',   2, fn (TextInput $t) => $t->cursor() === 3];
        yield Actions::LINE_START => [Actions::LINE_START,            new KeyMsg(Key::Home),                  new KeyMsg('a', ctrl: true), 'ctrl+a', 'hello',   5, fn (TextInput $t) => $t->cursor() === 0];
        yield Actions::LINE_END => [Actions::LINE_END,              new KeyMsg(Key::End),                   new KeyMsg('e', ctrl: true), 'ctrl+e', 'hello',   0, fn (TextInput $t) => $t->cursor() === 5];
        yield Actions::DELETE_CHAR_BACKWARD => [Actions::DELETE_CHAR_BACKWARD,  new KeyMsg(Key::Backspace),             new KeyMsg('h', ctrl: true), 'ctrl+h', 'hello',   5, fn (TextInput $t) => $t->value() === 'hell'];
        yield Actions::DELETE_CHAR_FORWARD => [Actions::DELETE_CHAR_FORWARD,   new KeyMsg(Key::Delete),                new KeyMsg('q', ctrl: true), 'ctrl+q', 'hello',   0, fn (TextInput $t) => $t->value() === 'ello'];
        yield Actions::DELETE_WORD_BACKWARD => [Actions::DELETE_WORD_BACKWARD,  new KeyMsg(Key::Backspace, ctrl: true), new KeyMsg('w', ctrl: true), 'ctrl+w', 'one two', 7, fn (TextInput $t) => $t->value() === 'one '];
        yield Actions::DELETE_WORD_FORWARD => [Actions::DELETE_WORD_FORWARD,   new KeyMsg(Key::Delete, ctrl: true),    new KeyMsg('d', ctrl: true), 'ctrl+d', 'one two', 0, fn (TextInput $t) => $t->value() === ' two'];
    }

    #[DataProvider('textInputKeybindingOverrideProvider')]
    #[Test]
    public function supportsCustomKeybinding(
        string $action,
        KeyMsg $defaultKey,
        KeyMsg $customKey,
        string $customPattern,
        string $initialValue,
        int $startingCursor,
        Closure $assertExpected,
    ): void {
        $area = Rect::fromSize(40, 1);
        $widget = TextInput::new()
                        ->withValue($initialValue)
            ->withCursor($startingCursor);

        $afterDefault = $widget->update($defaultKey, $area)->model;
        self::assertNotSame($widget, $afterDefault, "default key for $action must change state");
        self::assertTrue($assertExpected($afterDefault));

        $custom = $widget->withKeybindings(new Keybindings([$action => [$customPattern]]));
        self::assertSame($custom, $custom->update($defaultKey, $area)->model);

        $afterCustom = $custom->update($customKey, $area)->model;
        self::assertNotSame($custom, $afterCustom);
        self::assertTrue($assertExpected($afterCustom));
    }

    #[Test]
    public function printableCharactersStillInsertAfterMigration(): void
    {
        $area = Rect::fromSize(40, 1);
        $widget = TextInput::new();

        $afterA = $widget->update(new KeyMsg('a'), $area)->model;
        self::assertSame('a', $afterA->value());

        $custom = $widget->withKeybindings(new Keybindings([
            Actions::CURSOR_LEFT => ['ctrl+b'],
        ]));
        $afterAcustom = $custom->update(new KeyMsg('a'), $area)->model;
        self::assertSame('a', $afterAcustom->value());
    }

    #[Test]
    public function homeAtCursorZeroIsANoOpAndKeepsTheViewport(): void
    {
        $input = TextInput::new()->withValue('abcdefghij')->withCursor(0);
        $result = $input->update(new KeyMsg(Key::Home), new Rect(0, 0, 5, 1))->model;

        self::assertSame($input, $result, 'Home with the cursor already at 0 must not recompute the viewport');
    }

    #[Test]
    public function endAtTextEndIsANoOpAndKeepsTheViewport(): void
    {
        $input = TextInput::new()->withValue('abcdefghij');
        $result = $input->update(new KeyMsg(Key::End), new Rect(0, 0, 5, 1))->model;

        self::assertSame($input, $result, 'End with the cursor already at the end must not recompute the viewport');
    }

    #[Test]
    public function deleteForwardAtTextEndIsANoOpAndKeepsTheViewport(): void
    {
        $input = TextInput::new()->withValue('abcdefghij');
        $result = $input->update(new KeyMsg(Key::Delete), new Rect(0, 0, 5, 1))->model;

        self::assertSame($input, $result, 'Delete at the end of the text has nothing to delete and must change nothing');
    }

    #[Test]
    public function deleteWordForwardAtTextEndIsANoOpAndKeepsTheViewport(): void
    {
        $input = TextInput::new()->withValue('abcdefghij');
        $result = $input->update(new KeyMsg(Key::Delete, ctrl: true), new Rect(0, 0, 5, 1))->model;

        self::assertSame($input, $result, 'Ctrl+Delete at the end of the text must change nothing');
    }

    #[Test]
    public function deleteWordBackwardAtCursorZeroIsANoOpAndKeepsTheViewport(): void
    {
        $input = TextInput::new()->withValue('abcdefghij')->withCursor(0);
        $result = $input->update(new KeyMsg(Key::Backspace, ctrl: true), new Rect(0, 0, 5, 1))->model;

        self::assertSame($input, $result, 'Ctrl+Backspace at position 0 has nothing to delete and must change nothing');
    }

    #[Test]
    public function controlUEmptiesTheFieldTheWayItDoesAtAnyPrompt(): void
    {
        $filled = TextInput::new()->withValue('one two')->withCursor(3);

        $cleared = $filled->update(new KeyMsg('u', ctrl: true), Rect::fromSize(40, 1))->model;

        self::assertSame('', $cleared->value());
        self::assertSame(0, $cleared->cursor(), 'and the caret comes back to the start with it');
    }

    /**
     * The runtime turns a bracketed paste into a PasteMsg, and nothing read one, so
     * pasting into a field did nothing at all. Breaks and tabs become spaces, since
     * one control character in a one-line field is drawn as a hole and submitted with
     * the answer, and the char limit holds for a paste as it does for typing.
     */
    #[Test]
    public function pastedTextIsInsertedWhereTheCursorIs(): void
    {
        $area = new Rect(0, 0, 40, 1);
        $field = TextInput::new()->withValue('ab');

        self::assertSame('abXY', $field->update(new PasteMsg('XY'), $area)->model->value());
        self::assertSame(
            'one two three',
            TextInput::new()->update(new PasteMsg("one\r\ntwo\tthree"), $area)->model->value(),
            'a line break from the clipboard is a space',
        );
        self::assertSame(
            'abX',
            $field->withCharLimit(3)->update(new PasteMsg('XYZ'), $area)->model->value(),
            'a limit typing cannot pass is not one a paste walks through',
        );
    }
}
