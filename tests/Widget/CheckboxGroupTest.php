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
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\WidgetHarness;
use Elmphant\Tui\Widget\Choice\CheckboxGroup;
use Elmphant\Tui\Widget\Focusable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckboxGroupTest extends TestCase
{
    #[Test]
    public function itNamesTheKeysItAnswers(): void
    {
        self::assertSame(
            ['↑/↓' => 'move', 'Enter' => 'toggle'],
            CheckboxGroup::new([])->hints(),
        );
    }

    #[Test]
    public function aSqueezedGroupScrollsWithinItsOwnRect(): void
    {
        $area = new Rect(0, 1, 30, 3);
        $group = CheckboxGroup::new(['one', 'two', 'three', 'four', 'five']);
        for ($step = 0; $step < 4; ++$step) {
            $group = $group->update(new KeyMsg(Key::Down), $area)->model;
        }
        self::assertInstanceOf(CheckboxGroup::class, $group);

        $frame = new Frame(30, 5);
        $group->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame(str_repeat(' ', 30), $this->extractRow($frame, 0, 30), 'nothing may be painted above the rect');
        self::assertStringContainsString('three', $this->extractRow($frame, 1, 30));
        self::assertStringContainsString('four', $this->extractRow($frame, 2, 30));
        self::assertStringContainsString('five', $this->extractRow($frame, 3, 30));
    }

    #[Test]
    public function aLongOptionStopsAtTheRectInsteadOfBleedingPastIt(): void
    {
        $frame = new Frame(20, 2);
        $group = CheckboxGroup::new(['a very long option label', 'b']);

        $group->render($frame, new Rect(0, 0, 10, 2), new RenderContext(Theme::dark()));

        self::assertSame(str_repeat(' ', 10), substr($this->extractRow($frame, 0, 20), 10), 'no ink past column 10');
        self::assertStringContainsString('b', $this->extractRow($frame, 1, 20));
    }

    #[Test]
    public function aWrappingGroupComesRoundFromItsFirstOptionToItsLast(): void
    {
        $group = CheckboxGroup::new(['a', 'b', 'c'])->withWrapping();

        $moved = $group->update(new KeyMsg(Key::Up), new Rect(0, 0, 20, 6))->model;

        self::assertInstanceOf(CheckboxGroup::class, $moved);
        self::assertSame(2, $moved->cursor(), 'A short list a reader may wrap should wrap here too, not only in a Select');
    }

    #[Test]
    public function anIndexPastTheLastOptionIsNeverChecked(): void
    {
        $group = CheckboxGroup::new(['A', 'B'])->withCheckedIndexes([1, 5, -1]);

        self::assertFalse($group->isChecked(5));
        self::assertFalse($group->isChecked(-1));
        self::assertSame(['B'], $group->checkedValues());
    }

    #[Test]
    public function takesTheOptionsFirstAndLeavesTheLabelOptional(): void
    {
        $bare = CheckboxGroup::new(['A', 'B']);

        self::assertSame(['A'], $bare->withCheckedIndexes([0])->checkedValues(), 'the collection is what leads');
        self::assertSame(0, $bare->cursorLine(40), 'and an omitted label costs no rows');
        self::assertSame(2, CheckboxGroup::new(['A', 'B'], 'Pick')->cursorLine(40), 'a label still follows it, where there is one');
    }

    #[Test]
    public function theCursorRowSkipsTheLabelAndItsBlankLine(): void
    {
        $area = new Rect(0, 0, 20, 8);
        $labelled = CheckboxGroup::new(['Cheese', 'Onion', 'Pepper'], 'Toppings');

        self::assertSame(2, $labelled->cursorLine(40), 'A label and its gap sit above the first option');

        $moved = $labelled->update(new KeyMsg(Key::Down), $area)->model;
        self::assertInstanceOf(CheckboxGroup::class, $moved);
        self::assertSame(1, $moved->cursor());
        self::assertSame(3, $moved->cursorLine(40), 'Which the cursor row keeps accounting for');
    }

    #[Test]
    public function withoutALabelTheCursorRowIsTheCursorItself(): void
    {
        $area = new Rect(0, 0, 20, 8);
        $unlabelled = CheckboxGroup::new(['Cheese', 'Onion']);

        self::assertSame(0, $unlabelled->cursorLine(40));

        $moved = $unlabelled->update(new KeyMsg(Key::Down), $area)->model;
        self::assertInstanceOf(CheckboxGroup::class, $moved);
        self::assertSame(1, $moved->cursorLine(40));
    }

    #[Test]
    public function theOptionsLabelAndTicksCanAllBeReplaced(): void
    {
        $area = new Rect(0, 0, 24, 8);
        $group = CheckboxGroup::new(['Cheese'], 'Toppings')
            ->withOptions(['Olive', 'Caper'])
            ->withLabel('Extras')
            ->withCheckedIndexes([1]);

        $frame = new Frame(24, 8);
        $group->render($frame, $area, new RenderContext(Theme::dark()));

        $screen = '';
        for ($cell = 0; $cell < 24 * 8; ++$cell) {
            $screen .= $frame->characters[$cell];
        }

        self::assertStringContainsString('Extras', $screen);
        self::assertStringContainsString('Olive', $screen);
        self::assertStringContainsString('Caper', $screen);
        self::assertStringNotContainsString('Cheese', $screen, 'The original option is gone');
        self::assertSame(['Caper'], $group->checkedValues(), 'And the second option is the ticked one');
    }

    #[Test]
    public function implementsFocusable(): void
    {
        self::assertInstanceOf(Focusable::class, CheckboxGroup::new(['Cheese', 'Onion', 'Pepper'], 'Toppings'));
    }

    #[Test]
    public function constraintIncludesLabelAndSpacer(): void
    {
        $group = CheckboxGroup::new(['Cheese', 'Onion', 'Pepper'], 'Toppings');
        $constraint = $group->sizing()->rows;

        self::assertSame(5, $constraint->min);
        self::assertSame(5, $constraint->max);
    }

    #[Test]
    public function constraintWithoutLabel(): void
    {
        $group = CheckboxGroup::new(['A', 'B']);
        $constraint = $group->sizing()->rows;

        self::assertSame(2, $constraint->min);
        self::assertSame(2, $constraint->max);
    }

    #[Test]
    public function cursorMovesDownWithArrowKey(): void
    {
        $group = CheckboxGroup::new(['A', 'B', 'C'], 'Pick');
        $area = Rect::fromSize(40, 4);

        $result = $group->update(new KeyMsg(Key::Down), $area);

        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function cursorMovesUpWithArrowKey(): void
    {
        $group = CheckboxGroup::new(['A', 'B', 'C'], 'Pick');
        $area = Rect::fromSize(40, 4);

        $group = $group->update(new KeyMsg(Key::Down), $area)->model;
        $result = $group->update(new KeyMsg(Key::Up), $area);

        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function cursorDoesNotGoBelowLastOption(): void
    {
        $group = CheckboxGroup::new(['A', 'B'], 'Pick');
        $area = Rect::fromSize(40, 3);

        $group = $group->update(new KeyMsg(Key::Down), $area)->model;
        $result = $group->update(new KeyMsg(Key::Down), $area);

        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function cursorDoesNotGoAboveFirstOption(): void
    {
        $group = CheckboxGroup::new(['A', 'B'], 'Pick');
        $area = Rect::fromSize(40, 3);

        $result = $group->update(new KeyMsg(Key::Up), $area);

        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function enterTogglesCheckedState(): void
    {
        $group = CheckboxGroup::new(['A', 'B', 'C'], 'Pick');
        $area = Rect::fromSize(40, 4);

        $result = $group->update(new KeyMsg(Key::Enter), $area);

        self::assertTrue($result->model->isChecked(0));
    }

    #[Test]
    public function spaceTogglesCheckedState(): void
    {
        $group = CheckboxGroup::new(['A', 'B'], 'Pick');
        $area = Rect::fromSize(40, 3);

        $result = $group->update(new KeyMsg(' '), $area);

        self::assertTrue($result->model->isChecked(0));
    }

    #[Test]
    public function toggleUnchecksAlreadyCheckedOption(): void
    {
        $group = CheckboxGroup::new(['A', 'B'], 'Pick');
        $area = Rect::fromSize(40, 3);

        $group = $group->update(new KeyMsg(Key::Enter), $area)->model;
        $result = $group->update(new KeyMsg(Key::Enter), $area);

        self::assertFalse($result->model->isChecked(0));
    }

    #[Test]
    public function multipleOptionsCanBeChecked(): void
    {
        $group = CheckboxGroup::new(['A', 'B', 'C'], 'Pick');
        $area = Rect::fromSize(40, 4);

        $group = $group->update(new KeyMsg(Key::Enter), $area)->model;
        $group = $group->update(new KeyMsg(Key::Down), $area)->model;
        $group = $group->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertTrue($group->isChecked(0));
        self::assertTrue($group->isChecked(1));
        self::assertFalse($group->isChecked(2));
    }

    #[Test]
    public function checkedValuesReturnsSelectedStrings(): void
    {
        $group = CheckboxGroup::new(['A', 'B', 'C'], 'Pick');
        $area = Rect::fromSize(40, 4);

        $group = $group->update(new KeyMsg(Key::Enter), $area)->model;
        $group = $group->update(new KeyMsg(Key::Down), $area)->model;
        $group = $group->update(new KeyMsg(Key::Down), $area)->model;
        $group = $group->update(new KeyMsg(Key::Enter), $area)->model;

        self::assertSame(['A', 'C'], $group->checkedValues());
    }

    #[Test]
    public function tickedValuesComeInTheOrderTheOptionsAreOffered(): void
    {
        $ticked = WidgetHarness::for(CheckboxGroup::new(['A', 'B', 'C']), 40, 5)->press('down', 'down', 'enter', 'up', 'up', 'enter');

        self::assertSame(['A', 'C'], $ticked->widget()->value());
    }

    #[Test]
    public function mouseClickTogglesOption(): void
    {
        $group = CheckboxGroup::new(['A', 'B', 'C'], 'Pick');
        $area = new Rect(0, 0, 40, 6);

        $click = new MouseMsg(5, 3, MouseAction::Press, MouseButton::Left);
        $result = $group->update($click, $area);

        self::assertTrue($result->model->isChecked(1));
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function mouseClickOutsideDoesNothing(): void
    {
        $group = CheckboxGroup::new(['A', 'B'], 'Pick');
        $area = new Rect(0, 0, 40, 3);

        $click = new MouseMsg(5, 10, MouseAction::Press, MouseButton::Left);
        $result = $group->update($click, $area);

        self::assertSame($group, $result->model);
    }

    #[Test]
    public function rendersLabelAndOptions(): void
    {
        $group = CheckboxGroup::new(['Cheese', 'Onion'], 'Toppings');
        $frame = new Frame(30, 4);
        $area = new Rect(0, 0, 30, 4);

        $group->render($frame, $area, new RenderContext(Theme::dark()));

        $labelRow = '';
        for ($column = 0; $column < 10; ++$column) {
            $labelRow .= $frame->characters[$column];
        }
        self::assertStringContainsString('Toppings', $labelRow);

        // Options start at row 2 (label + blank line)
        $optionRow = '';
        for ($column = 0; $column < 15; ++$column) {
            $optionRow .= $frame->characters[60 + $column];
        }
        self::assertStringContainsString('Cheese', $optionRow);
        self::assertStringContainsString('[ ]', $optionRow);
    }

    #[Test]
    public function rendersCheckedMark(): void
    {
        $group = CheckboxGroup::new(['A', 'B']);
        $area = Rect::fromSize(30, 2);

        $group = $group->update(new KeyMsg(Key::Enter), $area)->model;

        $frame = new Frame(30, 2);
        $group->render($frame, new Rect(0, 0, 30, 2), new RenderContext(Theme::dark()));

        $firstRow = '';
        for ($column = 0; $column < 10; ++$column) {
            $firstRow .= $frame->characters[$column];
        }
        self::assertStringContainsString('[x]', $firstRow);
    }

    #[Test]
    public function upKeyReturnsDifferentModelWhenCursorAboveZero(): void
    {
        $group = CheckboxGroup::new(['A', 'B', 'C'], 'Pick');
        $area = Rect::fromSize(40, 5);

        $group = $group->update(new KeyMsg(Key::Down), $area)->model;
        self::assertSame(1, $group->cursor());

        $result = $group->update(new KeyMsg(Key::Up), $area);
        self::assertSame(0, $result->model->cursor());
        self::assertNotSame($group, $result->model);
    }

    #[Test]
    public function downKeyReturnsDifferentModelWhenNotAtEnd(): void
    {
        $group = CheckboxGroup::new(['A', 'B'], 'Pick');
        $area = Rect::fromSize(40, 4);

        $result = $group->update(new KeyMsg(Key::Down), $area);
        self::assertNotSame($group, $result->model);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function enterKeyReturnsDifferentModelWithToggle(): void
    {
        $group = CheckboxGroup::new(['A'], 'Pick');
        $area = Rect::fromSize(40, 3);

        $result = $group->update(new KeyMsg(Key::Enter), $area);
        self::assertNotSame($group, $result->model);
        self::assertTrue($result->model->isChecked(0));
    }

    #[Test]
    public function unknownKeyReturnsSameModel(): void
    {
        $group = CheckboxGroup::new(['A', 'B'], 'Pick');
        $area = Rect::fromSize(40, 4);

        $result = $group->update(new KeyMsg('z'), $area);
        self::assertSame($group, $result->model);
    }

    #[Test]
    public function mouseClickWithOffsetAreaComputesCorrectLocalY(): void
    {
        $group = CheckboxGroup::new(['A', 'B', 'C'], 'Label');
        $area = new Rect(5, 10, 30, 6);

        // Label at y=10, blank at y=11, options at y=12,13,14
        // Click option B at y=13 => localY=3, optionY=1
        $click = new MouseMsg(10, 13, MouseAction::Press, MouseButton::Left);
        $result = $group->update($click, $area);

        self::assertTrue($result->model->isChecked(1));
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function mouseClickOnLabelAreaDoesNotToggle(): void
    {
        $group = CheckboxGroup::new(['A', 'B'], 'Label');
        $area = new Rect(0, 0, 30, 4);

        // Click on label row (y=0) => localY=0, optionY=0-2=-2 < 0
        $click = new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left);
        $result = $group->update($click, $area);

        self::assertSame($group, $result->model);
    }

    #[Test]
    public function aClickOnTheLabelOfAScrolledGroupTicksNothing(): void
    {
        $area = new Rect(0, 0, 30, 4);
        $group = CheckboxGroup::new(['one', 'two', 'three', 'four', 'five'], 'Pick');
        foreach (range(1, 4) as $step) {
            $group = $group->update(new KeyMsg(Key::Down), $area)->model;
        }

        $clicked = $group->update(new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left), $area)->model;

        self::assertSame([], $clicked->value());
    }

    #[Test]
    public function mouseClickOnBlankLineAfterLabelDoesNotToggle(): void
    {
        $group = CheckboxGroup::new(['A', 'B'], 'Label');
        $area = new Rect(0, 0, 30, 4);

        // Click on blank line (y=1) => localY=1, optionY=1-2=-1 < 0
        $click = new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left);
        $result = $group->update($click, $area);

        self::assertSame($group, $result->model);
    }

    #[Test]
    public function mouseClickOnLastOptionTogglesIt(): void
    {
        $group = CheckboxGroup::new(['A', 'B', 'C']);
        $area = new Rect(0, 0, 30, 3);

        // No label, so optionY = localY = 2 (last option index)
        $click = new MouseMsg(5, 2, MouseAction::Press, MouseButton::Left);
        $result = $group->update($click, $area);

        self::assertTrue($result->model->isChecked(2));
    }

    #[Test]
    public function mouseClickBeyondLastOptionDoesNothing(): void
    {
        $group = CheckboxGroup::new(['A', 'B']);
        $area = new Rect(0, 0, 30, 5);

        // optionY=2, count=2 => 2 < 2 is false
        $click = new MouseMsg(5, 2, MouseAction::Press, MouseButton::Left);
        $result = $group->update($click, $area);

        self::assertSame($group, $result->model);
    }

    #[Test]
    public function mouseClickRequiresBothBoundsToBeValid(): void
    {
        $group = CheckboxGroup::new(['A'], 'Label');
        $area = new Rect(0, 0, 30, 3);

        // optionY = 0 - 2 = -2: fails >= 0
        $click = new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left);
        $result = $group->update($click, $area);
        self::assertSame($group, $result->model);

        // optionY = 3 - 2 = 1: fails < count (1)
        $click2 = new MouseMsg(5, 3, MouseAction::Press, MouseButton::Left);
        // This click is outside the area (area height=3, bottom=3, y=3 is out)
        // so it won't even reach handleClick — let's use a bigger area
        $group2 = CheckboxGroup::new(['A'], 'Label');
        $area2 = new Rect(0, 0, 30, 5);
        $click3 = new MouseMsg(5, 3, MouseAction::Press, MouseButton::Left);
        $result2 = $group2->update($click3, $area2);
        self::assertSame($group2, $result2->model);
    }

    #[Test]
    public function labelOffsetsOptionsRenderingByTwoRows(): void
    {
        $group = CheckboxGroup::new(['First', 'Second'], 'Title');
        $frame = new Frame(30, 5);
        $area = new Rect(0, 0, 30, 5);

        $group->render($frame, $area, new RenderContext(Theme::dark()));

        // Row 0: label
        self::assertStringContainsString('Title', $this->extractRow($frame, 0, 20));
        // Row 1: blank
        self::assertSame(str_repeat(' ', 30), $this->extractRow($frame, 1, 30));
        // Row 2: first option
        self::assertStringContainsString('First', $this->extractRow($frame, 2, 20));
        // Row 3: second option
        self::assertStringContainsString('Second', $this->extractRow($frame, 3, 20));
    }

    #[Test]
    public function labelOffsetUsesIncrementNotAbsoluteAssignment(): void
    {
        $group = CheckboxGroup::new(['First'], 'Title');
        $frame = new Frame(30, 8);
        $area = new Rect(0, 3, 30, 5);

        $group->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertStringContainsString('Title', $this->extractRow($frame, 3, 20));
        self::assertSame(str_repeat(' ', 30), $this->extractRow($frame, 4, 30));
        self::assertStringContainsString('First', $this->extractRow($frame, 5, 20));
        self::assertSame(str_repeat(' ', 30), $this->extractRow($frame, 2, 30), 'Row 2 must be empty');
    }

    #[Test]
    public function renderingStopsWhenAreaHeightExhausted(): void
    {
        $group = CheckboxGroup::new(['A', 'B', 'C']);
        $frame = new Frame(30, 2);
        $area = new Rect(0, 0, 30, 2);

        $group->render($frame, $area, new RenderContext(Theme::dark()));

        // Row 0 and 1 should have options
        self::assertStringContainsString('A', $this->extractRow($frame, 0, 10));
        self::assertStringContainsString('B', $this->extractRow($frame, 1, 10));
        // No row 2 exists in buffer — C is not rendered (buffer only has 2 rows)
    }

    #[Test]
    public function thirdOptionNotRenderedWhenAreaHasOnlyTwoRows(): void
    {
        $group = CheckboxGroup::new(['A', 'B', 'C']);
        $frame = new Frame(30, 3);
        $area = new Rect(0, 0, 30, 2);

        $group->render($frame, $area, new RenderContext(Theme::dark()));

        // Row 2 should remain blank — C must not be rendered
        self::assertSame(str_repeat(' ', 30), $this->extractRow($frame, 2, 30));
    }

    #[Test]
    public function cursorStyleAppliedOnlyAtCursorIndexWhenFocused(): void
    {
        $theme = Theme::dark();
        $group = CheckboxGroup::new(['A', 'B']);
        $frame = new Frame(30, 2);
        $area = new Rect(0, 0, 30, 2);

        $group->render($frame, $area, RenderContext::focused($theme));

        $cursorFg = $theme->cursorStyle->foregroundValue();
        $baseFg = $theme->base->foregroundValue();

        // Row 0 (cursor=0): should use cursor style
        self::assertSame($cursorFg, $this->foregroundAt($frame, 0, 0));
        // Row 1 (not cursor): should use base style (focused but not cursor)
        self::assertSame($baseFg, $this->foregroundAt($frame, 0, 1));
    }

    #[Test]
    public function cursorStyleNotAppliedWhenUnfocused(): void
    {
        $theme = Theme::dark();
        $group = CheckboxGroup::new(['A', 'B']);
        $frame = new Frame(30, 2);
        $area = new Rect(0, 0, 30, 2);

        $group->render($frame, $area, new RenderContext($theme));

        $cursorFg = $theme->cursorStyle->foregroundValue();
        // Row 0 is cursor index 0, but unfocused — must NOT use cursor style
        self::assertNotSame($cursorFg, $this->foregroundAt($frame, 0, 0));
    }

    #[Test]
    public function nonCursorIndexWhenFocusedUsesBaseStyle(): void
    {
        $theme = Theme::dark();
        $group = CheckboxGroup::new(['A', 'B', 'C']);
        $frame = new Frame(30, 3);
        $area = new Rect(0, 0, 30, 3);

        $group->render($frame, $area, RenderContext::focused($theme));

        // Cursor is at 0 — rows 1 and 2 should use base style (focused, not checked, not cursor)
        self::assertSame($theme->base->foregroundValue(), $this->foregroundAt($frame, 0, 1));
        self::assertSame($theme->base->foregroundValue(), $this->foregroundAt($frame, 0, 2));
    }

    #[Test]
    public function styleMatchArmCursorStyleIsDistinct(): void
    {
        $theme = Theme::dark();
        $group = CheckboxGroup::new(['A']);
        $frame = new Frame(30, 1);
        $area = new Rect(0, 0, 30, 1);

        $group->render($frame, $area, RenderContext::focused($theme));

        self::assertSame($theme->cursorStyle->foregroundValue(), $this->foregroundAt($frame, 0, 0));
    }

    #[Test]
    public function checkedNonCursorItemUsesAccentStyle(): void
    {
        $theme = Theme::dark();
        $group = CheckboxGroup::new(['A', 'B']);
        $area = Rect::fromSize(30, 2);

        // Check A (cursor=0), then move to B
        $group = $group->update(new KeyMsg(Key::Enter), $area)->model;
        $group = $group->update(new KeyMsg(Key::Down), $area)->model;

        $frame = new Frame(30, 2);
        $group->render($frame, new Rect(0, 0, 30, 2), RenderContext::focused($theme));

        // Row 0: checked but not cursor => accent
        self::assertSame($theme->accent->foregroundValue(), $this->foregroundAt($frame, 0, 0));
        // Row 1: cursor => cursorStyle
        self::assertSame($theme->cursorStyle->foregroundValue(), $this->foregroundAt($frame, 0, 1));
    }

    #[Test]
    public function focusedNonCursorUncheckedItemUsesBaseStyle(): void
    {
        $theme = Theme::dark();
        $group = CheckboxGroup::new(['A', 'B']);
        $frame = new Frame(30, 2);
        $area = new Rect(0, 0, 30, 2);

        $group->render($frame, $area, RenderContext::focused($theme));

        // Row 1: focused, not cursor (cursor=0), not checked => base
        self::assertSame($theme->base->foregroundValue(), $this->foregroundAt($frame, 0, 1));
    }

    #[Test]
    public function unfocusedUncheckedItemUsesDimStyle(): void
    {
        $theme = Theme::dark();
        $group = CheckboxGroup::new(['A', 'B']);
        $frame = new Frame(30, 2);
        $area = new Rect(0, 0, 30, 2);

        $group->render($frame, $area, new RenderContext($theme));

        // Unfocused, unchecked => dim
        self::assertSame($theme->dim->foregroundValue(), $this->foregroundAt($frame, 0, 0));
        self::assertSame($theme->dim->foregroundValue(), $this->foregroundAt($frame, 0, 1));
    }

    #[Test]
    public function optionTextRendersAtCorrectXOffset(): void
    {
        $theme = Theme::dark();
        $group = CheckboxGroup::new(['Z']);
        $frame = new Frame(30, 1);
        $area = new Rect(0, 0, 30, 1);

        $group->render($frame, $area, new RenderContext($theme));

        // Mark "[ ] " occupies columns 0-3, option text starts at column 4
        self::assertSame('[', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[1]);
        self::assertSame(']', $frame->characters[2]);
        self::assertSame(' ', $frame->characters[3]);
        self::assertSame('Z', $frame->characters[4]);
    }

    #[Test]
    public function optionTextRendersAtCorrectXOffsetWithNonZeroAreaX(): void
    {
        $theme = Theme::dark();
        $group = CheckboxGroup::new(['Q']);
        $frame = new Frame(30, 1);
        $area = new Rect(5, 0, 25, 1);

        $group->render($frame, $area, new RenderContext($theme));

        // Mark at x=5, option text at x=9
        self::assertSame('[', $frame->characters[5]);
        self::assertSame('Q', $frame->characters[9]);
        // Column 4 should still be blank (before the mark)
        self::assertSame(' ', $frame->characters[4]);
    }

    #[Test]
    public function eachOptionRendersOnSuccessiveRows(): void
    {
        $theme = Theme::dark();
        $group = CheckboxGroup::new(['A', 'B', 'C']);
        $frame = new Frame(30, 3);
        $area = new Rect(0, 0, 30, 3);

        $group->render($frame, $area, new RenderContext($theme));

        self::assertStringContainsString('A', $this->extractRow($frame, 0, 10));
        self::assertStringContainsString('B', $this->extractRow($frame, 1, 10));
        self::assertStringContainsString('C', $this->extractRow($frame, 2, 10));
    }

    #[Test]
    public function secondOptionDoesNotOverwriteFirstRow(): void
    {
        $theme = Theme::dark();
        $group = CheckboxGroup::new(['First', 'Second']);
        $frame = new Frame(30, 2);
        $area = new Rect(0, 0, 30, 2);

        $group->render($frame, $area, new RenderContext($theme));

        // Row 0 must contain "First" not "Second"
        $row0 = $this->extractRow($frame, 0, 15);
        self::assertStringContainsString('First', $row0);
        self::assertStringNotContainsString('Second', $row0);

        // Row 1 must contain "Second"
        self::assertStringContainsString('Second', $this->extractRow($frame, 1, 15));
    }

    #[Test]
    public function mouseClickWithLabelComputesCorrectOptionIndex(): void
    {
        $group = CheckboxGroup::new(['X', 'Y'], 'Title');
        $area = new Rect(0, 0, 30, 4);

        // With label: optionY = localY - 2
        // Click at y=2 => localY=2, optionY=0 => first option
        $click = new MouseMsg(5, 2, MouseAction::Press, MouseButton::Left);
        $result = $group->update($click, $area);

        self::assertTrue($result->model->isChecked(0));
        self::assertSame(0, $result->model->cursor());
    }

    // Without label: optionY = localY (no subtraction)
    #[Test]
    public function mouseClickWithoutLabelClicksCorrectOption(): void
    {
        $group = CheckboxGroup::new(['X', 'Y']);
        $area = new Rect(0, 0, 30, 2);

        $click = new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left);
        $result = $group->update($click, $area);

        self::assertTrue($result->model->isChecked(1));
        self::assertFalse($result->model->isChecked(0));
    }

    // Mouse click toggles (not just checks) — clicking already checked option unchecks it
    #[Test]
    public function mouseClickTogglesAlreadyCheckedOption(): void
    {
        $group = CheckboxGroup::new(['A', 'B']);
        $area = new Rect(0, 0, 30, 2);

        $click = new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left);
        $group = $group->update($click, $area)->model;
        self::assertTrue($group->isChecked(0));

        $group = $group->update($click, $area)->model;
        self::assertFalse($group->isChecked(0));
    }

    #[Test]
    public function supportsCustomFocusPrevKeybinding(): void
    {
        $area = Rect::fromSize(20, 10);
        $widget = CheckboxGroup::new(['a', 'b', 'c'], 'lbl')->withCursor(1);

        $afterDefault = $widget->update(new KeyMsg(Key::Up), $area)->model;
        self::assertSame(0, $afterDefault->cursor());

        $custom = $widget->withKeybindings(new Keybindings([Actions::CURSOR_UP => ['x']]));
        self::assertSame($custom, $custom->update(new KeyMsg(Key::Up), $area)->model);

        $afterCustom = $custom->update(new KeyMsg('x'), $area)->model;
        self::assertSame(0, $afterCustom->cursor());
    }

    #[Test]
    public function supportsCustomFocusNextKeybinding(): void
    {
        $area = Rect::fromSize(20, 10);
        $widget = CheckboxGroup::new(['a', 'b', 'c'], 'lbl');

        $afterDefault = $widget->update(new KeyMsg(Key::Down), $area)->model;
        self::assertSame(1, $afterDefault->cursor());

        $custom = $widget->withKeybindings(new Keybindings([Actions::CURSOR_DOWN => ['x']]));
        self::assertSame($custom, $custom->update(new KeyMsg(Key::Down), $area)->model);

        $afterCustom = $custom->update(new KeyMsg('x'), $area)->model;
        self::assertSame(1, $afterCustom->cursor());
    }

    #[Test]
    public function supportsCustomToggleKeybinding(): void
    {
        $area = Rect::fromSize(20, 10);
        $widget = CheckboxGroup::new(['a', 'b', 'c'], 'lbl');

        $afterDefault = $widget->update(new KeyMsg(Key::Enter), $area)->model;
        self::assertTrue($afterDefault->isChecked(0));

        $custom = $widget->withKeybindings(new Keybindings([Actions::TOGGLE => ['x']]));
        $afterDefaultOnCustom = $custom->update(new KeyMsg(Key::Enter), $area)->model;
        self::assertFalse($afterDefaultOnCustom->isChecked(0));

        $afterCustom = $custom->update(new KeyMsg('x'), $area)->model;
        self::assertTrue($afterCustom->isChecked(0));
    }

    #[Test]
    public function labeledGroupRendersLabelBlankSpacerThenOptions(): void
    {
        $group = CheckboxGroup::new(['Alpha', 'Beta'], 'Pick');
        $frame = new Frame(20, 5);
        $group->render($frame, new Rect(0, 0, 20, 5), new RenderContext(Theme::dark()));

        self::assertSame('Pick', rtrim($this->extractRow($frame, 0, 20)), 'Label on row 0');
        self::assertSame('', rtrim($this->extractRow($frame, 1, 20)), 'Row 1 is the spacer');
        self::assertSame('[ ] Alpha', rtrim($this->extractRow($frame, 2, 20)), 'First option starts on row 2 — the label occupies exactly two rows');
        self::assertSame('[ ] Beta', rtrim($this->extractRow($frame, 3, 20)));
    }

    #[Test]
    public function scrollsToKeepTheCursorVisibleWhenOptionsExceedTheHeight(): void
    {
        $group = CheckboxGroup::new(['Alpha', 'Beta', 'Gamma', 'Delta'])
            ->withCursor(3)
        ;
        $frame = new Frame(12, 3);
        $group->render($frame, new Rect(0, 0, 12, 3), new RenderContext(Theme::dark()));

        self::assertSame('[ ] Beta', rtrim($this->extractRow($frame, 0, 12)), 'One row scrolled out: Beta is now the top row');
        self::assertSame('[ ] Gamma', rtrim($this->extractRow($frame, 1, 12)));
        self::assertSame('[ ] Delta', rtrim($this->extractRow($frame, 2, 12)), 'The cursor row is the last visible row');
    }

    #[Test]
    public function aScrolledLabelledGroupKeepsItsLabelAndWindowsTheOptions(): void
    {
        $group = CheckboxGroup::new(['Alpha', 'Beta', 'Gamma', 'Delta'], 'Top')
            ->withCursor(3)
        ;
        $frame = new Frame(12, 4);
        $group->render($frame, new Rect(0, 0, 12, 4), new RenderContext(Theme::dark()));

        self::assertSame('Top', rtrim($this->extractRow($frame, 0, 12)), 'the label stays where the rect put it');
        self::assertSame('', rtrim($this->extractRow($frame, 1, 12)), 'Row 1 is the spacer');
        self::assertSame('[ ] Gamma', rtrim($this->extractRow($frame, 2, 12)), 'two label rows leave two option rows');
        self::assertSame('[ ] Delta', rtrim($this->extractRow($frame, 3, 12)), 'The cursor row lands on the last visible row');
    }

    #[Test]
    public function aClickTogglesTheBoxDrawnOnThatRowAfterTheListHasScrolled(): void
    {
        $group = CheckboxGroup::new(['one', 'two', 'three', 'four', 'five', 'six']);
        $area = Rect::fromSize(20, 3);

        $scrolled = $group;
        for ($step = 0; $step < 5; ++$step) {
            $scrolled = $scrolled->update(new KeyMsg(Key::Down), $area)->model;
        }

        $clicked = $scrolled->update(
            new MouseMsg(0, $area->y + 2, MouseAction::Press, MouseButton::Left),
            $area,
        )->model;

        self::assertSame(
            ['six'],
            $clicked->checkedValues(),
            'the bottom row shows the sixth option once the list has scrolled, so that is what a click there ticks',
        );
    }

    #[Test]
    public function readsAsHowManyOfItsBoxesAreTicked(): void
    {
        $group = CheckboxGroup::new(['app.log', 'deprecations', 'sync_log', 'sync_trail']);

        self::assertSame('0 of 4', $group->caption());
        self::assertSame('2 of 4', $group->activated('app.log')->activated('sync_log')->caption());
    }

    #[Test]
    public function readsAsTheHostsOwnWordForNothingTickedWhenItGivesOne(): void
    {
        $group = CheckboxGroup::new(['app.log', 'sync_log'])->withEmptyCaption('all');

        self::assertSame('all', $group->caption());
        self::assertSame('1 of 2', $group->activated('sync_log')->caption(), 'and back to counting once a box is ticked');
    }

    #[Test]
    public function tickingTheSameNameTwiceLeavesItClear(): void
    {
        $group = CheckboxGroup::new(['one', 'two']);

        self::assertSame(['one'], $group->activated('one')->checkedValues());
        self::assertSame([], $group->activated('one')->activated('one')->checkedValues());
    }

    #[Test]
    public function namesTheOptionUnderAPointerEvenAfterTheListHasScrolled(): void
    {
        $group = CheckboxGroup::new(['one', 'two', 'three', 'four', 'five', 'six'])->withCursor(5);
        $area = Rect::fromSize(20, 3);

        self::assertSame(
            'six',
            $group->optionAt(new MouseMsg(0, $area->y + 2, MouseAction::Press, MouseButton::Left), $area),
            'the bottom row shows the sixth option once the list has scrolled',
        );
        self::assertNull(
            $group->optionAt(new MouseMsg(0, $area->bottom() + 1, MouseAction::Press, MouseButton::Left), $area),
            'a pointer outside the drawn area addresses no option at all',
        );
        self::assertFalse($group->hasOption('seven'), 'an option it never offered is not one it can act on');
    }

    #[Test]
    public function measuresItsWidestOptionPlusItsBoxAndCountsItsLabelRows(): void
    {
        $bare = CheckboxGroup::new(['json', 'yaml', 'ndjson']);

        self::assertSame(6 + 4, $bare->popupWidth(), 'every option is drawn behind a box');
        self::assertSame(3, $bare->popupHeight(20));
        self::assertSame(5, CheckboxGroup::new(['json', 'yaml', 'ndjson'], 'Format')->popupHeight(20), 'a label costs a row and a spacer');
    }

    #[Test]
    public function theWheelWalksTheCursorTheWayItDoesInAnyOtherOptionList(): void
    {
        $group = CheckboxGroup::new(['one', 'two', 'three', 'four']);
        $area = Rect::fromSize(20, 4);

        $wheeled = $group->update(new MouseMsg(0, 0, MouseAction::WheelDown, MouseButton::None, 2), $area)->model;

        self::assertInstanceOf(CheckboxGroup::class, $wheeled);
        self::assertSame(2, $wheeled->cursor(), 'two notches down is two options down');
        self::assertSame(
            ['three'],
            $wheeled->update(new KeyMsg(' '), $area)->model->checkedValues(),
            'and the box the wheel left the cursor on is the one space ticks',
        );
    }

    #[Test]
    public function twoOptionsReadingTheSameAreRefusedWhereTheyAreHandedIn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CheckboxGroup options must read differently; "Yes" is at 0 and 2');

        CheckboxGroup::new(['Yes', 'No', 'Yes']);
    }

    #[Test]
    public function optionsPutInLaterAreHeldToTheSameRule(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CheckboxGroup options must read differently');

        CheckboxGroup::new(['Yes', 'No'])->withOptions(['Same', 'Same']);
    }

    private function extractRow(Frame $frame, int $row, int $columns): string
    {
        $text = '';
        $base = $row * $frame->width;
        for ($column = 0; $column < $columns; ++$column) {
            $text .= $frame->characters[$base + $column];
        }

        return $text;
    }

    private function foregroundAt(Frame $frame, int $x, int $y): int
    {
        return $frame->foregrounds[$y * $frame->width + $x];
    }
}
