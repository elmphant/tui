<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget\Tree;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Tree\SimpleTreeNode;
use Elmphant\Tui\Widget\Tree\TreeGuides;
use Elmphant\Tui\Widget\Tree\TreePath;
use Elmphant\Tui\Widget\Tree\TreeView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TreeViewTest extends TestCase
{
    #[Test]
    public function rightOnALeafLeavesItAndTheCursorAlone(): void
    {
        $tree = TreeView::new([new SimpleTreeNode('a', 'a'), new SimpleTreeNode('b', 'b')]);

        $once = $tree->expandOrEnter();
        $twice = $once->expandOrEnter();

        self::assertFalse($once->isExpanded(TreePath::root()->child('a')));
        self::assertEquals(TreePath::root()->child('a'), $twice->cursor());
        self::assertFalse($tree->toggle(TreePath::root()->child('a'))->isExpanded(TreePath::root()->child('a')));
    }

    #[Test]
    public function aNarrowTreeDrawsNothingPastItsRightEdge(): void
    {
        $deep = new SimpleTreeNode('e', 'leaf-label');
        foreach (['d', 'c', 'b', 'a'] as $key) {
            $deep = new SimpleTreeNode($key, "node-{$key}", '', [$deep]);
        }
        $root = TreePath::root();
        $tree = TreeView::new([new SimpleTreeNode('long', 'a-rather-long-label-here', 'detail'), $deep])
            ->expand($root->child('a'))
            ->expand($root->child('a')->child('b'))
            ->expand($root->child('a')->child('b')->child('c'))
            ->expand($root->child('a')->child('b')->child('c')->child('d'));
        $frame = new Frame(30, 7);

        $tree->render($frame, new Rect(0, 0, 10, 7), RenderContext::unfocused(Theme::dark()));

        $screen = ScreenBuffer::fromFrame($frame);
        $outside = array_map(static fn (int $row): string => trim($screen->textAt($row, 10, 20)), range(0, 6));
        self::assertSame(array_fill(0, 7, ''), $outside);
    }

    #[Test]
    public function itNamesTheKeysItAnswers(): void
    {
        self::assertSame(
            ['↑/↓' => 'move', 'Enter' => 'open or close'],
            TreeView::new([])->hints(),
        );
    }

    #[Test]
    public function aCollapsedTreeDrawsOnlyItsRoots(): void
    {
        $tree = TreeView::new([
            new SimpleTreeNode('order', 'order', 'Order(2)', [
                new SimpleTreeNode('lines', 'lines', 'array(3)'),
                new SimpleTreeNode('customer', 'customer', '"Ada"'),
            ]),
            new SimpleTreeNode('total', 'total', '94.2'),
        ]);

        $lines = self::draw($tree, 30, 4);

        self::assertSame('▸ order            Order(2)', $lines[0], 'a node with children offers a twisty');
        self::assertSame('▪ total            94.2', $lines[1], 'a leaf gets no twisty to press');
        self::assertSame('', $lines[2], 'a collapsed root keeps its children off the screen');
    }

    #[Test]
    public function anExpandedNodeDrawsItsChildrenUnderBranchGlyphs(): void
    {
        $tree = TreeView::new([
            new SimpleTreeNode('order', 'order', 'Order(2)', [
                new SimpleTreeNode('lines', 'lines', 'array(3)', [new SimpleTreeNode('0', '0', '1')]),
                new SimpleTreeNode('customer', 'customer', '"Ada"'),
            ]),
            new SimpleTreeNode('total', 'total', '94.2'),
        ])->expand(TreePath::root()->child('order'));

        $lines = self::draw($tree, 30, 5);

        self::assertSame('▾ order            Order(2)', $lines[0], 'the twisty turns down');
        self::assertSame('├─▸ lines          array(3)', $lines[1]);
        self::assertSame('└─▪ customer       "Ada"', $lines[2], 'the last child closes the branch');
        self::assertSame('▪ total            94.2', $lines[3], 'and the next root starts again at the margin');
    }

    #[Test]
    public function aGrandchildCarriesTheBarOfEveryAncestorStillHavingSiblings(): void
    {
        $tree = TreeView::new([
            new SimpleTreeNode('order', 'order', 'Order(2)', [
                new SimpleTreeNode('lines', 'lines', 'array(2)', [
                    new SimpleTreeNode('0', '0', '1'),
                    new SimpleTreeNode('1', '1', '2'),
                ]),
                new SimpleTreeNode('customer', 'customer', '"Ada"'),
            ]),
        ])
            ->expand(TreePath::root()->child('order'))
            ->expand(TreePath::root()->child('order')->child('lines'));

        $lines = self::draw($tree, 30, 6);

        self::assertSame('├─▾ lines          array(2)', $lines[1]);
        self::assertSame('│ ├─▪ 0            1', $lines[2], 'lines still has a sibling below, so its bar continues');
        self::assertSame('│ └─▪ 1            2', $lines[3]);
        self::assertSame('└─▪ customer       "Ada"', $lines[4], 'and the bar stops where that sibling arrives');
    }

    #[Test]
    public function theCursorStepsOverWhatIsVisibleAndSkipsWhatIsCollapsed(): void
    {
        $tree = self::orderTree();

        self::assertSame(['order'], $tree->cursor()->keys, 'the first row holds the cursor until told otherwise');
        self::assertSame(['total'], $tree->cursorDown()->cursor()->keys, 'a collapsed node hides its children from the cursor too');

        $expanded = $tree->expand(TreePath::root()->child('order'));

        self::assertSame(['order', 'lines'], $expanded->cursorDown()->cursor()->keys);
        self::assertSame(['order'], $expanded->cursorDown()->cursorUp()->cursor()->keys);
    }

    #[Test]
    public function collapseOrLeaveClosesTheNodeAndThenClimbsToItsParent(): void
    {
        $order = TreePath::root()->child('order');
        $lines = $order->child('lines');
        $tree = self::orderTree()->expand($order)->expand($lines)->withCursor($lines);

        $closed = $tree->collapseOrLeave();

        self::assertFalse($closed->isExpanded($lines), 'the first press closes what the cursor is on');
        self::assertSame(['order', 'lines'], $closed->cursor()->keys, 'and leaves the cursor where it was');

        $climbed = $closed->collapseOrLeave();

        self::assertSame(['order'], $climbed->cursor()->keys, 'a second press has nothing to close, so it climbs');
    }

    #[Test]
    public function collapseOrLeaveOnARootHasNowhereToClimb(): void
    {
        $tree = self::orderTree()->withCursor(TreePath::root()->child('total'));

        self::assertSame(['total'], $tree->collapseOrLeave()->cursor()->keys);
    }

    #[Test]
    public function expandOrEnterOpensTheNodeAndThenStepsIntoIt(): void
    {
        $order = TreePath::root()->child('order');
        $opened = self::orderTree()->withCursor($order)->expandOrEnter();

        self::assertTrue($opened->isExpanded($order));
        self::assertSame(['order'], $opened->cursor()->keys, 'opening alone does not move the cursor');
        self::assertSame(['order', 'lines'], $opened->expandOrEnter()->cursor()->keys, 'a second press enters the first child');
    }

    #[Test]
    public function theArrowKeysMoveTheCursorAndOpenWhatItSitsOn(): void
    {
        $area = new Rect(0, 0, 30, 10);
        $tree = self::orderTree();

        self::assertSame(['total'], self::afterKey($tree, Key::Down, $area)->cursor()->keys);

        $opened = self::afterKey($tree, Key::Right, $area);
        self::assertTrue($opened->isExpanded(TreePath::root()->child('order')), 'right opens what the cursor is on');
        self::assertFalse($opened->isExpanded(TreePath::root()->child('order')->child('lines')));
    }

    #[Test]
    public function aNodeWhoseChildrenAreNotLoadedYetShowsThatItIsWaiting(): void
    {
        $tree = TreeView::new([
            new SimpleTreeNode('lazy', 'lazy', 'array(3)', null),
            new SimpleTreeNode('total', 'total', '94.2'),
        ])->expand(TreePath::root()->child('lazy'));

        $lines = self::draw($tree, 30, 4);

        self::assertSame('▾ lazy             array(3)', $lines[0]);
        self::assertSame('└─⋯', $lines[1], 'a glyph rather than a word, so the framework carries no language');
        self::assertSame('▪ total            94.2', $lines[2], 'and the rest of the tree keeps drawing');
    }

    #[Test]
    public function theCursorStepsPastAWaitingRowRatherThanIntoIt(): void
    {
        $tree = TreeView::new([
            new SimpleTreeNode('lazy', 'lazy', 'array(3)', null),
            new SimpleTreeNode('total', 'total', '94.2'),
        ])->expand(TreePath::root()->child('lazy'));

        self::assertSame(['total'], $tree->cursorDown()->cursor()->keys, 'there is nothing there to select yet');
    }

    #[Test]
    public function theTreeNamesTheNodesWhoseChildrenItIsStillMissing(): void
    {
        $lazy = TreePath::root()->child('lazy');
        $tree = TreeView::new([
            new SimpleTreeNode('lazy', 'lazy', 'array(3)', null),
            new SimpleTreeNode('loaded', 'loaded', 'array(1)', [new SimpleTreeNode('0', '0', '1')]),
        ]);

        self::assertSame([], $tree->pendingExpansions(), 'a collapsed node is not waiting for anything');

        $expanded = $tree->expand($lazy)->expand(TreePath::root()->child('loaded'));

        self::assertSame([$lazy->keys], array_map(
            static fn (TreePath $path): array => $path->keys,
            $expanded->pendingExpansions(),
        ), 'only the one whose children are unknown');
    }

    #[Test]
    public function answeringWithLoadedChildrenKeepsTheCursorAndWhatWasOpen(): void
    {
        $lazy = TreePath::root()->child('lazy');
        $tree = TreeView::new([
            new SimpleTreeNode('lazy', 'lazy', 'array(1)', null),
            new SimpleTreeNode('total', 'total', '94.2'),
        ])->expand($lazy)->withCursor(TreePath::root()->child('total'));

        $answered = $tree->withRoots([
            new SimpleTreeNode('lazy', 'lazy', 'array(1)', [new SimpleTreeNode('0', '0', '7')]),
            new SimpleTreeNode('total', 'total', '94.2'),
        ]);

        self::assertSame([], $answered->pendingExpansions(), 'the wait is over');
        self::assertTrue($answered->isExpanded($lazy), 'and it is still open');
        self::assertSame(['total'], $answered->cursor()->keys, 'with the cursor where the user left it');
        self::assertSame('└─▪ 0              7', self::draw($answered, 30, 4)[1]);
    }

    #[Test]
    public function aTreeTallerThanItsPaneScrollsToKeepTheCursorInView(): void
    {
        $roots = [];
        foreach (range(1, 8) as $index) {
            $roots[] = new SimpleTreeNode((string)$index, 'row' . $index, (string)$index);
        }
        $area = new Rect(0, 0, 30, 3);
        $tree = TreeView::new($roots);

        self::assertSame('▪ row1             1', self::draw($tree, 30, 3)[0], 'it starts at the top');

        $moved = $tree;
        foreach (range(1, 5) as $ignored) {
            $moved = self::afterKey($moved, Key::Down, $area);
        }

        $lines = self::drawIn($moved, $area);
        self::assertSame(['6'], $moved->cursor()->keys);
        self::assertSame('▪ row4             4', $lines[0], 'the view followed the cursor down');
        self::assertSame('▪ row6             6', $lines[2], 'and the cursor is the last row shown');
    }

    #[Test]
    public function theCursorRowIsHighlightedRightAcrossThePane(): void
    {
        $theme = Theme::dark();
        $tree = self::orderTree();
        $frame = new Frame(30, 4);

        $tree->render($frame, new Rect(0, 0, 30, 4), RenderContext::focused($theme));

        $highlight = $theme->cursorStyle->backgroundValue();
        self::assertSame($highlight, $frame->backgrounds[0], 'the cursor row starts highlighted');
        self::assertSame($highlight, $frame->backgrounds[29], 'and stays highlighted to the far edge');
        self::assertNotSame($highlight, $frame->backgrounds[30], 'the row below it is not');
    }

    #[Test]
    public function aDeeplyNestedRowShowsItsLabelAtFullWidthAndDropsItsDetail(): void
    {
        $node = new SimpleTreeNode('deep', 'deep', 'value');
        foreach (range(1, 10) as $level) {
            $node = new SimpleTreeNode('level' . $level, 'level' . $level, '', [$node]);
        }
        $path = TreePath::root();
        $tree = TreeView::new([$node, new SimpleTreeNode('wide', 'wide', str_repeat('x', 21))]);
        foreach (range(10, 1) as $level) {
            $path = $path->child('level' . $level);
            $tree = $tree->expand($path);
        }

        $lines = self::draw($tree, 40, 12);

        self::assertSame('deep', rtrim(mb_substr($lines[10], 22)), 'the guides reach past the detail column, so the label takes the rest of the row');
    }

    #[Test]
    public function aLongLabelTakesTheRoomItHasAndMarksWhereItIsCut(): void
    {
        $tree = TreeView::new([
            new SimpleTreeNode('long', 'a-directory-name-of-thirty-chars', expandable: true),
            new SimpleTreeNode('file', 'invalid-utf8-file.txt', '42'),
        ]);

        self::assertSame(
            ['▸ a-directory-name-of-thirty-chars', '▪ invalid-utf8-file.txt 42'],
            array_map('rtrim', self::draw($tree, 60, 2)),
        );
        self::assertSame(
            ['▸ a-directory-name-of-th…', '▪ invalid-utf8-file.t… 42'],
            array_map('rtrim', self::draw($tree, 25, 2)),
        );
        self::assertSame(
            ['▸ a-directory-name-o…', '▪ invalid-utf8-fi… 42'],
            array_map('rtrim', self::draw($tree, 21, 2)),
        );
    }

    #[Test]
    public function aRowWithoutADetailColumnOpensWhereverItIsClicked(): void
    {
        $node = new SimpleTreeNode('deep', 'deep', 'value');
        foreach (range(1, 10) as $level) {
            $node = new SimpleTreeNode('level' . $level, 'level' . $level, '', [$node]);
        }
        $path = TreePath::root();
        $tree = TreeView::new([$node]);
        foreach (range(10, 1) as $level) {
            $path = $path->child('level' . $level);
            $tree = $tree->expand($path);
        }

        $area = new Rect(0, 0, 40, 12);
        $level1 = TreePath::root()->child('level10')->child('level9')->child('level8')
            ->child('level7')->child('level6')->child('level5')->child('level4')
            ->child('level3')->child('level2')->child('level1');

        $clicked = self::afterMouse($tree, 25, 9, $area);

        self::assertSame(['level10', 'level9', 'level8', 'level7', 'level6', 'level5', 'level4', 'level3', 'level2', 'level1'], $clicked->cursor()->keys);
        self::assertFalse($clicked->isExpanded($level1), 'with no value drawn beside it, the whole row is the name');
    }

    #[Test]
    public function aClickOnTheLabelTogglesWhileAClickOnTheDetailOnlySelects(): void
    {
        $area = new Rect(0, 0, 30, 6);
        $order = TreePath::root()->child('order');
        $tree = self::orderTree()->cursorDown();

        $onLabel = self::afterMouse($tree, 3, 0, $area);
        self::assertSame(['order'], $onLabel->cursor()->keys, 'the click moved the cursor');
        self::assertTrue($onLabel->isExpanded($order), 'and pressing the name opened it');

        $onDetail = self::afterMouse($tree, 22, 0, $area);
        self::assertSame(['order'], $onDetail->cursor()->keys);
        self::assertFalse($onDetail->isExpanded($order), 'the value column is for selecting, not opening');
    }

    #[Test]
    public function theTreeMeasuresTheRowsItCurrentlyShows(): void
    {
        $order = TreePath::root()->child('order');
        $tree = self::orderTree();

        self::assertSame(2, $tree->measureHeight(30), 'two roots while everything is closed');
        self::assertSame(4, $tree->expand($order)->measureHeight(30), 'and its two children once one opens');
    }

    #[Test]
    public function roundedGuidesCloseTheLastBranchWithACurve(): void
    {
        $tree = self::orderTree()
            ->withGuides(TreeGuides::Rounded)
            ->expand(TreePath::root()->child('order'));

        $lines = self::draw($tree, 30, 5);

        self::assertSame('├─▸ lines          array(1)', $lines[1], 'a branch with more below it is unchanged');
        self::assertSame('╰─▪ customer       "Ada"', $lines[2]);
    }

    #[Test]
    public function twoNodesCannotShareAPathJustBecauseOfWhatIsInTheirKeys(): void
    {
        // A key may hold any byte, so none of them may end a segment.
        $tree = TreeView::new([
            new SimpleTreeNode('a', 'a', '', [new SimpleTreeNode('b', 'b', 'child')]),
            new SimpleTreeNode("a\0b", 'sibling', 'root'),
        ])->expand(TreePath::root()->child('a')->child('b'));

        self::assertFalse(
            $tree->isExpanded(TreePath::root()->child("a\0b")),
            'the sibling was never opened',
        );
    }

    #[Test]
    public function rootsThatShrinkTheTreeBelowTheScrollAreStillDrawn(): void
    {
        $area = new Rect(0, 0, 30, 5);
        $roots = array_map(static fn (int $index): SimpleTreeNode => new SimpleTreeNode('n' . $index, 'node ' . $index), range(0, 29));
        $scrolled = TreeView::new($roots)->update(new MouseMsg(1, 1, MouseAction::WheelDown, MouseButton::Left, 20), $area)->model;
        self::assertInstanceOf(TreeView::class, $scrolled);

        $shrunk = $scrolled->withRoots([new SimpleTreeNode('a', 'alpha'), new SimpleTreeNode('b', 'beta')]);

        self::assertSame(['▪ alpha', '▪ beta'], array_map('trim', array_slice(self::drawIn($shrunk, $area), 0, 2)));
    }

    #[Test]
    public function upLeftEnterHomeAndEndAreBoundByDefault(): void
    {
        $area = new Rect(0, 0, 30, 10);
        $order = TreePath::root()->child('order');
        $tree = self::orderTree();

        $opened = self::afterKey($tree, Key::Enter, $area);
        $atEnd = self::afterKey($opened, Key::End, $area);
        $up = self::afterKey($atEnd, Key::Up, $area);
        $atHome = self::afterKey($atEnd, Key::Home, $area);
        $closed = self::afterKey($atHome, Key::Left, $area);

        self::assertTrue($opened->isExpanded($order));
        self::assertSame(['total'], $atEnd->cursor()->keys);
        self::assertSame(['order', 'customer'], $up->cursor()->keys);
        self::assertSame(['order'], $atHome->cursor()->keys);
        self::assertFalse($closed->isExpanded($order));
    }

    #[Test]
    public function theWheelScrollsTheRowsWithoutMovingTheCursor(): void
    {
        $area = new Rect(0, 0, 30, 3);
        $roots = array_map(static fn (int $index): SimpleTreeNode => new SimpleTreeNode('n' . $index, 'node ' . $index), range(0, 9));
        $tree = TreeView::new($roots);

        $down = $tree->update(new MouseMsg(1, 1, MouseAction::WheelDown, MouseButton::None, 2), $area)->model;
        self::assertInstanceOf(TreeView::class, $down);
        $back = $down->update(new MouseMsg(1, 1, MouseAction::WheelUp, MouseButton::None), $area)->model;
        self::assertInstanceOf(TreeView::class, $back);

        self::assertSame('▪ node 2', trim(self::drawIn($down, $area)[0]));
        self::assertSame('▪ node 1', trim(self::drawIn($back, $area)[0]));
        self::assertSame(['n0'], $down->cursor()->keys);
    }

    #[Test]
    public function aTreeDrawnAwayFromTheOriginLooksTheSameShifted(): void
    {
        $tree = self::orderTree()->expand(TreePath::root()->child('order'))->cursorDown();
        $frame = new Frame(40, 6);

        $tree->render($frame, new Rect(5, 1, 30, 4), new RenderContext(Theme::dark()));

        $shifted = array_map(
            static fn (int $row): string => rtrim(ScreenBuffer::fromFrame($frame)->textAt($row, 5, 30)),
            range(1, 4),
        );
        self::assertSame(array_slice(self::draw($tree, 30, 4), 0, 4), $shifted);
    }

    #[Test]
    public function aClickAwayFromTheOriginLandsOnTheRowAndColumnUnderIt(): void
    {
        $area = new Rect(5, 2, 30, 6);
        $order = TreePath::root()->child('order');
        $tree = self::orderTree()->cursorDown();

        $onLabel = self::afterMouse($tree, 8, 2, $area);
        $onDetail = self::afterMouse($tree, 5 + 22, 2, $area);
        $onTotal = self::afterMouse($tree, 8, 3, $area);

        self::assertTrue($onLabel->isExpanded($order));
        self::assertSame(['order'], $onDetail->cursor()->keys);
        self::assertFalse($onDetail->isExpanded($order));
        self::assertSame(['total'], $onTotal->cursor()->keys);
    }

    #[Test]
    public function aClickOnTheFirstCellOfTheDetailColumnOnlySelects(): void
    {
        $tree = self::orderTree()->cursorDown();

        $clicked = self::afterMouse($tree, 19, 0, new Rect(0, 0, 30, 6));

        self::assertSame(['order'], $clicked->cursor()->keys);
        self::assertFalse($clicked->isExpanded(TreePath::root()->child('order')));
    }

    #[Test]
    public function aClickOnAWaitingRowChangesNothing(): void
    {
        $tree = TreeView::new([
            new SimpleTreeNode('lazy', 'lazy', 'array(3)', null),
            new SimpleTreeNode('total', 'total', '94.2'),
        ])->expand(TreePath::root()->child('lazy'))->withCursor(TreePath::root()->child('total'));

        $clicked = self::afterMouse($tree, 1, 1, new Rect(0, 0, 30, 4));

        self::assertSame(['total'], $clicked->cursor()->keys);
        self::assertTrue($clicked->isExpanded(TreePath::root()->child('lazy')));
    }

    #[Test]
    public function guidesAndDetailsAreDimOffTheCursorRow(): void
    {
        $theme = Theme::dark();
        $tree = self::orderTree()->expand(TreePath::root()->child('order'));
        $frame = new Frame(30, 4);

        $tree->render($frame, new Rect(0, 0, 30, 4), RenderContext::unfocused($theme));

        $dim = $theme->dim->foregroundValue();
        self::assertSame($dim, $frame->foregrounds[30], 'the branch of an unselected row');
        self::assertSame($dim, $frame->foregrounds[30 + 19], 'and its detail');
        self::assertNotSame($dim, $frame->foregrounds[19], 'while the cursor row draws its detail in the cursor style');
    }

    #[Test]
    public function everyOpenNodeStillWaitingIsNamed(): void
    {
        $tree = TreeView::new([
            new SimpleTreeNode('a', 'a', '', null),
            new SimpleTreeNode('b', 'b', '', null),
        ])->expand(TreePath::root()->child('a'))->expand(TreePath::root()->child('b'));

        self::assertSame([['a'], ['b']], array_map(
            static fn (TreePath $path): array => $path->keys,
            $tree->pendingExpansions(),
        ));
    }

    #[Test]
    public function aGreatGrandchildAndAWaitingRowCarryEveryAncestorsBar(): void
    {
        $root = TreePath::root();
        $tree = TreeView::new([
            new SimpleTreeNode('a', 'a', '', [
                new SimpleTreeNode('b', 'b', '', [
                    new SimpleTreeNode('c', 'c', '', [new SimpleTreeNode('d', 'd')]),
                    new SimpleTreeNode('lazy', 'lazy', '', null),
                    new SimpleTreeNode('f', 'f', '', [new SimpleTreeNode('g', 'g')]),
                ]),
                new SimpleTreeNode('e', 'e'),
            ]),
        ])
            ->expand($root->child('a'))
            ->expand($root->child('a')->child('b'))
            ->expand($root->child('a')->child('b')->child('c'))
            ->expand($root->child('a')->child('b')->child('lazy'))
            ->expand($root->child('a')->child('b')->child('f'));

        $lines = array_map('rtrim', self::draw($tree, 30, 9));

        self::assertSame('│ ├─▾ c', $lines[2]);
        self::assertSame('│ │ └─▪ d', $lines[3]);
        self::assertSame('│ ├─▾ lazy', $lines[4]);
        self::assertSame('│ │ └─⋯', $lines[5]);
        self::assertSame('│ └─▾ f', $lines[6]);
        self::assertSame('│   └─▪ g', $lines[7]);
    }

    #[Test]
    public function nodesWithTheSameKeyUnderDifferentParentsOpenSeparately(): void
    {
        $tree = TreeView::new([
            new SimpleTreeNode('a', 'a', '', [new SimpleTreeNode('x', 'x', '', [new SimpleTreeNode('0', '0')])]),
            new SimpleTreeNode('b', 'b', '', [new SimpleTreeNode('x', 'x', '', [new SimpleTreeNode('0', '0')])]),
            new SimpleTreeNode('a:x', 'a:x', '', [new SimpleTreeNode('0', '0')]),
        ])->expand(TreePath::root()->child('a')->child('x'));

        self::assertFalse($tree->isExpanded(TreePath::root()->child('b')->child('x')));
        self::assertFalse($tree->isExpanded(TreePath::root()->child('a:x')));
    }

    #[Test]
    public function aNodeFlaggedExpandableWithNoChildrenOpensToNothing(): void
    {
        $empty = TreePath::root()->child('empty');
        $tree = TreeView::new([new SimpleTreeNode('total', 'total'), new SimpleTreeNode('empty', 'empty', '', [], expandable: true)])
            ->withCursor($empty);

        $entered = $tree->expandOrEnter()->expandOrEnter();

        self::assertSame('▸ empty', rtrim(self::draw($tree, 30, 3)[1]));
        self::assertTrue($entered->isExpanded($empty));
        self::assertSame(['empty'], $entered->cursor()->keys);
    }

    private static function afterMouse(TreeView $tree, int $x, int $y, Rect $area): TreeView
    {
        $result = $tree->update(new MouseMsg($x, $y, MouseAction::Press, MouseButton::Left), $area);
        $model = $result->model;
        self::assertInstanceOf(TreeView::class, $model);

        return $model;
    }

    /**
     * @return list<string>
     */
    private static function drawIn(TreeView $tree, Rect $area): array
    {
        $frame = new Frame($area->width, $area->height);
        $tree->render($frame, $area, new RenderContext(Theme::dark()));

        return explode("\n", ScreenBuffer::fromFrame($frame)->plainText());
    }

    private static function afterKey(TreeView $tree, Key|string $key, Rect $area): TreeView
    {
        $result = $tree->update(new KeyMsg($key), $area);
        $model = $result->model;
        self::assertInstanceOf(TreeView::class, $model);

        return $model;
    }

    private static function orderTree(): TreeView
    {
        return TreeView::new([
            new SimpleTreeNode('order', 'order', 'Order(2)', [
                new SimpleTreeNode('lines', 'lines', 'array(1)', [new SimpleTreeNode('0', '0', '1')]),
                new SimpleTreeNode('customer', 'customer', '"Ada"'),
            ]),
            new SimpleTreeNode('total', 'total', '94.2'),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function draw(TreeView $tree, int $width, int $height): array
    {
        $frame = new Frame($width, $height);
        $tree->render($frame, new Rect(0, 0, $width, $height), new RenderContext(Theme::dark()));

        return explode("\n", ScreenBuffer::fromFrame($frame)->plainText());
    }
}
