<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Radio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RadioTest extends TestCase
{
    use FocusableBehaviorTrait;

    private const MARK_WIDTH = 4;

    #[Test]
    public function takesTheOptionsFirstAndLeavesTheLabelOptional(): void
    {
        $bare = Radio::new(['A', 'B']);

        self::assertSame('A', $bare->selectedValue(), 'the collection is what leads');
        self::assertSame(2, $bare->constraint()->min, 'and an omitted label costs no rows');
        self::assertSame(4, Radio::new(['A', 'B'], 'Pick one')->constraint()->min, 'a label still follows it, where there is one');
    }

    #[Test]
    public function constraintReturnsExactOptionCountPlusLabelAndSpacer(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Pick one');
        $constraint = $radio->constraint();
        self::assertSame(5, $constraint->min);
        self::assertSame(5, $constraint->max);
    }

    #[Test]
    public function constraintWithoutLabelReturnsExactOptionCount(): void
    {
        $radio = Radio::new(['A', 'B', 'C']);
        $constraint = $radio->constraint();
        self::assertSame(3, $constraint->min);
        self::assertSame(3, $constraint->max);
    }

    #[Test]
    public function arrowDownMovesCursor(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Label')->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $radio->update(new KeyMsg(Key::Down), $area);
        self::assertInstanceOf(UpdateResult::class, $result);
        self::assertInstanceOf(Radio::class, $result->model);
        self::assertSame(1, $result->model->cursor());

        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function arrowDownDoesNotMovePastLastOption(): void
    {
        $radio = Radio::new(['A', 'B'], 'Label')->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $radio->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function arrowUpMovesCursor(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Label')->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $radio->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        self::assertSame(2, $result->model->cursor());

        $result = $result->model->update(new KeyMsg(Key::Up), $area);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function arrowUpDoesNotMovePastFirstOption(): void
    {
        $radio = Radio::new(['A', 'B'], 'Label')->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $radio->update(new KeyMsg(Key::Up), $area);
        self::assertSame(0, $result->model->cursor());
    }

    #[Test]
    public function enterSelectsCurrentCursorPosition(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Label')->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $radio->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Enter), $area);
        self::assertSame(1, $result->model->selected());
        self::assertSame('B', $result->model->selectedValue());
    }

    #[Test]
    public function rendersLabelWithTitleStyle(): void
    {
        $radio = Radio::new(['A', 'B'], 'Pick one')->withFocus(true);
        $frame = new Frame(40, 5);
        $area = new Rect(0, 0, 40, 5);
        $radio->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('P', $frame->characters[0]);
        self::assertSame('i', $frame->characters[1]);
        self::assertSame('c', $frame->characters[2]);
        self::assertSame('k', $frame->characters[3]);

        $theme = Theme::dark();
        self::assertSame($theme->titleStyle->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame(Attr::BOLD, $frame->attributes[0]);
    }

    #[Test]
    public function labelStyleDoesNotChangeWithFocus(): void
    {
        $theme = Theme::dark();
        $area = new Rect(0, 0, 40, 5);

        $focused = Radio::new(['A', 'B'], 'Pick one')->withFocus(true);
        $unfocused = Radio::new(['A', 'B'], 'Pick one')->withFocus(false);

        $focusedBuffer = new Frame(40, 5);
        $focused->render($focusedBuffer, $area, new RenderContext($theme));

        $unfocusedBuffer = new Frame(40, 5);
        $unfocused->render($unfocusedBuffer, $area, new RenderContext($theme));

        self::assertSame($focusedBuffer->foregrounds[0], $unfocusedBuffer->foregrounds[0]);
    }

    #[Test]
    public function updateIgnoresKeyWhenNotFocused(): void
    {
        $radio = Radio::new(['A', 'B'], 'Label');
        $area = Rect::fromSize(40, 10);

        $result = $radio->update(new KeyMsg(Key::Down), $area);
        self::assertSame($radio, $result->model);
    }

    #[Test]
    public function mouseClickSelectsOption(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Label');
        $area = new Rect(0, 0, 40, 6);

        $mouseMessage = new MouseMsg(5, 3, MouseAction::Press, MouseButton::Left);
        $result = $radio->update($mouseMessage, $area);
        self::assertSame(1, $result->model->selected());
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function upAtFirstOptionReturnsSameInstance(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Label')->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $radio->update(new KeyMsg(Key::Up), $area);
        self::assertSame($radio, $result->model);
    }

    #[Test]
    public function downAtLastOptionReturnsSameInstance(): void
    {
        $radio = Radio::new(['A', 'B'], 'Label')->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $movedDown = $radio->update(new KeyMsg(Key::Down), $area)->model;
        self::assertSame(1, $movedDown->cursor());

        $result = $movedDown->update(new KeyMsg(Key::Down), $area);
        self::assertSame($movedDown, $result->model);
    }

    #[Test]
    public function updateWithEmptyOptionsReturnsSelf(): void
    {
        $radio = Radio::new([], 'Label')->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $radio->update(new KeyMsg(Key::Down), $area);
        self::assertSame($radio, $result->model);
    }

    #[Test]
    public function emptyRadioConstraintHandlesLabel(): void
    {
        $radio = Radio::new([], 'Label');
        self::assertSame(2, $radio->constraint()->min);
        self::assertSame(2, $radio->constraint()->max);
    }

    #[Test]
    public function emptyRadioRenderDoesNotCrash(): void
    {
        $radio = Radio::new([], 'Label');
        $frame = new Frame(10, 5);
        $radio->render($frame, Rect::fromSize(10, 5), new RenderContext(Theme::dark()));
        self::assertTrue(true);
    }

    /**
     * L25: DecrementInteger, IncrementInteger — default selected must be exactly 0.
     */
    #[Test]
    public function defaultSelectedIsZero(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Label');
        self::assertSame(0, $radio->selected());
        self::assertSame('A', $radio->selectedValue());
    }

    /**
     * L81: MatchArmRemoval — space key selects current cursor position.
     */
    #[Test]
    public function spaceSelectsCurrentCursorPosition(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Label')->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $moved = $radio->update(new KeyMsg(Key::Down), $area)->model;
        $result = $moved->update(new KeyMsg(' '), $area);
        self::assertSame(1, $result->model->selected());
    }

    #[Test]
    public function aWrappingRadioCarriesTheCursorRoundEitherEnd(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Label')->wrapping()->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $up = $radio->update(new KeyMsg(Key::Up), $area)->model;
        self::assertSame(2, $up->cursor(), 'off the near end is the far one');

        $down = $radio->withCursor(2)->update(new KeyMsg(Key::Down), $area)->model;
        self::assertSame(0, $down->cursor(), 'and off the far end is the near one');
    }

    #[Test]
    public function aClearableRadioLetsThePickedOptionBePutBackDown(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Label')->clearable()->withSelected(0)->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $cleared = $radio->update(new KeyMsg(' '), $area)->model;

        self::assertSame(-1, $cleared->selected(), 'pressing the one already picked puts it back down');
    }

    #[Test]
    public function clickingTheOptionAClearableRadioHoldsPutsItBackDown(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Label')->clearable()->withSelected(1);
        $area = new Rect(0, 5, 40, 8);

        $cleared = $radio->update(new MouseMsg(5, 8, MouseAction::Press, MouseButton::Left), $area)->model;

        self::assertSame(-1, $cleared->selected());
        self::assertSame(1, $cleared->cursor(), 'the cursor still rests where the pointer left it');
    }

    /**
     * L93: Minus — click localY uses area.y offset for correct option mapping.
     */
    #[Test]
    public function clickAccountsForAreaYOffset(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Label');
        $area = new Rect(0, 5, 40, 8);

        $mouseMessage = new MouseMsg(5, 8, MouseAction::Press, MouseButton::Left);
        $result = $radio->update($mouseMessage, $area);
        self::assertSame(1, $result->model->selected());
        self::assertSame(1, $result->model->cursor());
    }

    /**
     * L95: GreaterThanOrEqualTo — click on label row (optionY = -1) does not select.
     */
    #[Test]
    public function clickOnLabelRowDoesNotSelect(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Label');
        $area = new Rect(0, 0, 40, 6);

        $mouseMessage = new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left);
        $result = $radio->update($mouseMessage, $area);
        self::assertSame(0, $result->model->selected());
        self::assertSame($radio, $result->model);
    }

    /**
     * L95: LessThan — click beyond last option does not select.
     */
    #[Test]
    public function clickBeyondLastOptionDoesNotSelect(): void
    {
        $radio = Radio::new(['A', 'B'], 'Label');
        $area = new Rect(0, 0, 40, 6);

        $mouseMessage = new MouseMsg(5, 4, MouseAction::Press, MouseButton::Left);
        $result = $radio->update($mouseMessage, $area);
        self::assertSame($radio, $result->model);
    }

    /**
     * L95: GreaterThanOrEqualTo — click at optionY exactly 0 must produce a new model.
     */
    #[Test]
    public function clickAtFirstOptionBoundaryProducesNewModel(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Label')->withFocus(true);
        $area = new Rect(0, 0, 40, 6);

        $moved = $radio->update(new KeyMsg(Key::Down), $area)->model;
        self::assertSame(1, $moved->cursor());

        $mouseMessage = new MouseMsg(5, 2, MouseAction::Press, MouseButton::Left);
        $result = $moved->update($mouseMessage, $area);
        self::assertNotSame($moved, $result->model, 'Click at optionY=0 must not be ignored');
        self::assertSame(0, $result->model->cursor());
        self::assertSame(0, $result->model->selected());
    }

    /**
     * L95: click at optionY exactly count-1 selects last option.
     */
    #[Test]
    public function clickAtLastOptionBoundarySelectsLastOption(): void
    {
        $radio = Radio::new(['A', 'B', 'C'], 'Label');
        $area = new Rect(0, 0, 40, 6);

        $mouseMessage = new MouseMsg(5, 4, MouseAction::Press, MouseButton::Left);
        $result = $radio->update($mouseMessage, $area);
        self::assertSame(2, $result->model->selected());
        self::assertSame(2, $result->model->cursor());
    }

    /**
     * L95: click on spacer row between label and options does not select.
     */
    #[Test]
    public function clickOnSpacerRowBetweenLabelAndOptionsDoesNotSelect(): void
    {
        $radio = Radio::new(['A', 'B'], 'Label');
        $area = new Rect(0, 0, 40, 6);

        $mouseMessage = new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left);
        $result = $radio->update($mouseMessage, $area);
        self::assertSame($radio, $result->model);
    }

    /**
     * L109: Assignment/DecrementInteger/IncrementInteger/PlusEqual — label shifts options down by 2 rows.
     */
    #[Test]
    public function optionsRenderTwoRowsBelowLabelWhenLabelPresent(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['Alpha', 'Beta'], 'Title')->withFocus(true);
        $frame = new Frame(40, 6);
        $area = new Rect(0, 0, 40, 6);
        $radio->render($frame, $area, new RenderContext($theme));

        $labelRowIndex = 0 * 40;
        self::assertSame('T', $frame->characters[$labelRowIndex]);

        $row1Index = 1 * 40;
        self::assertSame(' ', $frame->characters[$row1Index]);

        $optionRow0Index = 2 * 40;
        self::assertSame(Radio::PICKED_MARK, $this->markAt($frame, $optionRow0Index));

        $optionRow1Index = 3 * 40;
        self::assertSame(Radio::FREE_MARK, $this->markAt($frame, $optionRow1Index));
    }

    #[Test]
    public function labelOffsetUsesIncrementNotAbsoluteAssignment(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['Alpha'], 'Title')->withFocus(true);
        $frame = new Frame(40, 8);
        $area = new Rect(0, 3, 40, 5);
        $radio->render($frame, $area, new RenderContext($theme));

        self::assertSame('T', $frame->characters[3 * 40]);
        self::assertSame(' ', $frame->characters[4 * 40]);
        self::assertSame(Radio::PICKED_MARK, $this->markAt($frame, 5 * 40), 'Option must render at area.y(3)+2=5, not at absolute row 2');
        self::assertSame(' ', $frame->characters[2 * 40], 'Row 2 must be empty (mutant $y=2 would render option here)');
    }

    /**
     * L109: Without label, options start at row 0.
     */
    #[Test]
    public function optionsRenderAtRow0WhenNoLabel(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['Alpha', 'Beta'])->withFocus(true);
        $frame = new Frame(40, 4);
        $area = new Rect(0, 0, 40, 4);
        $radio->render($frame, $area, new RenderContext($theme));

        self::assertSame(Radio::PICKED_MARK, $this->markAt($frame, 0));

        $row1Index = 1 * 40;
        self::assertSame(Radio::FREE_MARK, $this->markAt($frame, $row1Index));
    }

    /**
     * L112: Foreach_ — all options must render, not just label.
     */
    #[Test]
    public function allOptionsAreRendered(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['Alpha', 'Beta', 'Gamma'])->withFocus(true);
        $frame = new Frame(40, 5);
        $area = new Rect(0, 0, 40, 5);
        $radio->render($frame, $area, new RenderContext($theme));

        self::assertSame('A', $frame->characters[self::MARK_WIDTH]);

        $row1 = 1 * 40 + self::MARK_WIDTH;
        self::assertSame('B', $frame->characters[$row1]);

        $row2 = 2 * 40 + self::MARK_WIDTH;
        self::assertSame('G', $frame->characters[$row2]);
    }

    /**
     * L113: GreaterThanOrEqualTo, GreaterThanOrEqualToNegotiation — clipping at area boundary.
     */
    #[Test]
    public function renderClipsOptionsAtAreaBottom(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['A', 'B', 'C'])->withFocus(true);
        $frame = new Frame(40, 2);
        $area = new Rect(0, 0, 40, 2);
        $radio->render($frame, $area, new RenderContext($theme));

        self::assertSame(Radio::PICKED_MARK, $this->markAt($frame, 0));

        $row1Index = 1 * 40;
        self::assertSame(Radio::FREE_MARK, $this->markAt($frame, $row1Index));
    }

    /**
     * L113: option at exact boundary row should not render.
     */
    #[Test]
    public function optionAtExactBottomBoundaryDoesNotRender(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['A', 'B', 'C'])->withFocus(true);
        $frame = new Frame(40, 3);
        $area = new Rect(0, 0, 40, 2);
        $radio->render($frame, $area, new RenderContext($theme));

        self::assertSame('A', $frame->characters[self::MARK_WIDTH]);
        self::assertSame('B', $frame->characters[1 * 40 + self::MARK_WIDTH]);
        self::assertSame(' ', $frame->characters[2 * 40], 'Row 2 must be empty when area height is 2');
        self::assertSame(' ', $frame->characters[2 * 40 + self::MARK_WIDTH], 'No option text on row 2');
    }

    /**
     * L117: cursor style only applies when both index matches cursor AND widget is focused.
     */
    #[Test]
    public function cursorStyleOnlyAppliesWhenFocused(): void
    {
        $theme = Theme::dark();
        $area = new Rect(0, 0, 40, 3);

        $focused = Radio::new(['A', 'B'])->withFocus(true);
        $focusedBuffer = new Frame(40, 3);
        $focused->render($focusedBuffer, $area, new RenderContext($theme));

        $unfocused = Radio::new(['A', 'B'])->withFocus(false);
        $unfocusedBuffer = new Frame(40, 3);
        $unfocused->render($unfocusedBuffer, $area, new RenderContext($theme));

        self::assertSame($theme->cursorStyle->foregroundValue(), $focusedBuffer->foregrounds[0]);
        self::assertNotSame($theme->cursorStyle->foregroundValue(), $unfocusedBuffer->foregrounds[0]);
    }

    /**
     * L117: non-cursor option on focused radio uses base style, not cursor style.
     */
    #[Test]
    public function nonCursorOptionUsesDifferentStyleThanCursor(): void
    {
        $theme = Theme::dark();
        $area = new Rect(0, 0, 40, 3);

        $radio = Radio::new(['A', 'B'])->withFocus(true);
        $frame = new Frame(40, 3);
        $radio->render($frame, $area, new RenderContext($theme));

        $cursorRowFg = $frame->foregrounds[0];
        $nonCursorRowFg = $frame->foregrounds[1 * 40];
        self::assertNotSame($cursorRowFg, $nonCursorRowFg);
    }

    /**
     * L118: selected option shows filled dot, non-selected shows empty dot.
     */
    #[Test]
    public function selectedOptionShowsFilledDot(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['A', 'B', 'C'])->withFocus(false);
        $frame = new Frame(40, 4);
        $area = new Rect(0, 0, 40, 4);
        $radio->render($frame, $area, new RenderContext($theme));

        self::assertSame(Radio::PICKED_MARK, $this->markAt($frame, 0));
        self::assertSame(Radio::FREE_MARK, $this->markAt($frame, 1 * 40));
        self::assertSame(Radio::FREE_MARK, $this->markAt($frame, 2 * 40));
    }

    /**
     * L118: when selected is 1, second option shows filled dot.
     */
    #[Test]
    public function secondOptionShowsFilledDotWhenSelected(): void
    {
        $radio = Radio::new(['A', 'B', 'C'])->withFocus(true);
        $area = Rect::fromSize(40, 5);

        $moved = $radio->update(new KeyMsg(Key::Down), $area)->model;
        $selected = $moved->update(new KeyMsg(Key::Enter), $area)->model;

        $theme = Theme::dark();
        $frame = new Frame(40, 4);
        $renderArea = new Rect(0, 0, 40, 4);
        $selected->render($frame, $renderArea, new RenderContext($theme));

        self::assertSame(Radio::FREE_MARK, $this->markAt($frame, 0));
        self::assertSame(Radio::PICKED_MARK, $this->markAt($frame, 1 * 40));
        self::assertSame(Radio::FREE_MARK, $this->markAt($frame, 2 * 40));
    }

    /**
     * L120: Ternary — selected uses ● and non-selected uses ○.
     */
    #[Test]
    public function selectedDotCharacterDiffersFromUnselected(): void
    {
        $radio = Radio::new(['A', 'B'])->withFocus(false);
        $theme = Theme::dark();
        $frame = new Frame(40, 3);
        $area = new Rect(0, 0, 40, 3);
        $radio->render($frame, $area, new RenderContext($theme));

        self::assertSame(Radio::PICKED_MARK, $this->markAt($frame, 0));
        self::assertSame(Radio::FREE_MARK, $this->markAt($frame, 1 * 40));
        self::assertNotSame(Radio::PICKED_MARK, Radio::FREE_MARK, 'the two states have to look different');
    }

    /**
     * L122: MatchArmRemoval — selected non-cursor option uses accent style.
     */
    #[Test]
    public function selectedNonCursorOptionUsesAccentStyle(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['A', 'B'])->withFocus(true);
        $area = Rect::fromSize(40, 5);

        $moved = $radio->update(new KeyMsg(Key::Down), $area)->model;

        $frame = new Frame(40, 3);
        $renderArea = new Rect(0, 0, 40, 3);
        $moved->render($frame, $renderArea, new RenderContext($theme));

        self::assertSame($theme->accent->foregroundValue(), $frame->foregrounds[0]);
    }

    /**
     * L122: MatchArmRemoval — focused non-selected non-cursor option uses base style.
     */
    #[Test]
    public function focusedNonSelectedNonCursorOptionUsesBaseStyle(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['A', 'B', 'C'])->withFocus(true);

        $frame = new Frame(40, 4);
        $area = new Rect(0, 0, 40, 4);
        $radio->render($frame, $area, new RenderContext($theme));

        $row2Fg = $frame->foregrounds[2 * 40];
        self::assertSame($theme->base->foregroundValue(), $row2Fg);
    }

    /**
     * L122: MatchArmRemoval — unfocused non-selected option uses dim style.
     */
    #[Test]
    public function unfocusedNonSelectedOptionUsesDimStyle(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['A', 'B'])->withFocus(false);

        $frame = new Frame(40, 3);
        $area = new Rect(0, 0, 40, 3);
        $radio->render($frame, $area, new RenderContext($theme));

        $row1Fg = $frame->foregrounds[1 * 40];
        self::assertSame($theme->dim->foregroundValue(), $row1Fg);
    }

    /**
     * L129: MethodCallRemoval — dot marker must be written to the buffer.
     */
    #[Test]
    public function dotMarkerIsRenderedInBuffer(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['A'])->withFocus(true);
        $frame = new Frame(40, 2);
        $area = new Rect(0, 0, 40, 2);
        $radio->render($frame, $area, new RenderContext($theme));

        self::assertSame(Radio::PICKED_MARK, $this->markAt($frame, 0));
        self::assertNotSame(0, $frame->foregrounds[0]);
    }

    /**
     * L130: option text renders at x offset +2, DecrementInteger/IncrementInteger/Plus/MethodCallRemoval.
     */
    #[Test]
    public function optionTextRendersRightAfterTheMark(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['Hello'])->withFocus(true);
        $frame = new Frame(40, 2);
        $area = new Rect(0, 0, 40, 2);
        $radio->render($frame, $area, new RenderContext($theme));

        self::assertSame('H', $frame->characters[self::MARK_WIDTH]);
        self::assertSame('e', $frame->characters[self::MARK_WIDTH + 1]);
        self::assertSame('l', $frame->characters[self::MARK_WIDTH + 2]);
        self::assertSame('l', $frame->characters[self::MARK_WIDTH + 3]);
        self::assertSame('o', $frame->characters[self::MARK_WIDTH + 4]);
    }

    /**
     * L130: option text renders at correct x when area has non-zero x offset.
     */
    #[Test]
    public function optionTextRendersAtCorrectXWithAreaOffset(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['Hi'])->withFocus(true);
        $frame = new Frame(40, 2);
        $area = new Rect(5, 0, 35, 2);
        $radio->render($frame, $area, new RenderContext($theme));

        self::assertSame(Radio::PICKED_MARK, $this->markAt($frame, 5));
        self::assertSame('H', $frame->characters[5 + self::MARK_WIDTH]);
        self::assertSame('i', $frame->characters[5 + self::MARK_WIDTH + 1]);
    }

    /**
     * L130: MethodCallRemoval — option text foreground must match style.
     */
    #[Test]
    public function optionTextForegroundMatchesStyle(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['Hello'])->withFocus(true);
        $frame = new Frame(40, 2);
        $area = new Rect(0, 0, 40, 2);
        $radio->render($frame, $area, new RenderContext($theme));

        self::assertSame($theme->cursorStyle->foregroundValue(), $frame->foregrounds[self::MARK_WIDTH]);
    }

    /**
     * L131: Increment — each option renders on successive rows.
     */
    #[Test]
    public function eachOptionRendersOnSuccessiveRows(): void
    {
        $theme = Theme::dark();
        $radio = Radio::new(['A', 'B', 'C'])->withFocus(true);
        $frame = new Frame(40, 5);
        $area = new Rect(0, 0, 40, 5);
        $radio->render($frame, $area, new RenderContext($theme));

        self::assertSame('A', $frame->characters[0 * 40 + self::MARK_WIDTH]);
        self::assertSame('B', $frame->characters[1 * 40 + self::MARK_WIDTH]);
        self::assertSame('C', $frame->characters[2 * 40 + self::MARK_WIDTH]);
    }

    /**
     * L81: unrecognized key returns same instance.
     */
    #[Test]
    public function unrecognizedKeyReturnsSameInstance(): void
    {
        $radio = Radio::new(['A', 'B'], 'Label')->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $radio->update(new KeyMsg('x'), $area);
        self::assertSame($radio, $result->model);
    }

    /**
     * L95: click on no-label radio at first option works.
     */
    #[Test]
    public function clickOnNoLabelRadioSelectsFirstOption(): void
    {
        $radio = Radio::new(['A', 'B', 'C']);
        $area = new Rect(0, 0, 40, 4);

        $mouseMessage = new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left);
        $result = $radio->update($mouseMessage, $area);
        self::assertSame(0, $result->model->selected());
        self::assertSame(0, $result->model->cursor());
    }

    /**
     * L95: click on no-label radio at last option.
     */
    #[Test]
    public function clickOnNoLabelRadioSelectsLastOption(): void
    {
        $radio = Radio::new(['A', 'B', 'C']);
        $area = new Rect(0, 0, 40, 4);

        $mouseMessage = new MouseMsg(5, 2, MouseAction::Press, MouseButton::Left);
        $result = $radio->update($mouseMessage, $area);
        self::assertSame(2, $result->model->selected());
    }

    /**
     * Click outside area has no effect (default match arm in update).
     */
    #[Test]
    public function clickOutsideAreaHasNoEffect(): void
    {
        $radio = Radio::new(['A', 'B'], 'Label');
        $area = new Rect(0, 0, 10, 4);

        $mouseMessage = new MouseMsg(15, 2, MouseAction::Press, MouseButton::Left);
        $result = $radio->update($mouseMessage, $area);
        self::assertSame($radio, $result->model);
    }

    #[Test]
    public function renderTruncatesLabelToAreaWidth(): void
    {
        $radio = Radio::new(['Very Long Option Text'], 'Very Long Label Text');
        $frame = new Frame(15, 5);
        $radio->render($frame, new Rect(0, 0, 10, 5), new RenderContext(Theme::dark()));

        // Label on row 0, option on row 2 (with prefix "● " = 2 chars)
        self::assertSame(' ', $frame->characters[10]);
        self::assertSame(' ', $frame->characters[2 * 15 + 10]);
    }

    #[Test]
    public function renderWithWidthEqualToTheMarkShowsNoOptionText(): void
    {
        $radio = Radio::new(['Option']);
        $frame = new Frame(10, 3);
        $radio->render($frame, new Rect(0, 0, self::MARK_WIDTH, 3), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[self::MARK_WIDTH]);
    }

    #[Test]
    public function renderWithOneColumnPastTheMarkShowsOneCharOfOptionText(): void
    {
        $radio = Radio::new(['Option']);
        $frame = new Frame(10, 3);
        $radio->render($frame, new Rect(0, 0, self::MARK_WIDTH + 1, 3), new RenderContext(Theme::dark()));

        self::assertSame('O', $frame->characters[self::MARK_WIDTH]);
    }

    public static function radioKeybindingOverrideProvider(): iterable
    {
        yield Actions::FOCUS_PREV => [Actions::FOCUS_PREV, new KeyMsg(Key::Up),    new KeyMsg('x'), 1, 'cursor',   0];
        yield Actions::FOCUS_NEXT => [Actions::FOCUS_NEXT, new KeyMsg(Key::Down),  new KeyMsg('x'), 0, 'cursor',   1];
        yield Actions::SELECT => [Actions::SELECT,     new KeyMsg(Key::Enter), new KeyMsg('x'), 1, 'selected', 1];
    }

    #[DataProvider('radioKeybindingOverrideProvider')]
    #[Test]
    public function supportsCustomKeybinding(
        string $action,
        KeyMsg $defaultKey,
        KeyMsg $customKey,
        int $startingCursor,
        string $observedField,
        int $expected,
    ): void {
        $area = Rect::fromSize(20, 10);
        $widget = Radio::new(['a', 'b', 'c'], 'lbl')->withFocus(true)->withCursor($startingCursor);

        $afterDefault = $widget->update($defaultKey, $area)->model;
        self::assertNotSame($widget, $afterDefault);
        self::assertSame($expected, $afterDefault->$observedField());

        $custom = $widget->withKeybindings(new Keybindings([$action => ['x']]));
        self::assertSame($custom, $custom->update($defaultKey, $area)->model);

        $afterCustom = $custom->update($customKey, $area)->model;
        self::assertSame($expected, $afterCustom->$observedField());
    }

    #[Test]
    public function cursorStaysVisibleWhenAreaIsSmallerThanTotalRows(): void
    {
        // Radio with a 2-row label + 5 options = 7 rows of content.
        // Render into a 4-row area with the cursor on the LAST option.
        // Without auto-scroll the cursor row (y=6) sits below the area
        // (bottom=4) and gets dropped by the clip — invisible to the user.
        // The widget must self-scroll so the cursor row stays in view.
        $radio = Radio::new(['Dark', 'Light', 'Dracula', 'Solarized', 'Rosé Pine'], 'Theme')
            ->withFocus(true);
        // Move cursor to last option (Rosé Pine)
        for ($i = 0; $i < 4; ++$i) {
            $radio = $radio->update(new KeyMsg(Key::Down), new Rect(0, 0, 20, 4))->model;
        }

        $frame = new Frame(20, 6);
        $area = new Rect(0, 0, 20, 4);
        $frame->pushClip($area);
        $radio->render($frame, $area, new RenderContext(Theme::dark()));
        $frame->popClip();

        $found = false;
        for ($y = 0; $y < 4; ++$y) {
            $row = '';
            for ($x = 0; $x < 20; ++$x) {
                $row .= $frame->characters[$y * 20 + $x];
            }
            if (str_contains($row, 'Rosé Pine')) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Cursor option (last) must be visible within the rendered area when the cursor is at the bottom');
    }

    #[Test]
    public function cursorRowWithoutLabelReturnsCursorIndex(): void
    {
        // L79 in Radio: cursorRow() = (label !== '' ? 2 : 0) + cursor
        $radio = Radio::new(['A', 'B', 'C'])->withFocus(true);

        self::assertSame(0, $radio->cursorRow(), 'cursorRow at index 0 with no label must be 0');

        $radio = $radio->update(new KeyMsg(Key::Down), new Rect(0, 0, 10, 5))->model;
        self::assertSame(1, $radio->cursorRow(), 'cursorRow at index 1 with no label must be 1');

        $radio = $radio->update(new KeyMsg(Key::Down), new Rect(0, 0, 10, 5))->model;
        self::assertSame(2, $radio->cursorRow(), 'cursorRow at index 2 with no label must be 2');
    }

    #[Test]
    public function cursorRowWithLabelOffsetsByTwo(): void
    {
        // L79: with label, the cursor row is offset by 2 (for the label + blank line).
        $radio = Radio::new(['A', 'B', 'C'], 'Pick one')->withFocus(true);

        self::assertSame(2, $radio->cursorRow(), 'cursorRow at index 0 with label must be 2');

        $radio = $radio->update(new KeyMsg(Key::Down), new Rect(0, 0, 10, 5))->model;
        self::assertSame(3, $radio->cursorRow(), 'cursorRow at index 1 with label must be 3');
    }

    #[Test]
    public function renderNoScrollWhenAreaFitsAllRows(): void
    {
        // L132/L137 in Radio render: scrollOffset must remain 0 when area is large enough.
        $radio = Radio::new(['A', 'B', 'C'], 'Pick')->withFocus(true);
        $frame = new Frame(10, 10);
        // Need at least: label(2) + 3 options = 5 rows. Area is 10 rows.
        $radio->render($frame, new Rect(0, 0, 10, 10), new RenderContext(Theme::dark()));

        // Label "Pick" at row 0
        self::assertSame('P', $frame->characters[0]);
        self::assertContains(
            $this->markAt($frame, 2 * 10),
            [Radio::PICKED_MARK, Radio::FREE_MARK],
            'first option marker must be at row 2 (after label + blank)',
        );
    }

    #[Test]
    public function labeledRadioRendersLabelBlankSpacerThenOptions(): void
    {
        $radio = Radio::new(['Alpha', 'Beta'], 'Pick');
        $frame = new Frame(20, 5);
        $radio->render($frame, new Rect(0, 0, 20, 5), new RenderContext(Theme::dark()));

        self::assertSame('Pick', rtrim($this->extractRow($frame, 0, 20)), 'Label on row 0');
        self::assertSame('', rtrim($this->extractRow($frame, 1, 20)), 'Row 1 is the spacer');
        self::assertSame(Radio::PICKED_MARK . 'Alpha', rtrim($this->extractRow($frame, 2, 20)), 'First option starts on row 2 — the label occupies exactly two rows');
        self::assertSame(Radio::FREE_MARK . 'Beta', rtrim($this->extractRow($frame, 3, 20)));
    }

    #[Test]
    public function scrollsToKeepTheCursorVisibleWhenOptionsExceedTheHeight(): void
    {
        $radio = Radio::new(['Alpha', 'Beta', 'Gamma', 'Delta'])
            ->withCursor(3)
            ->withFocus(true);
        $frame = new Frame(12, 3);
        $radio->render($frame, new Rect(0, 0, 12, 3), new RenderContext(Theme::dark()));

        self::assertSame(Radio::FREE_MARK . 'Beta', rtrim($this->extractRow($frame, 0, 12)), 'One row scrolled out: Beta is now the top row');
        self::assertSame(Radio::FREE_MARK . 'Gamma', rtrim($this->extractRow($frame, 1, 12)));
        self::assertSame(Radio::FREE_MARK . 'Delta', rtrim($this->extractRow($frame, 2, 12)), 'The cursor row is the last visible row');
    }

    #[Test]
    public function labeledRadioScrollAccountsForExactlyTwoLabelRows(): void
    {
        $radio = Radio::new(['Alpha', 'Beta', 'Gamma', 'Delta'], 'Top')
            ->withCursor(3)
            ->withFocus(true);
        $frame = new Frame(12, 4);
        $radio->render($frame, new Rect(0, 0, 12, 4), new RenderContext(Theme::dark()));

        self::assertSame(Radio::PICKED_MARK . 'Alpha', rtrim($this->extractRow($frame, 0, 12)), 'Offset (2+3)-4+1=2 scrolls the label and spacer out; all four options fit');
        self::assertSame(Radio::FREE_MARK . 'Delta', rtrim($this->extractRow($frame, 3, 12)), 'The cursor row lands on the last visible row');
    }

    #[Test]
    public function clickOnAScrolledLabelledRadioSelectsTheOptionUnderThePointer(): void
    {
        $radio = Radio::new(['Alpha', 'Beta', 'Gamma', 'Delta'], 'Top')
            ->withCursor(3)
            ->withFocus(true);
        $area = new Rect(0, 0, 12, 4);

        $result = $radio->update(new MouseMsg(5, 2, MouseAction::Press, MouseButton::Left), $area);

        self::assertSame('Gamma', $result->model->selectedValue(), 'An offset of 2 scrolls the label and spacer out, so row 2 holds the third option');
        self::assertSame(2, $result->model->cursor());
    }

    #[Test]
    public function readsAsTheOptionItsDotSitsOn(): void
    {
        $radio = Radio::new(['all', '≥ warning', '≥ error']);

        self::assertSame('all', $radio->caption(), 'a fresh radio rests on its first option');
        self::assertSame('≥ warning', $radio->activated('≥ warning')->caption());
    }

    #[Test]
    public function anEmptyRadioReadsAsItsEmptyCaption(): void
    {
        $empty = Radio::new([], 'Severity')->withEmptyCaption('none offered');

        self::assertSame('none offered', $empty->caption());
        self::assertSame(
            'A',
            Radio::new(['A'], 'Severity')->withEmptyCaption('none offered')->caption(),
            'with an option to rest on, the empty caption never shows',
        );
    }

    #[Test]
    public function namesTheOptionUnderAPointerPastTheRowsItsLabelTakes(): void
    {
        $radio = Radio::new(['json', 'yaml', 'toml'], 'Format');
        $area = Rect::fromSize(20, 5);

        $named = $radio->optionAt(new MouseMsg(1, $area->y + 3, MouseAction::Press, MouseButton::Left), $area);

        self::assertSame('yaml', $named, 'the label and its spacer sit above the first option');
        self::assertNull(
            $radio->optionAt(new MouseMsg(1, $area->bottom() + 1, MouseAction::Press, MouseButton::Left), $area),
            'a pointer outside the drawn area addresses no option at all',
        );
        self::assertFalse($radio->hasOption('xml'), 'an option it never offered is not one it can act on');
    }

    #[Test]
    public function measuresItsWidestOptionPlusItsMarkAndCountsItsLabelRows(): void
    {
        $bare = Radio::new(['json', 'yaml', 'ndjson']);

        self::assertSame(6 + self::MARK_WIDTH, $bare->popupWidth(), 'every option is drawn behind a mark');
        self::assertSame(3, $bare->popupHeight(20));
        self::assertSame(5, Radio::new(['json', 'yaml', 'ndjson'], 'Format')->popupHeight(20), 'a label costs a row and a spacer');
    }

    #[Test]
    public function enterOverAnEmptyRadioFinishesNothing(): void
    {
        self::assertTrue(
            Radio::new(['A'], 'Severity')->withFocus(true)->commits(new KeyMsg(Key::Enter)),
            'with something under the cursor, enter is what finishes the choice',
        );
        self::assertFalse(
            Radio::new([], 'Severity')->withFocus(true)->commits(new KeyMsg(Key::Enter)),
            'the binding matches, but there is nothing to choose, so the popup must not close',
        );
    }

    protected function createFocusable(): Focusable
    {
        return Radio::new(['A', 'B'], 'Label');
    }

    private function markAt(Frame $frame, int $index): string
    {
        return implode('', \array_slice($frame->characters, $index, self::MARK_WIDTH));
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
}
