<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\CursorAware;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focus\Navigation;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\ScrollPane;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScrollPaneTest extends TestCase
{
    #[Test]
    public function itNamesTheKeysItAnswers(): void
    {
        self::assertSame(['↑/↓' => 'scroll'], ScrollPane::of()->hints());
    }

    /**
     * Bound children keep their own focus stops; the surrounding pane must not add another.
     */
    #[Test]
    public function aPaneAroundABoundFieldIsNotAStopItself(): void
    {
        $pane = ScrollPane::of(Component::bind(
            'email',
            TextInput::new(),
            static fn (App $app, TextInput $field): App => $app,
        ));

        self::assertSame(Navigation::Never, $pane->navigation());
    }

    #[Test]
    public function theGeometryHandsBackTheChildrenAsTheyWereAuthored(): void
    {
        $pane = ScrollPane::of(TextInput::new(), TextInput::new());

        $placed = [];
        foreach ($pane->placedChildren(Rect::fromSize(20, 6)) as [$child]) {
            $placed[] = $child;
        }

        self::assertSame(
            $pane->children(),
            $placed,
            'a mark from the pane’s own flag would hold rects for widgets the tree may never draw',
        );
    }

    #[Test]
    public function measuresTheRowsItsChildrenNeed(): void
    {
        $pane = ScrollPane::of(Text::new('first'), Text::new('second'), Text::new('third'));

        self::assertSame(3, $pane->measureHeight(20), 'Three one-row children need three rows');
    }

    #[Test]
    public function drawsItsChildrenOneUnderTheOther(): void
    {
        $rows = $this->rows(ScrollPane::of(Text::new('first'), Text::new('second')), 20, 4);

        self::assertSame('first', $rows[0]);
        self::assertSame('second', $rows[1]);
    }

    #[Test]
    public function scrollingMovesTheChildrenUp(): void
    {
        $pane = ScrollPane::of(Text::new('first'), Text::new('second'), Text::new('third'));

        $rows = $this->rows($pane->withScrollOffset(1), 20, 2);

        self::assertStringStartsWith('second', $rows[0], 'The first child scrolled out of the top');
        self::assertStringStartsWith('third', $rows[1]);
    }

    #[Test]
    public function endShowsTheLastChildAndHomeTheFirst(): void
    {
        $pane = ScrollPane::of(...array_map(static fn (int $index): Text => Text::new("line{$index}"), range(0, 9)));
        $area = Rect::fromSize(20, 3);

        $atTheEnd = $pane->update(new KeyMsg(Key::End), $area)->model;
        $atTheTop = $atTheEnd->update(new KeyMsg(Key::Home), $area)->model;

        self::assertStringContainsString('line9', implode("\n", $this->rows($atTheEnd, 20, 3)));
        self::assertStringStartsWith('line0', $this->rows($atTheTop, 20, 3)[0]);
    }

    #[Test]
    public function aFollowedSpanTallerThanTheViewportShowsItsLastChild(): void
    {
        $pane = ScrollPane::of(...array_map(static fn (int $index): Text => Text::new("line{$index}"), range(0, 9)))
            ->withFollowChild(5, 4);

        self::assertSame(['line6', 'line7', 'line8'], array_map(static fn (string $row): string => trim(mb_substr($row, 0, 10)), $this->rows($pane, 20, 3)));
    }

    #[Test]
    public function nothingIsDrawnAboveTheViewport(): void
    {
        $pane = ScrollPane::of(Text::new('first'), Text::new('second'), Text::new('third'));

        $screen = implode("\n", $this->rows($pane->withScrollOffset(2), 20, 2));

        self::assertStringNotContainsString('first', $screen);
        self::assertStringNotContainsString('second', $screen);
    }

    #[Test]
    public function aChildTallerThanTheViewportIsClipped(): void
    {
        $frame = new Frame(10, 5);
        $pane = ScrollPane::of($this->fillsItsArea(5));

        $pane->render($frame, new Rect(0, 0, 10, 2), new RenderContext(Theme::dark()));

        self::assertStringStartsWith('XXXXXXXXX', $this->rowOf($frame, 0, 10));
        self::assertStringNotContainsString(
            'XXXXXXXXXX',
            $this->rowOf($frame, 0, 10),
            'The child stops before the scrollbar column',
        );
        self::assertSame('', $this->rowOf($frame, 2, 10), 'The child stopped at the bottom of the pane');
    }

    #[Test]
    public function contentTallerThanTheViewportGetsAScrollbar(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), Text::new('three'));

        $rows = $this->rows($pane, 10, 2);

        self::assertSame(10, mb_strlen($rows[0]), 'The last column carries a scrollbar, so the row is not blank there');
    }

    #[Test]
    public function contentThatFitsGetsNoScrollbar(): void
    {
        $rows = $this->rows(ScrollPane::of(Text::new('one')), 10, 4);

        self::assertSame('one', $rows[0], 'Nothing is drawn in the last column');
    }

    #[Test]
    public function theChildTheKeyboardNamesIsTheOnlyOneDrawnFocused(): void
    {
        $pane = ScrollPane::of($this->focusable('a'), Text::new('plain'), $this->focusable('b'));

        $rows = $this->rowsWithKeyboardOn($pane, 2, 20, 4);

        self::assertSame(['a', 'plain', 'b*'], array_slice($rows, 0, 3), 'The id counts every child, so 2 is the second focusable');
    }

    /**
     * Focus on a tab strip must not light a child that receives none of its keys.
     */
    #[Test]
    public function aPaneHoldingTheKeyboardItselfLightsNoChild(): void
    {
        $pane = ScrollPane::of(Text::new('plain'), $this->focusable('a'), $this->focusable('b'));

        $rows = $this->rows($pane, 20, 4, focused: true);

        self::assertSame(['plain', 'a', 'b'], array_slice($rows, 0, 3));
    }

    #[Test]
    public function aClickThatHitsNothingLeavesThePaneAsItWas(): void
    {
        $pane = ScrollPane::of($this->focusable('a'), $this->focusable('b'));

        $clicked = $this->clickAt($pane, 3, Rect::fromSize(20, 4));

        self::assertSame($pane, $clicked, 'An untouched widget is how this framework says "not mine"');
    }

    #[Test]
    public function theWheelScrollsTheContent(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), Text::new('three'));
        $area = Rect::fromSize(20, 2);

        $down = $this->wheel($pane, MouseAction::WheelDown, $area);
        $backUp = $this->wheel($down, MouseAction::WheelUp, $area);

        self::assertStringStartsWith('two', $this->rows($down, 20, 2)[0]);
        self::assertSame($this->rows($pane, 20, 2), $this->rows($backUp, 20, 2), 'And back again');
    }

    #[Test]
    public function theWheelStopsAtTheEnds(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), Text::new('three'));
        $area = Rect::fromSize(20, 2);

        self::assertSame($pane, $this->wheel($pane, MouseAction::WheelUp, $area), 'Already at the top');
    }

    #[Test]
    public function contentThatFitsDoesNotScroll(): void
    {
        $pane = ScrollPane::of(Text::new('one'));
        $area = Rect::fromSize(20, 4);

        self::assertSame($pane, $this->wheel($pane, MouseAction::WheelDown, $area));
    }

    #[Test]
    public function contentThatExactlyFillsTheViewportDoesNotScroll(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'));
        $area = Rect::fromSize(20, 2);

        self::assertSame($pane, $this->wheel($pane, MouseAction::WheelDown, $area), 'There is nothing below to reveal');
    }

    #[Test]
    public function theWheelOutsideThePaneIsNotItsBusiness(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), Text::new('three'));

        $result = $pane->update(new MouseMsg(50, 50, MouseAction::WheelDown, MouseButton::None), Rect::fromSize(20, 2));

        self::assertSame($pane, $result->model);
    }

    #[Test]
    public function scrollKeysMoveTheContent(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), Text::new('three'));
        $area = Rect::fromSize(20, 2);

        $down = $this->press($pane, Key::Down, $area);

        self::assertStringStartsWith('two', $this->rows($down, 20, 2)[0]);
        self::assertSame($this->rows($pane, 20, 2), $this->rows($this->press($down, Key::Up, $area), 20, 2));
    }

    #[Test]
    public function aKeyTheScrollBindingsDoNotNameLeavesThePaneAsItWas(): void
    {
        $pane = ScrollPane::of($this->counter('a'));

        self::assertSame($pane, $this->press($pane, Key::F1, Rect::fromSize(20, 4)));
    }

    #[Test]
    public function graftingTheKeyboardChildBackKeepsItInView(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), $this->counter('c'))->withKeyboardAt(2);

        $grafted = $pane->withChildAt(2, $this->counter('c'));

        self::assertStringStartsWith('c 0', $this->rows($grafted, 20, 2)[1]);
    }

    #[Test]
    public function tabIsLeftToWhoeverWalksTheRing(): void
    {
        $pane = ScrollPane::of($this->focusable('a'), $this->focusable('b'));

        $tabbed = $this->press($pane, Key::Tab, Rect::fromSize(20, 4));

        self::assertSame($pane, $tabbed, 'Each child is a stop of its own, so the pane has no cycle to run');
    }

    #[Test]
    public function aTickIsNotThePanesToHandOut(): void
    {
        $pane = ScrollPane::of($this->counter('a'), $this->counter('b'));

        $result = $pane->update(new TickMsg(0.0), Rect::fromSize(20, 4));

        self::assertSame($pane, $result->model, 'Each child is bound in its own right, so the tick reaches it there');
    }

    #[Test]
    public function aFollowedChildIsScrolledIntoView(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), Text::new('three'))
            ->withFollowChild(2);

        self::assertStringStartsWith('three', $this->rows($pane, 20, 1)[0]);
    }

    #[Test]
    public function aFollowedChildAlreadyOnScreenMovesNothing(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), Text::new('three'))
            ->withFollowChild(0);

        self::assertStringStartsWith('one', $this->rows($pane, 20, 2)[0]);
    }

    #[Test]
    public function aFollowedChildWithACursorIsShownAtThatRow(): void
    {
        $pane = ScrollPane::of($this->cursorList(8, 5))->withFollowChild(0);

        self::assertStringStartsWith(
            'row5',
            $this->rows($pane, 20, 4)[3],
            'A child taller than the viewport is followed at its cursor, not at its top',
        );
    }

    #[Test]
    public function followingSeveralChildrenKeepsTheirTopVisible(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), Text::new('three'), Text::new('four'))
            ->withFollowChild(1, 2);

        self::assertStringStartsWith('two', $this->rows($pane, 20, 2)[0], 'Both followed rows fit, so the first is on top');
    }

    #[Test]
    public function aFollowedChildAboveTheViewportScrollsBackUpToIt(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), Text::new('three'), Text::new('four'))
            ->withScrollOffset(3)
            ->withFollowChild(1);

        self::assertStringStartsWith('two', $this->rows($pane, 20, 1)[0], 'It scrolled back up, not down');
    }

    #[Test]
    public function scrollingCarriesOnFromWhereFollowingAChildLeftTheView(): void
    {
        $pane = ScrollPane::of(
            Text::new('one'),
            Text::new('two'),
            Text::new('three'),
            Text::new('four'),
            Text::new('five'),
        )
            ->withFollowChild(4);

        self::assertStringStartsWith('five', $this->rows($pane, 20, 1)[0], 'following put the last child on screen');

        $scrolled = $this->press($pane, Key::Up, Rect::fromSize(20, 1));

        self::assertStringStartsWith(
            'four',
            $this->rows($scrolled, 20, 1)[0],
            'one row up from what the reader is looking at, not from an offset left behind',
        );
    }

    #[Test]
    public function aFollowedChildTallerThanTheViewportShowsItsTop(): void
    {
        $pane = ScrollPane::of(Text::new('one'), $this->fillsItsArea(4))
            ->withFollowChild(1);

        self::assertStringStartsWith('XX', $this->rows($pane, 20, 2)[0], 'Its first row, not its last');
    }

    #[Test]
    public function theKeyboardChildAboveTheViewportIsScrolledBackUpTo(): void
    {
        $pane = ScrollPane::of($this->counter('a'), Text::new('two'), Text::new('three'))
            ->withScrollOffset(2)
            ->withKeyboardAt(0);

        self::assertStringStartsWith('a 0', $this->rows($pane, 20, 1)[0]);
    }

    #[Test]
    public function scrollingByHandStopsFollowing(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), Text::new('three'))
            ->withFollowChild(2)
            ->withScrollOffset(0);

        self::assertStringStartsWith('one', $this->rows($pane, 20, 1)[0], 'The user took over');
    }

    #[Test]
    public function aNegativeScrollOffsetIsRefused(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'))->withScrollOffset(-5);

        self::assertSame(0, $pane->scrollOffset(Rect::fromSize(20, 2)));
        self::assertStringStartsWith('one', $this->rows($pane, 20, 2)[0], 'Nothing is pushed below the top edge');
    }

    #[Test]
    public function aFollowSpanBelowOneStillFollowsOneChild(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), Text::new('three'))
            ->withFollowChild(2, 0);

        self::assertStringStartsWith('three', $this->rows($pane, 20, 1)[0]);
    }

    #[Test]
    public function anAreaWithNoWidthDrawsNothing(): void
    {
        $frame = new Frame(10, 3);

        ScrollPane::of(Text::new('one'))->render($frame, new Rect(0, 0, 0, 3), new RenderContext(Theme::dark()));

        self::assertSame('', $this->rowOf($frame, 0, 10));
    }

    #[Test]
    public function aChildThatThrowsLeavesTheClipStackAsItFoundIt(): void
    {
        $frame = new Frame(10, 3);
        // Enough children to need a scrollbar, so the clip is narrower than the
        // area and a leftover entry is visible.
        $pane = ScrollPane::of($this->throwingChild(), Text::new('b'), Text::new('c'), Text::new('d'));
        $before = $frame->clip();

        try {
            $pane->render($frame, new Rect(0, 0, 10, 3), new RenderContext(Theme::dark()));
            self::fail('The child was supposed to throw');
        } catch (RuntimeException) {
        }

        self::assertEquals($before, $frame->clip(), 'A half-drawn pane must not leave its clip behind');
    }

    #[Test]
    public function reportsItsChildrenAndWhereTheyLand(): void
    {
        $first = Text::new('one');
        $second = Text::new('two');
        $third = Text::new('three');
        $pane = ScrollPane::of($first, $second, $third)->withScrollOffset(1);

        $placed = [...$pane->placedChildren(Rect::fromSize(20, 2))];

        self::assertSame([$first, $second, $third], [...$pane->children()]);
        self::assertSame($first, $placed[0][0]);
        self::assertSame(-1, $placed[0][1]->y, 'The scrolled-away child reports where it actually is');
        self::assertSame(0, $placed[1][1]->y);
        self::assertSame(1, $placed[2][1]->y);
    }

    #[Test]
    public function scrollingDoesNotReMeasureAnUnchangedTree(): void
    {
        $tally = new MeasureTally();
        $pane = ScrollPane::of(new TallyingWidget(30, $tally));
        $area = new Rect(0, 0, 40, 10);
        $wheel = new MouseMsg(2, 2, MouseAction::WheelDown, MouseButton::None);

        $pane->render(new Frame(40, 10), $area, new RenderContext(Theme::dark()));
        $tally->measurements = 0;

        for ($tick = 0; $tick < 20; ++$tick) {
            $scrolled = $pane->update($wheel, $area)->model;
            self::assertInstanceOf(ScrollPane::class, $scrolled);
            $pane = $scrolled;
            $pane->render(new Frame(40, 10), $area, new RenderContext(Theme::dark()));
        }

        self::assertSame(0, $tally->measurements, 'Only the offset changed, so the heights already known must be reused');
    }

    #[Test]
    public function replacingAChildDoesReMeasure(): void
    {
        $tally = new MeasureTally();
        $pane = ScrollPane::of(new TallyingWidget(30, $tally));
        $area = new Rect(0, 0, 40, 10);

        $pane->render(new Frame(40, 10), $area, new RenderContext(Theme::dark()));
        $tally->measurements = 0;

        $pane->add(new TallyingWidget(5, $tally))
            ->render(new Frame(40, 10), $area, new RenderContext(Theme::dark()));

        self::assertGreaterThan(0, $tally->measurements, 'New content cannot reuse the old heights');
    }

    #[Test]
    public function shiftDownScrollsAWholePage(): void
    {
        $pane = self::numbered(12);

        $paged = $pane->update(new KeyMsg(Key::Down, shift: true), Rect::fromSize(20, 4))->model;

        self::assertStringStartsWith('line 5', $this->rows($paged, 20, 4)[0], 'A page is the viewport');
    }

    #[Test]
    public function shiftUpPagesBackAndStopsAtTheTop(): void
    {
        $area = Rect::fromSize(20, 4);
        $pane = self::numbered(12)->withScrollOffset(5);

        $back = $pane->update(new KeyMsg(Key::Up, shift: true), $area)->model;
        $further = $back->update(new KeyMsg(Key::Up, shift: true), $area)->model;

        self::assertStringStartsWith('line 2', $this->rows($back, 20, 4)[0]);
        self::assertStringStartsWith('line 1', $this->rows($further, 20, 4)[0], 'It stops at the first line');
    }

    private function throwingChild(): Widget
    {
        return new class implements Widget {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                throw new RuntimeException('boom');
            }
        };
    }

    private function counter(string $label): Widget
    {
        return new class($label) implements Focusable, CursorAware {
            public function __construct(
                private readonly string $label,
                private readonly int $count = 0,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function cursorLine(int $width): int
            {
                return 0;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                $enter = $message instanceof KeyMsg && $message->key === Key::Enter;

                if (!$enter && !$message instanceof TickMsg) {
                    return new UpdateResult($this);
                }

                return new UpdateResult(
                    new static($this->label, $this->count + 1),
                    $enter ? Cmd::quit() : null,
                );
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putString($area->x, $area->y, sprintf('%s %d%s', $this->label, $this->count, $context->hasFocus() ? '*' : ''));
            }
        };
    }

    private function cursorList(int $rows, int $cursorLine): Widget
    {
        return new class($rows, $cursorLine) implements Widget, CursorAware {
            public function __construct(
                private readonly int $rows,
                private readonly int $cursorLine,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact($this->rows), Constraint::exact($this->rows));
            }

            public function cursorLine(int $width): int
            {
                return $this->cursorLine;
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                for ($row = 0; $row < $this->rows; ++$row) {
                    $frame->putString($area->x, $area->y + $row, 'row' . $row);
                }
            }
        };
    }

    private function press(ScrollPane $pane, Key $key, Rect $area): ScrollPane
    {
        $result = $pane->update(new KeyMsg($key), $area);
        self::assertInstanceOf(ScrollPane::class, $result->model);

        return $result->model;
    }

    private function clickAt(ScrollPane $pane, int $y, Rect $area): ScrollPane
    {
        $result = $pane->update(new MouseMsg(0, $y, MouseAction::Press, MouseButton::Left), $area);
        self::assertInstanceOf(ScrollPane::class, $result->model);

        return $result->model;
    }

    private function wheel(ScrollPane $pane, MouseAction $action, Rect $area): ScrollPane
    {
        $result = $pane->update(new MouseMsg(0, 0, $action, MouseButton::None), $area);
        self::assertInstanceOf(ScrollPane::class, $result->model);

        return $result->model;
    }

    private function focusable(string $label): Widget
    {
        return new class($label) implements Focusable {
            public function __construct(private readonly string $label)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putString($area->x, $area->y, $this->label . ($context->hasFocus() ? '*' : ''));
            }
        };
    }

    private function fillsItsArea(int $height): Widget
    {
        return new class($height) implements Widget {
            public function __construct(private readonly int $height)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact($this->height), Constraint::exact($this->height));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->fillRect($area->x, $area->y, $area->width, $area->height, 0, 0, 0, 'X');
            }
        };
    }

    private static function numbered(int $count): ScrollPane
    {
        $lines = [];
        for ($line = 1; $line <= $count; ++$line) {
            $lines[] = Text::new('line ' . $line);
        }

        return ScrollPane::of(...$lines);
    }

    private function rowOf(Frame $frame, int $row, int $width): string
    {
        return rtrim(implode('', array_slice($frame->characters, $row * $width, $width)));
    }

    /**
     * @return list<string>
     */
    private function rows(ScrollPane $pane, int $width, int $height, bool $focused = false): array
    {
        $frame = new Frame($width, $height);
        $context = $focused ? RenderContext::focused(Theme::dark()) : new RenderContext(Theme::dark());
        $pane->render($frame, Rect::fromSize($width, $height), $context);

        return self::rowsOf($frame, $width, $height);
    }

    /**
     * Render with a published child ID as the runtime does, so the pane can resolve child focus.
     *
     * @return list<string>
     */
    private function rowsWithKeyboardOn(ScrollPane $pane, int $child, int $width, int $height): array
    {
        $frame = new Frame($width, $height);
        $pass = RenderPass::new(Theme::dark());
        $pass->focus->focusOn(FocusState::on(ComponentId::named('pane/' . $child)));
        $context = $pass->context;
        $context->withinComponent(
            ComponentId::named('pane'),
            static fn () => $pane->render($frame, Rect::fromSize($width, $height), $context),
        );

        return self::rowsOf($frame, $width, $height);
    }

    /**
     * @return list<string>
     */
    private static function rowsOf(Frame $frame, int $width, int $height): array
    {
        $rows = [];
        for ($row = 0; $row < $height; ++$row) {
            $rows[] = rtrim(implode('', array_slice($frame->characters, $row * $width, $width)));
        }

        return $rows;
    }
}

final class MeasureTally
{
    public int $measurements = 0;
}

final readonly class TallyingWidget implements Widget, MeasureHeight
{
    public function __construct(
        private int $height,
        private MeasureTally $tally,
    ) {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::min(1), Constraint::content()->withMin(1));
    }

    public function measureHeight(int $width): int
    {
        ++$this->tally->measurements;

        return $this->height;
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
