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
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\CheckboxRow;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\OptionRowLayout;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class CheckboxRowTest extends TestCase
{
    #[Test]
    public function theLabelsCanBeReplacedAfterConstruction(): void
    {
        $frame = new Frame(30, 2);
        $row = CheckboxRow::new(['Old'])->withOptions(['Fresh', 'Newer']);

        $row->render($frame, new Rect(0, 0, 30, 2), new RenderContext(Theme::dark()));

        $line = '';
        for ($column = 0; $column < 30; ++$column) {
            $line .= $frame->characters[$column];
        }

        self::assertStringContainsString('Fresh', $line);
        self::assertStringContainsString('Newer', $line);
        self::assertStringNotContainsString('Old', $line);
    }

    #[Test]
    public function implementsFocusable(): void
    {
        self::assertInstanceOf(Focusable::class, CheckboxRow::new(['A']));
    }

    #[Test]
    public function breaksOntoAFurtherRowRatherThanRunningOffTheEdge(): void
    {
        $row = CheckboxRow::new(['system', 'pages'])->withChecked([true, false]);
        $frame = new Frame(12, 2);

        $row->render($frame, new Rect(0, 0, 12, 2), new RenderContext(Theme::dark()));
        $screen = ScreenBuffer::fromFrame($frame);

        self::assertSame('[x] system', trim($screen->getLine(0)));
        self::assertSame('[ ] pages', trim($screen->getLine(1)), 'the box stays with the label it belongs to');
    }

    #[Test]
    public function asksForTheRowsItsOptionsNeedAtTheWidthItIsGiven(): void
    {
        $row = CheckboxRow::new(['system', 'pages', 'all']);

        self::assertSame(1, $row->measureHeight(40));
        self::assertSame(3, $row->measureHeight(12), 'a narrow pane must hand it the rows it wraps onto');
        self::assertSame(1, $row->constraint()->min, 'one row is the floor, the measurement raises it');
    }

    #[Test]
    public function rightMoveCursorWraps(): void
    {
        $row = CheckboxRow::new(['A', 'B'])->withFocus(true);
        $area = new Rect(0, 0, 40, 1);

        $result = $row->update(new KeyMsg(Key::Right), $area);
        $result = $result->model->update(new KeyMsg(Key::Right), $area);

        self::assertSame(0, $this->getCursor($result->model));
    }

    #[Test]
    public function leftMoveCursorWraps(): void
    {
        $row = CheckboxRow::new(['A', 'B'])->withFocus(true);
        $area = new Rect(0, 0, 40, 1);

        $result = $row->update(new KeyMsg(Key::Left), $area);

        self::assertSame(1, $this->getCursor($result->model));
    }

    #[Test]
    public function spaceTogglesCurrentOption(): void
    {
        $row = CheckboxRow::new(['A', 'B'])->withFocus(true);
        $area = new Rect(0, 0, 40, 1);

        $result = $row->update(new KeyMsg(' '), $area);

        self::assertTrue($result->model->isChecked(0));
        self::assertFalse($result->model->isChecked(1));
    }

    #[Test]
    public function enterTogglesCurrentOption(): void
    {
        $row = CheckboxRow::new(['A', 'B'])->withFocus(true);
        $area = new Rect(0, 0, 40, 1);

        $result = $row->update(new KeyMsg(Key::Enter), $area);

        self::assertTrue($result->model->isChecked(0));
    }

    #[Test]
    public function toggleUnchecks(): void
    {
        $row = CheckboxRow::new(['A', 'B'])->withFocus(true);
        $area = new Rect(0, 0, 40, 1);

        $result = $row->update(new KeyMsg(' '), $area);
        $result = $result->model->update(new KeyMsg(' '), $area);

        self::assertFalse($result->model->isChecked(0));
    }

    #[Test]
    public function checkedIndices(): void
    {
        $row = CheckboxRow::new(['A', 'B', 'C'])->withFocus(true);
        $area = new Rect(0, 0, 40, 1);

        $row = $row->update(new KeyMsg(' '), $area)->model;
        $row = $row->update(new KeyMsg(Key::Right), $area)->model;
        $row = $row->update(new KeyMsg(Key::Right), $area)->model;
        $row = $row->update(new KeyMsg(' '), $area)->model;

        self::assertSame([0, 2], $row->checkedIndices());
    }

    #[Test]
    public function ignoresKeyWhenNotFocused(): void
    {
        $row = CheckboxRow::new(['A', 'B']);
        $area = new Rect(0, 0, 40, 1);

        $result = $row->update(new KeyMsg(' '), $area);

        self::assertSame($row, $result->model);
    }

    #[Test]
    public function withFocusReturnsNewInstance(): void
    {
        $row = CheckboxRow::new(['A']);

        self::assertFalse($row->isFocused());
        self::assertTrue($row->withFocus(true)->isFocused());
    }

    #[Test]
    public function mouseClickTogglesOption(): void
    {
        $row = CheckboxRow::new(['Alpha', 'Beta']);
        $area = new Rect(0, 0, 40, 1);

        $result = $row->update(
            new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left),
            $area,
        );

        self::assertTrue($result->model->isChecked(0));
    }

    #[Test]
    public function mouseClickOnSecondOption(): void
    {
        $row = CheckboxRow::new(['AB', 'CD']);
        $area = new Rect(0, 0, 40, 1);

        $clickX = 4 + 2 + 2;
        $result = $row->update(
            new MouseMsg($clickX, 0, MouseAction::Press, MouseButton::Left),
            $area,
        );

        self::assertTrue($result->model->isChecked(1));
        self::assertFalse($result->model->isChecked(0));
    }

    #[Test]
    public function mouseClickOutsideDoesNothing(): void
    {
        $row = CheckboxRow::new(['A']);
        $area = new Rect(0, 0, 40, 1);

        $result = $row->update(
            new MouseMsg(0, 5, MouseAction::Press, MouseButton::Left),
            $area,
        );

        self::assertSame($row, $result->model);
    }

    #[Test]
    public function prefixRendered(): void
    {
        $row = CheckboxRow::new(['X'])->withPrefix('Filter: ');
        $frame = new Frame(30, 1);
        $context = new RenderContext(Theme::dark());

        $row->render($frame, new Rect(0, 0, 30, 1), $context);

        self::assertSame('F', $frame->characters[0]);
        self::assertSame('i', $frame->characters[1]);
    }

    #[Test]
    public function prefixOffsetsMouse(): void
    {
        $row = CheckboxRow::new(['AB'])->withPrefix('Pre: ');
        $area = new Rect(0, 0, 40, 1);

        $clickX = 5;
        $result = $row->update(
            new MouseMsg($clickX, 0, MouseAction::Press, MouseButton::Left),
            $area,
        );

        self::assertTrue($result->model->isChecked(0));
    }

    #[Test]
    public function rendersCheckedMark(): void
    {
        $row = CheckboxRow::new(['A'])->withFocus(true);
        $area = new Rect(0, 0, 20, 1);

        $toggled = $row->update(new KeyMsg(' '), $area)->model;

        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());
        $toggled->render($frame, new Rect(0, 0, 20, 1), $context);

        self::assertSame('[', $frame->characters[0]);
        self::assertSame('x', $frame->characters[1]);
    }

    #[Test]
    public function rendersUncheckedMark(): void
    {
        $row = CheckboxRow::new(['A']);
        $frame = new Frame(20, 1);
        $context = new RenderContext(Theme::dark());

        $row->render($frame, new Rect(0, 0, 20, 1), $context);

        self::assertSame('[', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[1]);
    }

    #[Test]
    public function emptyLabelsHandleGracefully(): void
    {
        $row = CheckboxRow::new([])->withFocus(true);
        $area = new Rect(0, 0, 20, 1);

        $result = $row->update(new KeyMsg(Key::Right), $area);

        self::assertSame($row, $result->model);
    }

    #[Test]
    public function renderWithZeroWidthDoesNotCrash(): void
    {
        $row = CheckboxRow::new(['A']);
        $frame = new Frame(10, 1);
        $context = new RenderContext(Theme::dark());

        $row->render($frame, new Rect(0, 0, 0, 1), $context);

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function unrecognizedKeyReturnsSameModelWhenFocused(): void
    {
        $row = CheckboxRow::new(['A', 'B'])->withFocus(true);
        $area = new Rect(0, 0, 40, 1);

        $result = $row->update(new KeyMsg('z'), $area);

        self::assertSame($row, $result->model);
    }

    #[Test]
    public function isCheckedReturnsFalseForOutOfBoundsIndex(): void
    {
        $row = CheckboxRow::new(['A', 'B']);

        self::assertFalse($row->isChecked(99));
    }

    #[Test]
    public function leftFromMiddleCursorGoesToPreviousNotNext(): void
    {
        $row = CheckboxRow::new(['A', 'B', 'C'])->withFocus(true);
        $area = new Rect(0, 0, 40, 1);

        $row = $row->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(1, $this->getCursor($row));

        $row = $row->update(new KeyMsg(Key::Left), $area)->model;
        self::assertSame(0, $this->getCursor($row));
    }

    #[Test]
    public function leftKeyRemovalMutantKiller(): void
    {
        $row = CheckboxRow::new(['A', 'B', 'C'])->withFocus(true);
        $area = new Rect(0, 0, 40, 1);

        $result = $row->update(new KeyMsg(Key::Left), $area);

        self::assertNotSame($row, $result->model);
        self::assertSame(2, $this->getCursor($result->model));
    }

    #[Test]
    public function prefixClickPositionUsesIncrementNotAbsoluteAssignment(): void
    {
        $row = CheckboxRow::new(['AB'])->withPrefix('Hi');
        $area = new Rect(5, 0, 40, 1);

        $clickBeyondMutantRange = $row->update(
            new MouseMsg(12, 0, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertTrue($clickBeyondMutantRange->model->isChecked(0), 'Click at x=12 hits option in range [7,15) but misses mutant range [2,10)');

        $clickAtPrefixLenOnly = $row->update(
            new MouseMsg(2, 0, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertFalse($clickAtPrefixLenOnly->model->isChecked(0), 'Click at prefixLen(2) without baseX offset must miss');
    }

    #[Test]
    public function prefixShiftsMouseClickPositions(): void
    {
        $row = CheckboxRow::new(['AB'])->withPrefix('Hi');
        $area = new Rect(0, 0, 40, 1);

        $clickBeforePrefix = $row->update(
            new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertFalse($clickBeforePrefix->model->isChecked(0));

        $clickAtOption = $row->update(
            new MouseMsg(2, 0, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertTrue($clickAtOption->model->isChecked(0));
    }

    #[Test]
    public function multibytePrefixShiftsMouseClickCorrectly(): void
    {
        $row = CheckboxRow::new(['AB'])->withPrefix("\u{00FC}\u{00FC}");
        $area = new Rect(0, 0, 40, 1);

        $clickAtPrefixEnd = $row->update(
            new MouseMsg(1, 0, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertFalse($clickAtPrefixEnd->model->isChecked(0));

        $clickAtOption = $row->update(
            new MouseMsg(2, 0, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertTrue($clickAtOption->model->isChecked(0));
    }

    #[Test]
    public function aClickOnTheLastColumnOfAnOptionStillPicksIt(): void
    {
        $row = CheckboxRow::new(['AB', 'CD']);
        $area = new Rect(0, 0, 40, 1);

        $lastColumn = CharWidth::stringWidth(CheckboxRow::UNCHECKED_MARK . 'AB') - 1;
        $clickAtBoundary = $row->update(
            new MouseMsg($lastColumn, 0, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertTrue($clickAtBoundary->model->isChecked(0));
        self::assertFalse($clickAtBoundary->model->isChecked(1));

        $clickAtNextOption = $row->update(
            new MouseMsg($lastColumn + 1 + OptionRowLayout::GAP, 0, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertTrue($clickAtNextOption->model->isChecked(1));
        self::assertFalse($clickAtNextOption->model->isChecked(0));
    }

    #[Test]
    public function multibyteLabelsCalculateClickBoundariesCorrectly(): void
    {
        $row = CheckboxRow::new(["\u{00FC}\u{00FC}", 'B']);
        $area = new Rect(0, 0, 40, 1);

        $optionWidth = 4 + 2 + 2;
        $clickOnSecond = $row->update(
            new MouseMsg($optionWidth, 0, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertTrue($clickOnSecond->model->isChecked(1));
        self::assertFalse($clickOnSecond->model->isChecked(0));
    }

    #[Test]
    public function mouseClickOnThirdOptionVerifiesCumulativeOffset(): void
    {
        $row = CheckboxRow::new(['A', 'B', 'C']);
        $area = new Rect(0, 0, 60, 1);

        $firstWidth = 4 + 1 + 2;
        $secondWidth = 4 + 1 + 2;
        $thirdStart = $firstWidth + $secondWidth;

        $result = $row->update(
            new MouseMsg($thirdStart, 0, MouseAction::Press, MouseButton::Left),
            $area,
        );

        self::assertTrue($result->model->isChecked(2));
        self::assertFalse($result->model->isChecked(0));
        self::assertFalse($result->model->isChecked(1));
    }

    #[Test]
    public function prefixRenderedAtCorrectPositionFollowedByCheckbox(): void
    {
        $row = CheckboxRow::new(['X'])->withPrefix('AB');
        $frame = new Frame(30, 1);
        $context = new RenderContext(Theme::dark());

        $row->render($frame, new Rect(0, 0, 30, 1), $context);

        self::assertSame('A', $frame->characters[0]);
        self::assertSame('B', $frame->characters[1]);
        self::assertSame('[', $frame->characters[2]);
    }

    #[Test]
    public function renderUncheckedUnfocusedUsesDimStyle(): void
    {
        $theme = Theme::dark();
        $row = CheckboxRow::new(['A']);
        $frame = new Frame(20, 1);

        $row->render($frame, new Rect(0, 0, 20, 1), new RenderContext($theme));

        self::assertSame($theme->dim->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function renderFocusedCursorItemUsesCursorStyle(): void
    {
        $theme = Theme::dark();
        $row = CheckboxRow::new(['A'])->withFocus(true);
        $frame = new Frame(20, 1);

        $row->render($frame, new Rect(0, 0, 20, 1), new RenderContext($theme));

        self::assertSame($theme->cursorStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function renderCheckedNonCursorUsesAccentStyle(): void
    {
        $theme = Theme::dark();
        $row = CheckboxRow::new(['A', 'B'])->withFocus(true);
        $area = new Rect(0, 0, 40, 1);

        $row = $row->update(new KeyMsg(' '), $area)->model;
        $row = $row->update(new KeyMsg(Key::Right), $area)->model;

        $frame = new Frame(40, 1);
        $row->render($frame, new Rect(0, 0, 40, 1), new RenderContext($theme));

        self::assertSame($theme->accent->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function renderFocusedNonCursorUncheckedUsesBaseStyle(): void
    {
        $theme = Theme::dark();
        $row = CheckboxRow::new(['A', 'B'])->withFocus(true);
        $area = new Rect(0, 0, 40, 1);

        $row = $row->update(new KeyMsg(Key::Right), $area)->model;

        $frame = new Frame(40, 1);
        $row->render($frame, new Rect(0, 0, 40, 1), new RenderContext($theme));

        self::assertSame($theme->base->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function renderCursorOnlyOnFocusedIndex(): void
    {
        $theme = Theme::dark();
        $row = CheckboxRow::new(['A', 'B'])->withFocus(true);
        $frame = new Frame(40, 1);

        $row->render($frame, new Rect(0, 0, 40, 1), new RenderContext($theme));

        self::assertSame($theme->cursorStyle->foregroundValue(), $frame->foregrounds[0]);

        $secondStart = 4 + 1 + 2;
        self::assertSame($theme->base->foregroundValue(), $frame->foregrounds[$secondStart]);
    }

    #[Test]
    public function renderMultipleLabelsAtCorrectPositions(): void
    {
        $row = CheckboxRow::new(['AB', 'CD']);
        $frame = new Frame(40, 1);
        $context = new RenderContext(Theme::dark());

        $row->render($frame, new Rect(0, 0, 40, 1), $context);

        self::assertSame('[', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[1]);
        self::assertSame(']', $frame->characters[2]);
        self::assertSame(' ', $frame->characters[3]);
        self::assertSame('A', $frame->characters[4]);
        self::assertSame('B', $frame->characters[5]);
        self::assertSame(' ', $frame->characters[6]);
        self::assertSame(' ', $frame->characters[7]);

        $secondStart = 4 + 2 + 2;
        self::assertSame('[', $frame->characters[$secondStart]);
        self::assertSame(' ', $frame->characters[$secondStart + 1]);
        self::assertSame(']', $frame->characters[$secondStart + 2]);
        self::assertSame(' ', $frame->characters[$secondStart + 3]);
        self::assertSame('C', $frame->characters[$secondStart + 4]);
        self::assertSame('D', $frame->characters[$secondStart + 5]);
    }

    #[Test]
    public function leavesTheGapBetweenOptionsBlank(): void
    {
        $theme = Theme::dark();
        $row = CheckboxRow::new(['A', 'B'])->withFocus(true);
        $frame = new Frame(40, 1);

        $row->render($frame, new Rect(0, 0, 40, 1), new RenderContext($theme));

        $gap = CharWidth::stringWidth(CheckboxRow::UNCHECKED_MARK . 'A');
        self::assertSame(' ', $frame->characters[$gap], 'the gap carries no option, so it carries no mark');
        self::assertSame('[', $frame->characters[$gap + OptionRowLayout::GAP], 'and the next option starts past it');
    }

    #[Test]
    public function mouseClickSetsCursorToClickedIndex(): void
    {
        $row = CheckboxRow::new(['A', 'B', 'C']);
        $area = new Rect(0, 0, 60, 1);

        $secondStart = 4 + 1 + 2;
        $result = $row->update(
            new MouseMsg($secondStart, 0, MouseAction::Press, MouseButton::Left),
            $area,
        );

        self::assertSame(1, $this->getCursor($result->model));
    }

    #[Test]
    public function renderWithNonZeroAreaOffset(): void
    {
        $row = CheckboxRow::new(['A']);
        $frame = new Frame(30, 3);
        $context = new RenderContext(Theme::dark());

        $row->render($frame, new Rect(5, 1, 20, 1), $context);

        self::assertSame(' ', $frame->characters[0]);
        $offset = 1 * 30 + 5;
        self::assertSame('[', $frame->characters[$offset]);
    }

    #[Test]
    public function prefixRenderUsesIncrementNotAbsoluteAssignment(): void
    {
        $row = CheckboxRow::new(['X'])->withPrefix('AB');
        $frame = new Frame(30, 1);
        $context = new RenderContext(Theme::dark());

        $row->render($frame, new Rect(3, 0, 25, 1), $context);

        self::assertSame('A', $frame->characters[3]);
        self::assertSame('B', $frame->characters[4]);
        self::assertSame('[', $frame->characters[5], 'Checkbox must start at area.x(3) + prefixLen(2) = 5, not at prefixLen(2) = 2');
        self::assertSame(' ', $frame->characters[2], 'Column 2 must be empty (before area.x)');
    }

    #[Test]
    public function clickHitsCorrectCjkLabelPosition(): void
    {
        $row = CheckboxRow::new(['漢字', 'B'])->withFocus(true);
        $area = new Rect(0, 0, 30, 1);

        // Each han character takes two columns, so the option ends at 7. Counted
        // as one column each it would end at 5 and this click would miss.
        $click = new MouseMsg(7, 0, MouseAction::Press, MouseButton::Left);
        $result = $row->update($click, $area);

        self::assertTrue($result->model->isChecked(0), 'the last column of 漢字 belongs to it');
        self::assertFalse($result->model->isChecked(1), 'Option B should not be checked');
    }

    #[Test]
    public function supportsCustomCursorLeftKeybinding(): void
    {
        $area = Rect::fromSize(40, 3);
        $widget = CheckboxRow::new(['a', 'b', 'c'])->withFocus(true)->withCursor(1);

        $afterDefault = $widget->update(new KeyMsg(Key::Left), $area)->model;
        self::assertSame(0, $this->getCursor($afterDefault));

        $custom = $widget->withKeybindings(new Keybindings([Actions::CURSOR_LEFT => ['x']]));
        self::assertSame($custom, $custom->update(new KeyMsg(Key::Left), $area)->model);

        $afterCustom = $custom->update(new KeyMsg('x'), $area)->model;
        self::assertSame(0, $this->getCursor($afterCustom));
    }

    #[Test]
    public function supportsCustomCursorRightKeybinding(): void
    {
        $area = Rect::fromSize(40, 3);
        $widget = CheckboxRow::new(['a', 'b', 'c'])->withFocus(true);

        $afterDefault = $widget->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(1, $this->getCursor($afterDefault));

        $custom = $widget->withKeybindings(new Keybindings([Actions::CURSOR_RIGHT => ['x']]));
        self::assertSame($custom, $custom->update(new KeyMsg(Key::Right), $area)->model);

        $afterCustom = $custom->update(new KeyMsg('x'), $area)->model;
        self::assertSame(1, $this->getCursor($afterCustom));
    }

    #[Test]
    public function supportsCustomToggleKeybinding(): void
    {
        $area = Rect::fromSize(40, 3);
        $widget = CheckboxRow::new(['a', 'b', 'c'])->withFocus(true);

        $afterDefault = $widget->update(new KeyMsg(Key::Enter), $area)->model;
        self::assertTrue($afterDefault->isChecked(0));

        $custom = $widget->withKeybindings(new Keybindings([Actions::TOGGLE => ['x']]));
        self::assertFalse($custom->update(new KeyMsg(Key::Enter), $area)->model->isChecked(0));

        $afterCustom = $custom->update(new KeyMsg('x'), $area)->model;
        self::assertTrue($afterCustom->isChecked(0));
    }

    private function getCursor(mixed $model): int
    {
        $reflection = new ReflectionProperty($model, 'cursor');

        return $reflection->getValue($model);
    }
}
