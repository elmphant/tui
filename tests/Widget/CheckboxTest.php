<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Choice\Checkbox;
use Elmphant\Tui\Widget\Focusable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckboxTest extends TestCase
{
    use FocusableBehaviorTrait;

    #[Test]
    public function itNamesTheKeysItAnswers(): void
    {
        self::assertSame(['Enter' => 'toggle'], Checkbox::new('agree')->hints());
    }

    #[Test]
    public function implementsFocusable(): void
    {
        self::assertInstanceOf(Focusable::class, Checkbox::new('Accept'));
    }

    #[Test]
    public function constraintReturnsExactOne(): void
    {
        $checkbox = Checkbox::new('Accept');
        $constraint = $checkbox->sizing()->rows;
        self::assertSame(1, $constraint->min);
        self::assertSame(1, $constraint->max);
    }

    #[Test]
    public function startsUnchecked(): void
    {
        self::assertFalse(Checkbox::new('Accept')->isChecked());
    }

    #[Test]
    public function withCheckedSetsState(): void
    {
        $checkbox = Checkbox::new('Accept')->withChecked(true);
        self::assertTrue($checkbox->isChecked());
    }

    #[Test]
    public function withCheckedReturnsNewInstance(): void
    {
        $checkbox = Checkbox::new('Accept');
        $checked = $checkbox->withChecked(true);
        self::assertNotSame($checkbox, $checked);
    }

    #[Test]
    public function label(): void
    {
        self::assertSame('Accept', Checkbox::new('Accept')->label());
    }

    #[Test]
    public function withLabelReplacesTheLabelAndElidesNoOps(): void
    {
        $checkbox = Checkbox::new('Before');
        $modified = $checkbox->withLabel('After');

        self::assertSame('After', $modified->label());
        self::assertSame($modified, $modified->withLabel('After'));
    }

    #[Test]
    public function enterTogglesChecked(): void
    {
        $checkbox = Checkbox::new('Accept')->withFocus(true);
        $area = Rect::fromSize(40, 1);

        $result = $checkbox->update(new KeyMsg(Key::Enter), $area);
        self::assertTrue($result->model->isChecked());

        $result = $result->model->update(new KeyMsg(Key::Enter), $area);
        self::assertFalse($result->model->isChecked());
    }

    #[Test]
    public function spaceTogglesChecked(): void
    {
        $checkbox = Checkbox::new('Accept')->withFocus(true);
        $area = Rect::fromSize(40, 1);

        $result = $checkbox->update(new KeyMsg(' '), $area);
        self::assertTrue($result->model->isChecked());
    }

    #[Test]
    public function ignoresKeyWhenNotFocused(): void
    {
        $checkbox = Checkbox::new('Accept');
        $area = Rect::fromSize(40, 1);
        $result = $checkbox->update(new KeyMsg(Key::Enter), $area);
        self::assertSame($checkbox, $result->model);
    }

    #[Test]
    public function unhandledKeyReturnsSelf(): void
    {
        $checkbox = Checkbox::new('Accept')->withFocus(true);
        $area = Rect::fromSize(40, 1);
        $result = $checkbox->update(new KeyMsg('x'), $area);
        self::assertSame($checkbox, $result->model);
    }

    #[Test]
    public function mouseClickToggles(): void
    {
        $checkbox = Checkbox::new('Accept');
        $area = new Rect(0, 0, 40, 1);

        $mouseMessage = new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left);
        $result = $checkbox->update($mouseMessage, $area);
        self::assertTrue($result->model->isChecked());

        $result = $result->model->update($mouseMessage, $area);
        self::assertFalse($result->model->isChecked());
    }

    #[Test]
    public function mouseClickOutsideDoesNothing(): void
    {
        $checkbox = Checkbox::new('Accept');
        $area = new Rect(0, 0, 40, 1);

        $mouseMessage = new MouseMsg(5, 3, MouseAction::Press, MouseButton::Left);
        $result = $checkbox->update($mouseMessage, $area);
        self::assertSame($checkbox, $result->model);
    }

    #[Test]
    public function rendersUncheckedBox(): void
    {
        $checkbox = Checkbox::new('Accept')->withFocus(true);
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $checkbox->render($frame, $area, new RenderContext(Theme::dark()));

        $row = '';
        for ($column = 0; $column < 12; ++$column) {
            $row .= $frame->characters[$column];
        }
        self::assertStringContainsString('[ ]', $row);
        self::assertStringContainsString('Accept', $row);
    }

    #[Test]
    public function rendersCheckedBox(): void
    {
        $checkbox = Checkbox::new('Accept')->withChecked(true);
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 1);
        $checkbox->render($frame, $area, new RenderContext(Theme::dark()));

        $row = '';
        for ($column = 0; $column < 12; ++$column) {
            $row .= $frame->characters[$column];
        }
        self::assertStringContainsString('[x]', $row);
    }

    #[Test]
    public function focusedCheckboxUseCursorStyle(): void
    {
        $theme = Theme::dark();
        $checkbox = Checkbox::new('A')->withFocus(true);
        $frame = new Frame(20, 1);
        $checkbox->render($frame, new Rect(0, 0, 20, 1), RenderContext::focused($theme));

        $expectedForeground = $theme->cursorStyle->foregroundValue();
        self::assertSame($expectedForeground, $frame->foregrounds[0]);
    }

    #[Test]
    public function checkedUnfocusedCheckboxUsesAccentStyle(): void
    {
        $theme = Theme::dark();
        $checkbox = Checkbox::new('A')->withChecked(true);
        $frame = new Frame(20, 1);
        $checkbox->render($frame, new Rect(0, 0, 20, 1), new RenderContext($theme));

        $expectedForeground = $theme->accent->foregroundValue();
        self::assertSame($expectedForeground, $frame->foregrounds[0]);
    }

    #[Test]
    public function uncheckedUnfocusedCheckboxUsesDimStyle(): void
    {
        $theme = Theme::dark();
        $checkbox = Checkbox::new('A');
        $frame = new Frame(20, 1);
        $checkbox->render($frame, new Rect(0, 0, 20, 1), new RenderContext($theme));

        $expectedForeground = $theme->dim->foregroundValue();
        self::assertSame($expectedForeground, $frame->foregrounds[0]);
    }

    #[Test]
    public function renderStylesDifferBetweenFocusedCheckedAndDefault(): void
    {
        $theme = Theme::dark();
        $area = new Rect(0, 0, 20, 1);

        $focusedBuffer = new Frame(20, 1);
        Checkbox::new('A')->withFocus(true)->render($focusedBuffer, $area, RenderContext::focused($theme));

        $checkedBuffer = new Frame(20, 1);
        Checkbox::new('A')->withChecked(true)->render($checkedBuffer, $area, new RenderContext($theme));

        $defaultBuffer = new Frame(20, 1);
        Checkbox::new('A')->render($defaultBuffer, $area, new RenderContext($theme));

        self::assertNotSame($focusedBuffer->foregrounds[0], $checkedBuffer->foregrounds[0]);
        self::assertNotSame($checkedBuffer->foregrounds[0], $defaultBuffer->foregrounds[0]);
        self::assertNotSame($focusedBuffer->foregrounds[0], $defaultBuffer->foregrounds[0]);
    }

    #[Test]
    public function labelStartsAfterCheckMark(): void
    {
        $checkbox = Checkbox::new('Hi')->withFocus(true);
        $frame = new Frame(20, 1);
        // Use non-zero x offset so $column = putStyled() differs from $column += putStyled()
        $checkbox->render($frame, new Rect(2, 0, 18, 1), new RenderContext(Theme::dark()));

        // Mark "[ ] " at x=2: [=2, ' '=3, ]=4, ' '=5
        self::assertSame('[', $frame->characters[2]);
        self::assertSame(']', $frame->characters[4]);
        self::assertSame(' ', $frame->characters[5]);
        // Label starts at column 6 (2 + 4)
        self::assertSame('H', $frame->characters[6]);
        self::assertSame('i', $frame->characters[7]);
    }

    #[Test]
    public function renderWithZeroWidthDoesNotCrash(): void
    {
        $checkbox = Checkbox::new('Accept');
        $frame = new Frame(20, 1);
        $checkbox->render($frame, new Rect(0, 0, 0, 1), new RenderContext(Theme::dark()));
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function renderWithZeroHeightDoesNotCrash(): void
    {
        $checkbox = Checkbox::new('Accept');
        $frame = new Frame(20, 1);
        $checkbox->render($frame, new Rect(0, 0, 20, 0), new RenderContext(Theme::dark()));
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function renderTruncatesLabelToAreaWidth(): void
    {
        $checkbox = Checkbox::new('Very Long Label Text');
        $frame = new Frame(15, 1);
        $checkbox->render($frame, new Rect(0, 0, 10, 1), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[10]);
    }

    #[Test]
    public function renderWithWidthEqualToMarkShowsNoLabel(): void
    {
        $checkbox = Checkbox::new('Label');
        $frame = new Frame(10, 1);
        $checkbox->render($frame, new Rect(0, 0, 4, 1), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[4]);
    }

    #[Test]
    public function renderWithWidthSmallerThanMarkTruncatesMark(): void
    {
        $checkbox = Checkbox::new('Label');
        $frame = new Frame(10, 1);
        $checkbox->render($frame, new Rect(0, 0, 2, 1), new RenderContext(Theme::dark()));

        self::assertSame('[', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2]);
    }

    #[Test]
    public function supportsCustomToggleKeybinding(): void
    {
        $area = Rect::fromSize(20, 1);
        $widget = Checkbox::new('label')->withFocus(true);

        $afterEnter = $widget->update(new KeyMsg(Key::Enter), $area)->model;
        self::assertTrue($afterEnter->isChecked());

        $custom = $widget->withKeybindings(new Keybindings([Actions::TOGGLE => ['x']]));
        $afterDefaultOnCustom = $custom->update(new KeyMsg(Key::Enter), $area)->model;
        self::assertFalse($afterDefaultOnCustom->isChecked());

        $afterCustom = $custom->update(new KeyMsg('x'), $area)->model;
        self::assertTrue($afterCustom->isChecked());
    }

    protected function createFocusable(): Focusable
    {
        return Checkbox::new('Accept');
    }
}
