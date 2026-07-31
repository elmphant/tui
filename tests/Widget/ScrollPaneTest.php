<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\CursorAware;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\ScrollPane;
use Elmphant\Tui\Widget\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScrollPaneTest extends TestCase
{
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
    public function focusingAChildBlursTheOthers(): void
    {
        $pane = ScrollPane::of($this->focusable('a'), Text::new('plain'), $this->focusable('b'));

        $rows = $this->rows($pane->withFocusAt(1), 20, 4);

        self::assertSame('a', $rows[0], 'The first focusable lost focus');
        self::assertSame('plain', $rows[1], 'A child that cannot focus is skipped when counting');
        self::assertSame('b*', $rows[2], 'The second focusable has it');
    }

    #[Test]
    public function focusStartsOnTheFirstChildThatCanTakeIt(): void
    {
        $pane = ScrollPane::of(Text::new('plain'), $this->focusable('a'), $this->focusable('b'));

        $rows = $this->rows($pane->withFocus(true), 20, 4);

        self::assertSame('a*', $rows[1]);
        self::assertSame('b', $rows[2]);
    }

    #[Test]
    public function reversedFocusStartsOnTheLastChildThatCanTakeIt(): void
    {
        $pane = ScrollPane::of($this->focusable('a'), $this->focusable('b'));

        $rows = $this->rows($pane->withFocusReversed(), 20, 4);

        self::assertSame('a', $rows[0]);
        self::assertSame('b*', $rows[1]);
    }

    #[Test]
    public function clickingAChildGivesItFocus(): void
    {
        $pane = ScrollPane::of($this->focusable('a'), $this->focusable('b'))->withFocus(true);

        $clicked = $this->clickAt($pane, 1, Rect::fromSize(20, 4));

        self::assertSame(['a', 'b*'], array_slice($this->rows($clicked, 20, 4), 0, 2));
    }

    #[Test]
    public function aClickThatHitsNothingLeavesThePaneAsItWas(): void
    {
        $pane = ScrollPane::of($this->focusable('a'), $this->focusable('b'))->withFocus(true);

        $clicked = $this->clickAt($pane, 3, Rect::fromSize(20, 4));

        self::assertSame($pane, $clicked, 'An untouched widget is how this framework says "not mine"');
    }

    #[Test]
    public function aClickFindsTheChildUnderItEvenWhenScrolled(): void
    {
        $pane = ScrollPane::of($this->focusable('a'), $this->focusable('b'), $this->focusable('c'))
            ->withFocus(true)
            ->withScrollOffset(1);

        $clicked = $this->clickAt($pane, 0, Rect::fromSize(20, 2));

        self::assertStringStartsWith('b*', $this->rows($clicked, 20, 2)[0], 'Row 0 shows b once scrolled, so b takes focus');
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
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), Text::new('three'))->withFocus(true);
        $area = Rect::fromSize(20, 2);

        $down = $this->press($pane, Key::Down, $area);

        self::assertStringStartsWith('two', $this->rows($down, 20, 2)[0]);
        self::assertSame($this->rows($pane, 20, 2), $this->rows($this->press($down, Key::Up, $area), 20, 2));
    }

    #[Test]
    public function keysAreIgnoredWhileThePaneIsNotFocused(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), Text::new('three'));

        self::assertSame($pane, $this->press($pane, Key::Down, Rect::fromSize(20, 2)));
    }

    #[Test]
    public function theFocusedChildSeesTheMessageFirst(): void
    {
        $pane = ScrollPane::of($this->counter('a'), $this->counter('b'))->withFocus(true)->withFocusAt(1);

        $pressed = $this->press($pane, Key::Enter, Rect::fromSize(20, 4));

        self::assertSame(['a 0', 'b 1*'], array_slice($this->rows($pressed, 20, 4), 0, 2));
    }

    #[Test]
    public function aChildThatIgnoresTheMessageLeavesThePaneAsItWas(): void
    {
        $pane = ScrollPane::of($this->counter('a'))->withFocus(true);

        self::assertSame($pane, $this->press($pane, Key::F1, Rect::fromSize(20, 4)));
    }

    #[Test]
    public function aCommandFromTheFocusedChildIsPassedOn(): void
    {
        $pane = ScrollPane::of($this->counter('a'))->withFocus(true);

        $result = $pane->update(new KeyMsg(Key::Enter), Rect::fromSize(20, 4));

        self::assertNotNull($result->command, 'The child asked for a command, so the pane must not swallow it');
    }

    #[Test]
    public function scrollingFollowsTheCursorOfTheFocusedChild(): void
    {
        $pane = ScrollPane::of(Text::new('one'), Text::new('two'), $this->counter('c'))
            ->withFocus(true);

        $rows = $this->rows($this->press($pane, Key::Enter, Rect::fromSize(20, 2)), 20, 2);

        self::assertStringStartsWith('c 1*', $rows[1], 'The pane scrolled so the cursor row is on screen');
    }

    #[Test]
    public function tabMovesFocusToTheNextChild(): void
    {
        $pane = ScrollPane::of($this->focusable('a'), $this->focusable('b'))->withFocus(true);

        $tabbed = $this->press($pane, Key::Tab, Rect::fromSize(20, 4));

        self::assertSame(['a', 'b*'], array_slice($this->rows($tabbed, 20, 4), 0, 2));
    }

    #[Test]
    public function tabPastTheLastChildLeavesThePaneAsItWas(): void
    {
        $pane = ScrollPane::of($this->focusable('a'), $this->focusable('b'))->withFocusReversed();

        $tabbed = $this->press($pane, Key::Tab, Rect::fromSize(20, 4));

        self::assertSame($pane, $tabbed, 'Focus leaves the pane, so the parent decides where it goes');
    }

    #[Test]
    public function shiftTabMovesFocusBack(): void
    {
        $pane = ScrollPane::of($this->focusable('a'), $this->focusable('b'))->withFocusReversed();

        $result = $pane->update(new KeyMsg(Key::Tab, shift: true), Rect::fromSize(20, 4));
        self::assertInstanceOf(ScrollPane::class, $result->model);

        self::assertSame(['a*', 'b'], array_slice($this->rows($result->model, 20, 4), 0, 2));
    }

    #[Test]
    public function aMessageThatIsNotInputReachesEveryChild(): void
    {
        $pane = ScrollPane::of($this->counter('a'), $this->counter('b'))->withFocus(true);

        $result = $pane->update(new TickMsg(0.0), Rect::fromSize(20, 4));
        self::assertInstanceOf(ScrollPane::class, $result->model);

        self::assertSame(
            ['a 1*', 'b 1'],
            array_slice($this->rows($result->model, 20, 4), 0, 2),
            'A tick is not input: it goes to all of them, not only the focused one',
        );
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
    public function aFollowedChildTallerThanTheViewportShowsItsTop(): void
    {
        $pane = ScrollPane::of(Text::new('one'), $this->fillsItsArea(4))
            ->withFollowChild(1);

        self::assertStringStartsWith('XX', $this->rows($pane, 20, 2)[0], 'Its first row, not its last');
    }

    #[Test]
    public function aCursorAboveTheViewportScrollsBackUp(): void
    {
        $pane = ScrollPane::of($this->counter('a'), Text::new('two'), Text::new('three'))
            ->withFocus(true)
            ->withScrollOffset(2);

        $pressed = $this->press($pane, Key::Enter, Rect::fromSize(20, 1));

        self::assertStringStartsWith('a 1*', $this->rows($pressed, 20, 1)[0], 'The cursor was above the viewport');
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

        self::assertSame(0, $pane->scrollOffset());
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
        $pane = ScrollPane::of($first, $second)->withScrollOffset(1);

        $placed = [...$pane->childWidgets(Rect::fromSize(20, 2))];

        self::assertSame([$first, $second], [...$pane->children()]);
        self::assertSame($first, $placed[0][0]);
        self::assertSame(-1, $placed[0][1]->y, 'The scrolled-away child reports where it actually is');
        self::assertSame(0, $placed[1][1]->y);
    }

    #[Test]
    public function scrollingDoesNotReMeasureAnUnchangedTree(): void
    {
        $tally = new MeasureTally();
        $pane = ScrollPane::of(new TallyingWidget(30, $tally))->withFocus(true);
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
        $pane = self::numbered(12)->withFocus(true);

        $paged = $pane->update(new KeyMsg(Key::Down, shift: true), Rect::fromSize(20, 4))->model;

        self::assertStringStartsWith('line 5', $this->rows($paged, 20, 4)[0], 'A page is the viewport');
    }

    #[Test]
    public function shiftUpPagesBackAndStopsAtTheTop(): void
    {
        $area = Rect::fromSize(20, 4);
        $pane = self::numbered(12)->withFocus(true)->withScrollOffset(5);

        $back = $pane->update(new KeyMsg(Key::Up, shift: true), $area)->model;
        $further = $back->update(new KeyMsg(Key::Up, shift: true), $area)->model;

        self::assertStringStartsWith('line 2', $this->rows($back, 20, 4)[0]);
        self::assertStringStartsWith('line 1', $this->rows($further, 20, 4)[0], 'It stops at the first line');
    }

    private function throwingChild(): Widget
    {
        return new class implements Widget {
            public function constraint(): Constraint
            {
                return Constraint::exact(1);
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
                private readonly bool $focused = false,
            ) {
            }

            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function isFocused(): bool
            {
                return $this->focused;
            }

            public function withFocus(bool $focused): static
            {
                return new static($this->label, $this->count, $focused);
            }

            public function cursorRow(): int
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
                    new static($this->label, $this->count + 1, $this->focused),
                    $enter ? Cmd::quit() : null,
                );
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putString($area->x, $area->y, sprintf('%s %d%s', $this->label, $this->count, $this->focused ? '*' : ''));
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
            public function __construct(
                private readonly string $label,
                private readonly bool $focused = false,
            ) {
            }

            public function constraint(): Constraint
            {
                return Constraint::exact(1);
            }

            public function isFocused(): bool
            {
                return $this->focused;
            }

            public function withFocus(bool $focused): static
            {
                return new static($this->label, $focused);
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putString($area->x, $area->y, $this->label . ($this->focused ? '*' : ''));
            }
        };
    }

    private function fillsItsArea(int $height): Widget
    {
        return new class($height) implements Widget {
            public function __construct(private readonly int $height)
            {
            }

            public function constraint(): Constraint
            {
                return Constraint::exact($this->height);
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
    private function rows(ScrollPane $pane, int $width, int $height): array
    {
        $frame = new Frame($width, $height);
        $pane->render($frame, Rect::fromSize($width, $height), new RenderContext(Theme::dark()));

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

final readonly class TallyingWidget implements Widget, \Elmphant\Tui\Widget\MeasureHeight
{
    public function __construct(
        private int $height,
        private MeasureTally $tally,
    ) {
    }

    public function constraint(): Constraint
    {
        return Constraint::min(1);
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
