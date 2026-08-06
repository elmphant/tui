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
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Select;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SelectTest extends TestCase
{
    use FocusableBehaviorTrait;

    #[Test]
    public function constraintReturnsExactOptionCount(): void
    {
        $select = Select::new(['A', 'B', 'C']);
        $constraint = $select->constraint();
        self::assertSame(3, $constraint->min);
        self::assertSame(3, $constraint->max);
    }

    #[Test]
    public function arrowDownMovesCursor(): void
    {
        $select = Select::new(['A', 'B', 'C'])->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $select->update(new KeyMsg(Key::Down), $area);
        self::assertInstanceOf(UpdateResult::class, $result);
        self::assertInstanceOf(Select::class, $result->model);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function arrowDownDoesNotMovePastLastOption(): void
    {
        $select = Select::new(['A', 'B'])->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $select->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function arrowUpMovesCursor(): void
    {
        $select = Select::new(['A', 'B', 'C'])->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $select->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Up), $area);
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function enterSelectsCurrentCursorPosition(): void
    {
        $select = Select::new(['A', 'B', 'C'])->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $select->update(new KeyMsg(Key::Down), $area);
        $result = $result->model->update(new KeyMsg(Key::Enter), $area);
        self::assertSame(1, $result->model->selected());
        self::assertSame('B', $result->model->selectedValue());
    }

    #[Test]
    public function rendersCursorStyleWhenFocused(): void
    {
        $select = Select::new(['Alpha', 'Beta'])->withFocus(true);
        $frame = new Frame(40, 5);
        $area = new Rect(0, 0, 40, 5);
        $theme = Theme::dark();
        $select->render($frame, $area, new RenderContext($theme));

        self::assertSame('A', $frame->characters[0]);
        self::assertSame($theme->cursorStyle->backgroundValue(), $frame->backgrounds[0]);
    }

    #[Test]
    public function doesNotRenderCursorStyleWhenNotFocused(): void
    {
        $select = Select::new(['Alpha', 'Beta'])->withFocus(false);
        $frame = new Frame(40, 5);
        $area = new Rect(0, 0, 40, 5);
        $theme = Theme::dark();
        $select->render($frame, $area, new RenderContext($theme));

        self::assertNotSame($theme->cursorStyle->backgroundValue(), $frame->backgrounds[0]);
    }

    #[Test]
    public function updateIgnoresKeyWhenNotFocused(): void
    {
        $select = Select::new(['A', 'B']);
        $area = Rect::fromSize(40, 10);

        $result = $select->update(new KeyMsg(Key::Down), $area);
        self::assertSame($select, $result->model);
    }

    #[Test]
    public function mouseClickSelectsOption(): void
    {
        $select = Select::new(['A', 'B', 'C']);
        $area = new Rect(0, 0, 40, 5);

        $mouseMessage = new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left);
        $result = $select->update($mouseMessage, $area);
        self::assertSame(1, $result->model->selected());
        self::assertSame(1, $result->model->cursor());
    }

    #[Test]
    public function upAtFirstOptionReturnsSameInstance(): void
    {
        $select = Select::new(['A', 'B', 'C'])->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $select->update(new KeyMsg(Key::Up), $area);
        self::assertSame($select, $result->model);
    }

    #[Test]
    public function downAtLastOptionReturnsSameInstance(): void
    {
        $select = Select::new(['A', 'B'])->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $movedDown = $select->update(new KeyMsg(Key::Down), $area)->model;
        self::assertSame(1, $movedDown->cursor());

        $result = $movedDown->update(new KeyMsg(Key::Down), $area);
        self::assertSame($movedDown, $result->model);
    }

    #[Test]
    public function updateWithEmptyOptionsReturnsSelf(): void
    {
        $select = Select::new([])->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $select->update(new KeyMsg(Key::Down), $area);
        self::assertSame($select, $result->model);
    }

    #[Test]
    public function emptySelectConstraintIsZero(): void
    {
        $select = Select::new([]);
        self::assertSame(0, $select->constraint()->min);
        self::assertSame(0, $select->constraint()->max);
    }

    #[Test]
    public function emptySelectRenderDoesNotCrash(): void
    {
        $select = Select::new([]);
        $frame = new Frame(10, 5);
        $select->render($frame, Rect::fromSize(10, 5), new RenderContext(Theme::dark()));
        self::assertTrue(true);
    }

    #[Test]
    public function selectedValueWithNoSelection(): void
    {
        $select = Select::new(['A', 'B']);
        self::assertNull($select->selectedValue());
    }

    #[Test]
    public function defaultSelectedIsMinusOne(): void
    {
        $select = Select::new(['A', 'B', 'C']);
        self::assertSame(-1, $select->selected());
    }

    #[Test]
    public function spaceSelectsCurrentCursorPosition(): void
    {
        $select = Select::new(['A', 'B', 'C'])->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $movedDown = $select->update(new KeyMsg(Key::Down), $area)->model;
        $result = $movedDown->update(new KeyMsg(' '), $area);
        self::assertSame(1, $result->model->selected());
    }

    #[Test]
    public function unhandledKeyReturnsSameInstance(): void
    {
        $select = Select::new(['A', 'B', 'C'])->withFocus(true);
        $area = Rect::fromSize(40, 10);

        $result = $select->update(new KeyMsg('x'), $area);
        self::assertSame($select, $result->model);
    }

    #[Test]
    public function mouseClickWithNonZeroAreaYComputesCorrectLocalY(): void
    {
        $select = Select::new(['A', 'B', 'C']);
        $area = new Rect(0, 5, 40, 10);

        $mouseMessage = new MouseMsg(5, 7, MouseAction::Press, MouseButton::Left);
        $result = $select->update($mouseMessage, $area);
        self::assertSame(2, $result->model->cursor());
        self::assertSame(2, $result->model->selected());
    }

    #[Test]
    public function mouseClickOnFirstOptionAtAreaTopEdge(): void
    {
        $select = Select::new(['A', 'B', 'C']);
        $area = new Rect(0, 3, 40, 10);

        $mouseMessage = new MouseMsg(5, 3, MouseAction::Press, MouseButton::Left);
        $result = $select->update($mouseMessage, $area);
        self::assertSame(0, $result->model->cursor());
        self::assertSame(0, $result->model->selected());
    }

    #[Test]
    public function mouseClickBeyondLastOptionDoesNotSelect(): void
    {
        $select = Select::new(['A', 'B', 'C']);
        $area = new Rect(0, 0, 40, 10);

        $mouseMessage = new MouseMsg(5, 3, MouseAction::Press, MouseButton::Left);
        $result = $select->update($mouseMessage, $area);
        self::assertSame($select, $result->model);
    }

    #[Test]
    public function mouseClickAboveAreaDoesNotSelect(): void
    {
        $select = Select::new(['A', 'B', 'C']);
        $area = new Rect(0, 5, 40, 10);

        $mouseMessage = new MouseMsg(5, 3, MouseAction::Press, MouseButton::Left);
        $result = $select->update($mouseMessage, $area);
        self::assertSame($select, $result->model);
    }

    #[Test]
    public function rendersSelectedOptionWithAccentStyle(): void
    {
        $select = Select::new(['Alpha', 'Beta', 'Gamma'])->withFocus(true);
        $area = Rect::fromSize(40, 5);

        $selected = $select->update(new KeyMsg(Key::Down), $area)->model;
        $selected = $selected->update(new KeyMsg(Key::Enter), $area)->model;
        $selected = $selected->update(new KeyMsg(Key::Down), $area)->model;

        $frame = new Frame(40, 5);
        $theme = Theme::dark();
        $selected->render($frame, $area, new RenderContext($theme));

        $selectedRowIndex = 1 * 40;
        self::assertSame($theme->accent->foregroundValue(), $frame->foregrounds[$selectedRowIndex]);

        $cursorRowIndex = 2 * 40;
        self::assertSame($theme->cursorStyle->backgroundValue(), $frame->backgrounds[$cursorRowIndex]);
    }

    #[Test]
    public function rendersFocusedNonCursorNonSelectedOptionWithBaseStyle(): void
    {
        $select = Select::new(['Alpha', 'Beta', 'Gamma'])->withFocus(true);
        $area = Rect::fromSize(40, 5);
        $frame = new Frame(40, 5);
        $theme = Theme::dark();
        $select->render($frame, $area, new RenderContext($theme));

        $secondRowIndex = 1 * 40;
        self::assertSame($theme->base->foregroundValue(), $frame->foregrounds[$secondRowIndex]);
    }

    #[Test]
    public function rendersUnfocusedUnselectedOptionWithDimStyle(): void
    {
        $select = Select::new(['Alpha', 'Beta']);
        $area = Rect::fromSize(40, 5);
        $frame = new Frame(40, 5);
        $theme = Theme::dark();
        $select->render($frame, $area, new RenderContext($theme));

        self::assertSame($theme->dim->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame($theme->dim->foregroundValue(), $frame->foregrounds[40]);
    }

    #[Test]
    public function renderTruncatesLabelToAreaWidth(): void
    {
        $select = Select::new(['Hello World Long Text']);
        $frame = new Frame(15, 1);
        $select->render($frame, new Rect(0, 0, 10, 1), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[10]);
    }

    #[Test]
    public function scrollsToKeepTheCursorVisibleWhenOptionsExceedTheHeight(): void
    {
        $select = Select::new(['Alpha', 'Beta', 'Gamma', 'Delta'])
            ->withCursor(3)
            ->withFocus(true);
        $frame = new Frame(12, 3);
        $select->render($frame, new Rect(0, 0, 12, 3), new RenderContext(Theme::dark()));

        self::assertSame('Beta', rtrim($this->extractRow($frame, 0, 12)), 'One row scrolled out: Beta is now the top row');
        self::assertSame('Gamma', rtrim($this->extractRow($frame, 1, 12)));
        self::assertSame('Delta', rtrim($this->extractRow($frame, 2, 12)), 'The cursor row is the last visible row');
    }

    #[Test]
    public function clickOnAScrolledListSelectsTheOptionUnderThePointer(): void
    {
        $select = Select::new(['Alpha', 'Beta', 'Gamma', 'Delta'])
            ->withCursor(3)
            ->withFocus(true);
        $area = new Rect(0, 0, 12, 3);

        $result = $select->update(new MouseMsg(5, 0, MouseAction::Press, MouseButton::Left), $area);

        self::assertSame('Beta', $result->model->selectedValue(), 'The top visible row is Beta once one row has scrolled out');
        self::assertSame(1, $result->model->cursor());
    }

    public static function selectKeybindingOverrideProvider(): iterable
    {
        yield Actions::FOCUS_PREV => [Actions::FOCUS_PREV, new KeyMsg(Key::Up),    new KeyMsg('x'), 1, 'cursor',   0];
        yield Actions::FOCUS_NEXT => [Actions::FOCUS_NEXT, new KeyMsg(Key::Down),  new KeyMsg('x'), 0, 'cursor',   1];
        yield Actions::SELECT => [Actions::SELECT,     new KeyMsg(Key::Enter), new KeyMsg('x'), 0, 'selected', 0];
    }

    #[DataProvider('selectKeybindingOverrideProvider')]
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
        $widget = Select::new(['a', 'b', 'c'])->withFocus(true)->withCursor($startingCursor);

        $afterDefault = $widget->update($defaultKey, $area)->model;
        self::assertNotSame($widget, $afterDefault, "default key for $action should change state");
        self::assertSame($expected, $afterDefault->$observedField());

        $custom = $widget->withKeybindings(new Keybindings([$action => ['x']]));
        $afterDefaultOnCustom = $custom->update($defaultKey, $area)->model;
        self::assertSame($custom, $afterDefaultOnCustom, "custom override: default key for $action must not fire");

        $afterCustom = $custom->update($customKey, $area)->model;
        self::assertNotSame($custom, $afterCustom);
        self::assertSame($expected, $afterCustom->$observedField());
    }

    protected function createFocusable(): Focusable
    {
        return Select::new(['A', 'B']);
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
