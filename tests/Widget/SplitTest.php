<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\LayoutPlan;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Structure\Split;
use Elmphant\Tui\Widget\Text;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SplitTest extends TestCase
{
    #[Test]
    public function aGlobalRebindingReachesTheKeysAPaneAnswers(): void
    {
        $area = new Rect(0, 0, 40, 6);
        $list = ListView::new([new SimpleListItem('a'), new SimpleListItem('b')])->withFocus(true);
        $split = Split::row(SplitState::even(2), $list, Text::new('right'))
            ->withGlobalKeybindings(new Keybindings([Actions::CURSOR_DOWN => ['j']]));

        $updated = $split->update(new KeyMsg('j'), $area)->model;

        self::assertInstanceOf(Split::class, $updated);
        $first = [...$updated->childWidgets($area)][0][0];
        self::assertInstanceOf(ListView::class, $first);
        self::assertSame(1, $first->cursor(), 'a split must hand its panes the bindings the app made active');
    }

    #[Test]
    public function aPaneReceivesTheKeysTypedIntoIt(): void
    {
        $area = new Rect(0, 0, 40, 6);
        $split = Split::row(SplitState::even(2), TextInput::new()->withFocus(true), Text::new('right'));

        $updated = $split->update(new KeyMsg('h'), $area)->model;

        self::assertInstanceOf(Split::class, $updated);
        $first = [...$updated->childWidgets($area)][0][0];
        self::assertInstanceOf(TextInput::class, $first);
        self::assertSame('h', $first->value(), 'A split that swallows input is a dead end for anything interactive');
    }

    #[Test]
    public function aPreResolvedLayoutPlanDrawsExactlyWhatDirectLayoutDraws(): void
    {
        $area = new Rect(0, 0, 24, 6);

        foreach ([[1, 1], [3, 1, 1], [1, 4]] as $shares) {
            $children = array_map(static fn (int $share): Text => Text::new(str_repeat('x', $share + 2)), $shares);
            $split = Split::row(new SplitState($shares), ...$children);

            $planned = new Frame(24, 6);
            (new RenderContext(Theme::dark()))->renderWithPlan($split, $planned, $area, LayoutPlan::resolve($split, $area));

            $direct = new Frame(24, 6);
            $split->render($direct, $area, new RenderContext(Theme::dark()));

            self::assertSame(
                $direct->characters,
                $planned->characters,
                'The two layout paths must agree for shares ' . implode('/', $shares),
            );
        }
    }

    #[Test]
    public function refusesFewerSharesThanChildren(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Split needs as many shares as children, got 3 children and 2 shares');

        Split::row(SplitState::even(2), Text::new('a'), Text::new('b'), Text::new('c'));
    }

    #[Test]
    public function theSpareColumnGoesToThePaneWithTheLargestClaimOnItWhereverItSits(): void
    {
        self::assertSame([4, 1, 1], $this->paneWidthsForShares([3, 1, 1]), 'Six columns over shares of three, one and one leaves one spare, and the widest pane has the strongest claim on it');
        self::assertSame([1, 1, 4], $this->paneWidthsForShares([1, 1, 3]), 'Moving the widest share to the end moves the spare column with it');
    }

    #[Test]
    public function refusesMoreSharesThanChildren(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Split needs as many shares as children, got 2 children and 3 shares');

        Split::row(SplitState::even(3), Text::new('a'), Text::new('b'));
    }

    #[Test]
    public function dividesItsAreaEvenlyByDefault(): void
    {
        $split = Split::row(SplitState::even(2), Text::new('left'), Text::new('right'));

        $widths = $this->paneWidths($split, Rect::fromSize(21, 10));

        self::assertSame([10, 10], $widths, 'Two panes and a one cell divider in 21 columns');
    }

    #[Test]
    public function fillsTheAxisExactlyAtEveryWidth(): void
    {
        $split = Split::row(SplitState::even(3), Text::new('a'), Text::new('b'), Text::new('c'));

        for ($width = 10; $width <= 200; ++$width) {
            $widths = $this->paneWidths($split, Rect::fromSize($width, 10));

            self::assertSame(
                $width - 2,
                array_sum($widths),
                "Three panes and two dividers account for every column of $width",
            );
        }
    }

    #[Test]
    public function draggingADividerMovesTheBoundary(): void
    {
        $area = Rect::fromSize(21, 10);
        $split = Split::row(SplitState::even(2), Text::new('left'), Text::new('right'));

        // The divider sits at column 10; take hold of it and pull it right.
        $dragged = $this->drag($split, $area, from: 10, to: 14);

        self::assertSame([14, 6], $this->paneWidths($dragged, $area));
    }

    #[Test]
    public function aDragStopsAtTheMinimumOfThePaneItShrinks(): void
    {
        $area = Rect::fromSize(21, 10);
        $split = Split::row(SplitState::even(2), Text::new('left'), Text::new('right'))
            ->withMinimums(6, 8);

        self::assertSame([12, 8], $this->paneWidths($this->drag($split, $area, from: 10, to: 19), $area));
        self::assertSame([6, 14], $this->paneWidths($this->drag($split, $area, from: 10, to: 1), $area));
    }

    #[Test]
    public function dragsTheMiddleBoundaryAndLeavesTheFirstPaneAlone(): void
    {
        $area = Rect::fromSize(32, 10);
        $split = Split::row(SplitState::even(3), Text::new('a'), Text::new('b'), Text::new('c'));

        // Three panes of ten in 32 columns put the second divider at column 21.
        $dragged = $this->drag($split, $area, from: 21, to: 26);

        self::assertSame([10, 15, 5], $this->paneWidths($dragged, $area));
    }

    #[Test]
    public function panesSitWhereTheAreaStartsRatherThanAtTheOrigin(): void
    {
        $area = new Rect(5, 3, 21, 6);
        $split = Split::row(SplitState::even(2), Text::new('left'), Text::new('right'));

        $panes = [];
        foreach ($split->childWidgets($area) as [, $pane]) {
            $panes[] = [$pane->x, $pane->y, $pane->width, $pane->height];
        }

        self::assertSame([[5, 3, 10, 6], [16, 3, 10, 6]], $panes);
        self::assertSame(0, $split->dividerAt(new MouseMsg(15, 4, MouseAction::Press, MouseButton::Left), $area));

        $frame = new Frame(30, 10);
        $split->render($frame, $area, new RenderContext(Theme::dark()));
        self::assertSame('│', $frame->characters[3 * 30 + 15], 'The divider follows the offset area');
    }

    #[Test]
    public function findsADividerOnlyOnTheColumnItOccupies(): void
    {
        $area = Rect::fromSize(32, 10);
        $split = Split::row(SplitState::even(3), Text::new('a'), Text::new('b'), Text::new('c'));

        self::assertNull($this->dividerUnder($split, $area, 9), 'One column left of the first divider');
        self::assertSame(0, $this->dividerUnder($split, $area, 10));
        self::assertNull($this->dividerUnder($split, $area, 11), 'One column right of it');
        self::assertSame(1, $this->dividerUnder($split, $area, 21), 'The second divider, past an accumulated offset');
        self::assertNull($this->dividerUnder($split, $area, 32), 'The far edge of the last pane divides nothing');
        self::assertNull($this->dividerUnder($split, $area, 40), 'Outside the area entirely');
    }

    #[Test]
    public function onlyALeftButtonMouseMessageTouchesTheDivision(): void
    {
        $area = Rect::fromSize(21, 10);
        $split = Split::row(SplitState::even(2), Text::new('left'), Text::new('right'));

        $key = $split->update(new KeyMsg(Key::Enter), $area)->model;
        self::assertSame($split, $key, 'A key message is not a drag');

        $right = $split->update(new MouseMsg(10, 0, MouseAction::Press, MouseButton::Right), $area)->model;
        self::assertSame($split, $right, 'Only the left button grabs a divider');
    }

    #[Test]
    public function onlyMotionMovesAGrabbedDivider(): void
    {
        $area = Rect::fromSize(21, 10);
        $split = Split::row(SplitState::even(2), Text::new('left'), Text::new('right'));

        $motion = $split->update(new MouseMsg(14, 0, MouseAction::Motion, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Split::class, $motion);
        self::assertSame([10, 10], $this->paneWidths($motion, $area), 'Nothing was grabbed');

        $grabbed = $split->update(new MouseMsg(10, 0, MouseAction::Press, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Split::class, $grabbed);

        $wheeled = $grabbed->update(new MouseMsg(14, 0, MouseAction::WheelUp, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Split::class, $wheeled);
        self::assertSame([10, 10], $this->paneWidths($wheeled, $area), 'A wheel is not a drag');
    }

    #[Test]
    public function unevenPanesSitEndToEndAlongTheAxis(): void
    {
        $area = Rect::fromSize(22, 6);
        $split = Split::row(new SplitState([500, 300, 200]), Text::new('a'), Text::new('b'), Text::new('c'));

        $placed = [];
        foreach ($split->childWidgets($area) as [, $pane]) {
            $placed[] = [$pane->x, $pane->width];
        }

        self::assertSame([[0, 10], [11, 6], [18, 4]], $placed);
        self::assertSame(0, $this->dividerUnder($split, $area, 10));
        self::assertSame(1, $this->dividerUnder($split, $area, 17));
    }

    #[Test]
    public function draggingUnevenPanesMovesOnlyTheGrabbedPair(): void
    {
        $area = Rect::fromSize(22, 6);
        $split = Split::row(new SplitState([500, 300, 200]), Text::new('a'), Text::new('b'), Text::new('c'));

        self::assertSame([10, 9, 1], $this->paneWidths($this->drag($split, $area, from: 17, to: 20), $area));
        self::assertSame([10, 2, 8], $this->paneWidths($this->drag($split, $area, from: 17, to: 13), $area));
    }

    #[Test]
    public function drawsNoDividerPastTheLastPaneEvenWhenTheFrameHasRoom(): void
    {
        $area = new Rect(0, 0, 20, 2);
        $split = Split::row(SplitState::even(3), Text::new('a'), Text::new('b'), Text::new('c'));

        $frame = new Frame(26, 2);
        $split->render($frame, $area, new RenderContext(Theme::dark()));

        $dividers = [];
        for ($column = 0; $column < 26; ++$column) {
            if ($frame->characters[$column] === '│') {
                $dividers[] = $column;
            }
        }

        self::assertSame([6, 13], $dividers, 'Nothing is drawn at column 20, past the last pane');
    }

    #[Test]
    public function aPointerOutsideTheAreaGrabsNothing(): void
    {
        $area = Rect::fromSize(32, 10);
        $split = Split::row(SplitState::even(3), Text::new('a'), Text::new('b'), Text::new('c'));

        self::assertNull(
            $split->dividerAt(new MouseMsg(10, 20, MouseAction::Press, MouseButton::Left), $area),
            'On a divider column, but below the area',
        );
    }

    #[Test]
    public function aPaneWithNoMinimumOfItsOwnFallsBackToOne(): void
    {
        $split = Split::row(SplitState::even(3), Text::new('a'), Text::new('b'), Text::new('c'))
            ->withMinimums(5);

        self::assertSame([5, 1, 1], $this->paneWidths($split, Rect::fromSize(9, 4)));
    }

    #[Test]
    public function panesAndMinimumsAreIndexedByPositionWhateverTheCallerSpread(): void
    {
        $area = Rect::fromSize(21, 10);

        // Spreading a string-keyed array into a variadic is legal PHP: the keys
        // arrive as named arguments, and positional indexing needs them dropped
        // while the order they were written in stays.
        $split = Split::row(SplitState::even(2), ...['b' => Text::new('left'), 'a' => Text::new('right')])
            ->withMinimums(...['left' => 8, 'right' => 6]);

        self::assertSame([14, 6], $this->paneWidths($this->drag($split, $area, from: 10, to: 19), $area));
    }

    #[Test]
    public function everyPaneKeepsAtLeastOneColumn(): void
    {
        $split = Split::row(SplitState::even(3), Text::new('a'), Text::new('b'), Text::new('c'))
            ->withMinimums(0, -5, 3);

        self::assertSame([1, 1, 2], $this->paneWidths($split, Rect::fromSize(6, 4)));
    }

    #[Test]
    public function minimumsTooBigForTheAreaAreSharedOutInProportion(): void
    {
        $split = Split::row(SplitState::even(3), Text::new('a'), Text::new('b'), Text::new('c'))
            ->withMinimums(10, 10, 10);

        self::assertSame([10, 10, 9], $this->paneWidths($split, Rect::fromSize(31, 4)), 'One column short, and the last pane is the one that goes without');
        self::assertSame([10, 10, 10], $this->paneWidths($split, Rect::fromSize(32, 4)), 'Exactly enough for them');
    }

    #[Test]
    public function anAreaWithNoRoomLeavesEveryPaneEmpty(): void
    {
        $split = Split::row(SplitState::even(3), Text::new('a'), Text::new('b'), Text::new('c'));

        self::assertSame([0, 0, 0], $this->paneWidths($split, Rect::fromSize(0, 10)));
    }

    #[Test]
    public function aColumnDrawsItsDividerAcrossTheWholeWidth(): void
    {
        $area = Rect::fromSize(6, 9);
        $split = Split::column(SplitState::even(2), Text::new('top'), Text::new('bot'));

        $frame = new Frame(6, 9);
        $split->render($frame, $area, new RenderContext(Theme::dark()));

        for ($column = 0; $column < 6; ++$column) {
            self::assertSame('─', $frame->characters[4 * 6 + $column], "The divider fills column $column of row 4");
        }

        self::assertSame('t', $frame->characters[0], 'The first child drew into its pane');
        self::assertSame('b', $frame->characters[5 * 6], 'The second child drew below the divider');
    }

    #[Test]
    public function drawsOneDividerFewerThanItHasPanes(): void
    {
        $area = Rect::fromSize(32, 3);
        $split = Split::row(SplitState::even(3), Text::new('a'), Text::new('b'), Text::new('c'));

        $frame = new Frame(32, 3);
        $split->render($frame, $area, new RenderContext(Theme::dark()));

        $dividers = [];
        for ($column = 0; $column < 32; ++$column) {
            if ($frame->characters[$column] === '│') {
                $dividers[] = $column;
            }
        }

        self::assertSame([10, 21], $dividers, 'Two dividers for three panes, none past the last');
    }

    #[Test]
    public function releasingEndsTheDragSoLaterMotionMovesNothing(): void
    {
        $area = Rect::fromSize(21, 10);
        $dragged = $this->drag(
            Split::row(SplitState::even(2), Text::new('left'), Text::new('right')),
            $area,
            from: 10,
            to: 14,
        );

        $released = $dragged->update(new MouseMsg(14, 0, MouseAction::Release, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Split::class, $released);

        $after = $released->update(new MouseMsg(4, 0, MouseAction::Motion, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Split::class, $after);

        self::assertSame([14, 6], $this->paneWidths($after, $area), 'The pointer moved with no button pressed on a divider');
    }

    #[Test]
    public function aColumnDividesTheOtherAxis(): void
    {
        $area = Rect::fromSize(40, 21);
        $split = Split::column(SplitState::even(2), Text::new('top'), Text::new('bottom'))
            ->withMinimums(4, 4);

        $heights = [];
        foreach ($split->childWidgets($area) as [, $pane]) {
            $heights[] = $pane->height;
            self::assertSame(40, $pane->width, 'A column keeps the full width');
        }

        self::assertSame([10, 10], $heights);

        $pressed = $split->update(new MouseMsg(0, 10, MouseAction::Press, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Split::class, $pressed);
        $moved = $pressed->update(new MouseMsg(0, 14, MouseAction::Motion, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Split::class, $moved);

        $dragged = [];
        foreach ($moved->childWidgets($area) as [, $pane]) {
            $dragged[] = $pane->height;
        }

        self::assertSame([14, 6], $dragged, 'The divider follows the pointer down');
    }

    #[Test]
    public function drawsAGrabbableDividerBetweenThePanes(): void
    {
        $area = Rect::fromSize(21, 4);
        $split = Split::row(SplitState::even(2), Text::new('left'), Text::new('right'));

        $frame = new Frame(21, 4);
        $split->render($frame, $area, new RenderContext(Theme::dark()));

        for ($row = 0; $row < 4; ++$row) {
            self::assertSame('│', $frame->characters[$row * 21 + 10], "The divider fills column 10 of row $row");
        }
    }

    #[Test]
    public function theDividerBeingDraggedIsMarkedOut(): void
    {
        $area = Rect::fromSize(21, 4);
        $split = Split::row(SplitState::even(2), Text::new('left'), Text::new('right'));

        self::assertNotSame(
            $this->dividerColour($split, $area),
            $this->dividerColour($this->drag($split, $area, from: 10, to: 10), $area),
            'A divider under the pointer looks different from one at rest',
        );
    }

    #[Test]
    public function aSplitRebuiltFromItsStateCarriesOnAnUnfinishedDrag(): void
    {
        $area = Rect::fromSize(21, 10);
        $split = Split::row(SplitState::even(2), Text::new('left'), Text::new('right'));

        $pressed = $split->update(new MouseMsg(10, 0, MouseAction::Press, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Split::class, $pressed);

        // What an application does between two messages: keep the state, throw
        // the widget away and build it again on the next view.
        $rebuilt = Split::row($pressed->state(), Text::new('left'), Text::new('right'));
        $moved = $rebuilt->update(new MouseMsg(14, 0, MouseAction::Motion, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Split::class, $moved);

        self::assertSame([14, 6], $this->paneWidths($moved, $area), 'The drag survives the widget it started on');
    }

    #[Test]
    public function neverDrawsOutsideAnAreaTooSmallForItsMinimums(): void
    {
        $split = Split::row(SplitState::even(3), Text::new('a'), Text::new('b'), Text::new('c'))
            ->withMinimums(40, 40, 40);

        for ($width = 0; $width <= 130; ++$width) {
            $widths = $this->paneWidths($split, Rect::fromSize($width, 4));
            $dividers = min(2, max(0, $width));

            self::assertSame(
                max(0, $width - $dividers),
                array_sum($widths),
                "Minimums of 120 asked for, $width columns given",
            );

            foreach ($widths as $pane) {
                self::assertGreaterThanOrEqual(0, $pane, 'No pane is drawn backwards');
            }
        }
    }

    /**
     * @return list<int>
     */
    /**
     * A press is a fresh grip, so a drag whose release went to a layer above must not
     * go on resizing on the next unrelated drag.
     */
    #[Test]
    public function aPressEndsADragWhoseReleaseNeverArrived(): void
    {
        $area = new Rect(0, 0, 40, 10);
        $split = Split::row(SplitState::even(2), Text::new('left'), Text::new('right'));
        $grabbed = $split->update(new MouseMsg(20, 5, MouseAction::Press, MouseButton::Left), $area)->model;
        self::assertNotSame($split, $grabbed, 'precondition: the divider was grabbed');

        $regripped = $grabbed->update(new MouseMsg(4, 5, MouseAction::Press, MouseButton::Left), $area)->model;
        $dragged = $regripped->update(new MouseMsg(8, 5, MouseAction::Motion, MouseButton::Left), $area)->model;

        self::assertSame(SplitState::even(2)->shares, $dragged->state()->shares);
    }

    /**
     * Squeezed, the panes give ground from whatever sits above its floor, so one
     * already at its floor keeps it.
     */
    #[Test]
    public function thePaneWithRoomGivesGroundAndTheOneAtItsFloorKeepsIt(): void
    {
        $split = Split::row(new SplitState([100, 900]), Text::new('left'), Text::new('right'))
            ->withMinimums(10, 4);

        self::assertSame([10, 29], $this->paneWidths($split, new Rect(0, 0, 40, 6)), 'roomy at 40 columns');

        $squeezed = $this->paneWidths($split, new Rect(0, 0, 20, 6));
        self::assertSame([10, 9], $squeezed, 'the right pane gave up its slack');
        self::assertSame(20, array_sum($squeezed) + 1, 'and every column is handed out, gap included');
    }

    /**
     * Equal shares of an odd number of columns cannot come out even, and whatever is
     * left over has to land somewhere or a column of the terminal stays blank.
     */
    #[Test]
    public function theColumnThatWillNotDivideGoesToTheLastPane(): void
    {
        $split = Split::row(SplitState::even(3), Text::new('a'), Text::new('b'), Text::new('c'));

        $widths = $this->paneWidths($split, new Rect(0, 0, 12, 6));

        self::assertSame([3, 3, 4], $widths, 'ten columns among three panes leaves one over');
        self::assertSame(12, array_sum($widths) + 2, 'and with the two gaps, nothing is left unpainted');
    }

    /**
     * @param list<int> $shares
     *
     * @return list<int>
     */
    private function paneWidthsForShares(array $shares): array
    {
        $split = Split::row(new SplitState($shares), Text::new('a'), Text::new('b'), Text::new('c'));

        return $this->paneWidths($split, Rect::fromSize(8, 4));
    }

    private function dividerUnder(Split $split, Rect $area, int $column): ?int
    {
        return $split->dividerAt(new MouseMsg($column, $area->y, MouseAction::Press, MouseButton::Left), $area);
    }

    private function dividerColour(Split $split, Rect $area): int
    {
        $frame = new Frame($area->width, $area->height);
        $split->render($frame, $area, new RenderContext(Theme::dark()));

        $column = 0;
        foreach ($split->childWidgets($area) as [, $pane]) {
            $column = $pane->x + $pane->width;
            break;
        }

        return $frame->foregrounds[$column];
    }

    private function drag(Split $split, Rect $area, int $from, int $to): Split
    {
        $pressed = $split->update(new MouseMsg($from, $area->y, MouseAction::Press, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Split::class, $pressed);

        $moved = $pressed->update(new MouseMsg($to, $area->y, MouseAction::Motion, MouseButton::Left), $area)->model;
        self::assertInstanceOf(Split::class, $moved);

        return $moved;
    }

    private function paneWidths(Split $split, Rect $area): array
    {
        $widths = [];

        foreach ($split->childWidgets($area) as [, $pane]) {
            $widths[] = $pane->width;
        }

        return $widths;
    }
}
