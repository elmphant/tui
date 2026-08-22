<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget\Tree;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
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
    public function theArrowKeysDriveTheTreeOnlyWhileItHoldsTheKeyboard(): void
    {
        $area = new Rect(0, 0, 30, 10);
        $tree = self::orderTree()->withFocus(true);

        self::assertSame(['total'], self::afterKey($tree, Key::Down, $area)->cursor()->keys);

        $opened = self::afterKey($tree, Key::Right, $area);
        self::assertTrue($opened->isExpanded(TreePath::root()->child('order')), 'right opens what the cursor is on');
        self::assertFalse($opened->isExpanded(TreePath::root()->child('order')->child('lines')));

        $unfocused = self::afterKey(self::orderTree()->withFocus(false), Key::Down, $area);
        self::assertSame(['order'], $unfocused->cursor()->keys, 'a tree without the keyboard ignores the key');
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
        $tree = TreeView::new($roots)->withFocus(true);

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
        $tree = self::orderTree()->withFocus(true);
        $frame = new Frame(30, 4);

        $tree->render($frame, new Rect(0, 0, 30, 4), new RenderContext($theme));

        $highlight = $theme->cursorStyle->backgroundValue();
        self::assertSame($highlight, $frame->backgrounds[0], 'the cursor row starts highlighted');
        self::assertSame($highlight, $frame->backgrounds[29], 'and stays highlighted to the far edge');
        self::assertNotSame($highlight, $frame->backgrounds[30], 'the row below it is not');
    }

    #[Test]
    public function aClickOnTheLabelTogglesWhileAClickOnTheDetailOnlySelects(): void
    {
        $area = new Rect(0, 0, 30, 6);
        $order = TreePath::root()->child('order');
        $tree = self::orderTree()->withFocus(true)->cursorDown();

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
