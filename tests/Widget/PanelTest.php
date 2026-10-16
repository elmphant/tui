<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\LayoutPlan;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\BorderStyle;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use Elmphant\Tui\Tests\Support\RootPass;
use Elmphant\Tui\Widget\Bar\Scrollbar;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\CursorAware;
use Elmphant\Tui\Widget\Feedback\ProgressBar;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Separator;
use Elmphant\Tui\Widget\Prose\Spacer;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PanelTest extends TestCase
{
    use PerformanceBudget;

    #[Test]
    public function itNamesTheKeysItsPaneAnswers(): void
    {
        self::assertSame(['↑/↓' => 'scroll'], (new Panel())->hints());
    }

    #[Test]
    public function measuresItsChildrenPlusTheChromeAroundThem(): void
    {
        self::assertSame(5, $this->panelOfLines(3)->measureHeight(20), 'Three rows of content plus the border above and below');
    }

    #[Test]
    public function aChildDrawingPastItsAreaIsCroppedByThePanelBorder(): void
    {
        $frame = new Frame(40, 10);
        $panel = Panel::new('Held')->withChildren([self::widgetDrawingPastItsArea()]);

        $panel->render($frame, new Rect(0, 0, 12, 6), new RenderContext(Theme::dark()));

        $screen = ScreenBuffer::fromFrame($frame);

        self::assertStringContainsString('X', $screen->lineAt(1), 'Precondition: the child did draw inside the panel');
        self::assertSame('', trim($screen->textAt(1, 12, 28)), 'nothing of it reached past the border');
        self::assertStringContainsString('╰', $screen->lineAt(5), 'and the border ring is intact');
    }

    #[Test]
    public function aPanelTooSmallForItsBorderShowsItsContentWithoutOne(): void
    {
        $frame = new Frame(10, 2);
        $panel = Panel::new('Held')->withPadding(Spacing::horizontal(1))->add(Text::new('hi'));

        $panel->render($frame, new Rect(0, 0, 2, 2), new RenderContext(Theme::dark()));

        self::assertSame('hi', ScreenBuffer::fromFrame($frame)->textAt(0, 0, 2));
    }

    #[Test]
    public function theTitleCanBeReplacedAfterConstruction(): void
    {
        $area = new Rect(0, 0, 24, 5);

        self::assertStringContainsString('first', $this->screenOf(Panel::new('first'), $area));

        $renamed = Panel::new('first')->withTitle('second');
        self::assertStringContainsString('second', $this->screenOf($renamed, $area));
        self::assertStringNotContainsString('first', $this->screenOf($renamed, $area));
    }

    #[Test]
    public function anOverriddenBorderStyleWinsOverTheTheme(): void
    {
        $area = new Rect(0, 0, 24, 5);
        $accent = Theme::dark()->accent->foregroundValue();

        $plain = $this->borderColour(Panel::new('t'), $area);
        self::assertNotSame($accent, $plain, 'The theme border is not the accent to begin with');

        self::assertSame(
            $accent,
            $this->borderColour(Panel::new('t')->withBorderStyle(Theme::dark()->accent), $area),
            'An unfocused panel uses the plain override',
        );

        self::assertSame(
            $accent,
            $this->borderColour(Panel::new('t')->withFocusedBorderStyle(Theme::dark()->accent), $area, focused: true),
            'A focused panel uses the focused override',
        );
    }

    #[Test]
    public function anEmphasizedBorderFollowsTheThemeItIsRenderedWith(): void
    {
        $area = new Rect(0, 0, 24, 5);
        $panel = Panel::new('t')->withEmphasizedBorder();

        foreach ([Theme::dark(), Theme::light()] as $theme) {
            self::assertSame(
                $theme->focusedBorderStyle->foregroundValue(),
                $this->borderColour($panel, $area, $theme),
                'The theme decides the colour, so it can differ per theme',
            );
        }
    }

    #[Test]
    public function anOverriddenTitleStyleWinsOverTheTheme(): void
    {
        $area = new Rect(0, 0, 24, 5);
        $accent = Theme::dark()->accent->foregroundValue();

        $plain = $this->titleColour(Panel::new('Hi'), $area);
        $styled = $this->titleColour(Panel::new('Hi')->withTitleStyle(Theme::dark()->accent), $area);

        self::assertNotSame($plain, $styled);
        self::assertSame($accent, $styled);
    }

    #[Test]
    public function aPreResolvedLayoutPlanDrawsExactlyWhatDirectLayoutDraws(): void
    {
        $area = new Rect(0, 0, 24, 6);

        foreach ([0, 1, 7, 50] as $offset) {
            $panel = $this->panelOfLines(10)->withScrollOffset($offset);

            $planned = new Frame(24, 6);
            RootPass::draw(RenderPass::new(Theme::dark()), $panel, $planned, $area, LayoutPlan::resolve($panel, $area));

            $direct = new Frame(24, 6);
            $panel->render($direct, $area, new RenderContext(Theme::dark()));

            self::assertSame(
                $direct->characters,
                $planned->characters,
                "The two layout paths must agree at scroll offset {$offset}",
            );
        }
    }

    #[Test]
    public function replacingTheChildrenHandsThemBackAsTheyWereAuthored(): void
    {
        $children = [Select::new(['a']), Select::new(['b'])];

        $panel = Panel::new('t')->withChildren($children);

        self::assertSame($children, $panel->children(), 'A panel marks nothing: the tree decides where the keyboard is');
    }

    #[Test]
    public function handingBackTheSameChildrenChangesNothing(): void
    {
        $children = [Select::new(['a']), Select::new(['b'])];
        $panel = Panel::new('t')->withChildren($children);

        self::assertSame($panel, $panel->withChildren($children));
    }

    #[Test]
    public function aChildOneRowOutsideTheContentAreaIsNotDrawnAtAll(): void
    {
        $area = new Rect(0, 0, 24, 6);

        // The border takes rows 0 and 5, leaving rows 1 to 4 for content. At an
        // offset of one, line0 sits on the border row and line5 just past it.
        $screen = $this->screenOf($this->panelOfLines(10)->withScrollOffset(1), $area);

        self::assertStringContainsString('line1', $screen, 'The first row inside');
        self::assertStringContainsString('line4', $screen, 'The last row inside');
        self::assertStringNotContainsString('line0', $screen, 'One row above must not overwrite the border');
        self::assertStringNotContainsString('line5', $screen, 'One row below must not overwrite the border');
    }

    #[Test]
    public function anOffsetPastTheContentIsClampedToTheLastPageAndAPaddingRow(): void
    {
        $area = new Rect(0, 0, 24, 6);

        // Ten rows in four viewport rows leaves 10 - 4 + 1 = 7 as the last offset.
        self::assertSame(
            $this->screenOf($this->panelOfLines(10)->withScrollOffset(7), $area),
            $this->screenOf($this->panelOfLines(10)->withScrollOffset(50), $area),
            'An offset far past the end shows the same rows as the last legal one',
        );

        $clamped = $this->screenOf($this->panelOfLines(10)->withScrollOffset(50), $area);
        self::assertStringContainsString('line9', $clamped, 'The last row stays on screen');
        self::assertStringNotContainsString('line6', $clamped, 'And the page before it does not');
    }

    #[Test]
    public function theWheelStopsScrollingAtTheBottomOfTheContent(): void
    {
        $area = new Rect(0, 0, 24, 6);
        $panel = $this->panelOfLines(10);
        $wheel = new MouseMsg(2, 2, MouseAction::WheelDown, MouseButton::Left);

        for ($turn = 0; $turn < 20; ++$turn) {
            $result = $panel->update($wheel, $area);
            self::assertInstanceOf(Panel::class, $result->model);
            $panel = $result->model;
        }

        $atRest = $this->screenOf($panel, $area);
        self::assertStringContainsString('line9', $atRest, 'The last line is reachable');

        $further = $panel->update($wheel, $area);
        self::assertInstanceOf(Panel::class, $further->model);

        self::assertSame($atRest, $this->screenOf($further->model, $area), 'And the wheel goes no further');
    }

    #[Test]
    public function followingAChildBelowTheViewportScrollsItIntoView(): void
    {
        $area = new Rect(0, 0, 24, 6);
        $panel = $this->panelOfLines(10)->withFollowChild(7);

        $screen = $this->screenOf($panel, $area);

        self::assertStringContainsString('line7', $screen, 'The followed child is on screen');
        self::assertStringNotContainsString('line3', $screen, 'Scrolled past the earlier children');
    }

    #[Test]
    public function followingAChildAboveTheViewportScrollsBackToIt(): void
    {
        $area = new Rect(0, 0, 24, 6);
        $panel = $this->panelOfLines(10)->withScrollOffset(5)->withFollowChild(1);

        $screen = $this->screenOf($panel, $area);

        self::assertStringContainsString('line1', $screen, 'The followed child becomes the top row');
        self::assertStringNotContainsString('line6', $screen, 'The old viewport is left behind');
    }

    #[Test]
    public function aFollowedChildAlreadyInViewLeavesTheOffsetAlone(): void
    {
        $area = new Rect(0, 0, 24, 6);

        self::assertSame(
            $this->screenOf($this->panelOfLines(10), $area),
            $this->screenOf($this->panelOfLines(10)->withFollowChild(2), $area),
            'Nothing scrolls for a child that is already visible',
        );
    }

    #[Test]
    public function followingASpanTallerThanTheViewportShowsItsTop(): void
    {
        $area = new Rect(0, 0, 24, 6);
        $panel = $this->panelOfLines(10)->withFollowChild(6, 4);

        $screen = $this->screenOf($panel, $area);

        self::assertStringContainsString('line6', $screen, 'A span too tall to fit is shown from its first row');
        self::assertStringNotContainsString('line5', $screen);
    }

    #[Test]
    public function scrollsJustFarEnoughToRevealAChildCursorBelowTheViewport(): void
    {
        // A six-row area leaves four content rows once the border takes two.
        $area = new Rect(0, 0, 24, 6);
        $panel = Panel::new('t')->add($this->eightOptions());

        // The cursor reaches row 5, so the top row scrolls to 5 - 4 + 1 = 2:
        // exactly far enough, leaving opt2 through opt5 on screen.
        $screen = $this->screenOf($panel->withKeyboardAt(0)->withChildAt(0, $this->eightOptionsAtRow(5)), $area);

        self::assertStringNotContainsString('opt1', $screen, 'Scrolled past');
        self::assertStringContainsString('opt2', $screen, 'The new top row');
        self::assertStringContainsString('opt5', $screen, 'The cursor row');
    }

    #[Test]
    public function scrollsBackUpWhenAChildCursorRisesAboveTheViewport(): void
    {
        $area = new Rect(0, 0, 24, 6);
        $panel = Panel::new('t')->add($this->eightOptions())->withScrollOffset(4);

        $screen = $this->screenOf($panel->withKeyboardAt(0)->withChildAt(0, $this->eightOptionsAtRow(1)), $area);

        self::assertStringContainsString('opt1', $screen, 'The cursor row, now the top row');
        self::assertStringNotContainsString('opt5', $screen, 'The old viewport is left behind');
    }

    #[Test]
    public function constraintReturnsFlex(): void
    {
        $panel = Panel::new('Test');
        $constraint = $panel->sizing()->rows;

        self::assertSame(1, $constraint->flex);
    }

    #[Test]
    public function addAcceptsWidgetAndReturnsNewPanel(): void
    {
        $panel = Panel::new('Test');
        $panelWithChild = $panel->add(Text::new('Hello'));

        self::assertNotSame($panel, $panelWithChild);
        self::assertInstanceOf(Panel::class, $panelWithChild);
    }

    #[Test]
    public function implementsFocusable(): void
    {
        $panel = Panel::new('Test');

        self::assertInstanceOf(Focusable::class, $panel);
    }

    #[Test]
    public function rendersBorderCharacters(): void
    {
        $frame = new Frame(20, 10);
        $panel = Panel::new('');
        $area = new Rect(0, 0, 20, 10);

        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('╭', $frame->characters[0]);
        self::assertSame('╮', $frame->characters[19]);
        self::assertSame('╰', $frame->characters[9 * 20]);
        self::assertSame('╯', $frame->characters[9 * 20 + 19]);
        self::assertSame('─', $frame->characters[1]);
        self::assertSame('│', $frame->characters[1 * 20]);
    }

    #[Test]
    public function rendersTitle(): void
    {
        $frame = new Frame(20, 10);
        $panel = Panel::new('Settings');
        $area = new Rect(0, 0, 20, 10);

        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[1]);
        self::assertSame('S', $frame->characters[2]);
        self::assertSame('e', $frame->characters[3]);
        self::assertSame('t', $frame->characters[4]);
    }

    #[Test]
    public function skipsRenderingWhenAreaTooSmall(): void
    {
        $frame = new Frame(5, 5);
        $panel = Panel::new('Test');

        $panel->render($frame, new Rect(0, 0, 2, 2), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function rendersChildWidgets(): void
    {
        $frame = new Frame(20, 10);
        $panel = Panel::new('')
            ->add(Text::new('Hello'));

        $panel->render($frame, new Rect(0, 0, 20, 10), new RenderContext(Theme::dark()));

        self::assertSame('H', $frame->characters[1 * 20 + 1]);
        self::assertSame('e', $frame->characters[1 * 20 + 2]);
        self::assertSame('l', $frame->characters[1 * 20 + 3]);
    }

    #[Test]
    public function focusedBorderUsesAccentColor(): void
    {
        $frame = new Frame(20, 10);
        $panel = Panel::new('Title');
        $area = new Rect(0, 0, 20, 10);

        $panel->render($frame, $area, RenderContext::focused(Theme::dark()));

        $topLeftForeground = $frame->foregrounds[0];
        $unfocusedBuffer = new Frame(20, 10);
        Panel::new('Title')->render($unfocusedBuffer, $area, new RenderContext(Theme::dark()));
        $unfocusedTopLeftForeground = $unfocusedBuffer->foregrounds[0];

        self::assertNotSame($unfocusedTopLeftForeground, $topLeftForeground);
    }

    #[Test]
    public function aFocusedPanelUsesTheThemesFocusedBorder(): void
    {
        $area = new Rect(0, 0, 12, 4);
        $theme = Theme::dark()->withFocusedBorder(BorderStyle::Double);
        $panel = Panel::new('T');

        self::assertStringContainsString('╔', $this->screenWith($panel, $area, $theme, focused: true));
        self::assertStringNotContainsString('╔', $this->screenWith($panel, $area, $theme));
    }

    #[Test]
    public function scrollOffsetChangesOnArrowKeys(): void
    {
        $panel = Panel::new('Test')
            ->add(Text::new('Line 1'))
            ->add(Text::new('Line 2'))
            ->add(Text::new('Line 3'))
            ->add(Text::new('Line 4'))
            ->add(Text::new('Line 5'));

        $area = new Rect(0, 0, 20, 5);
        $downMessage = new KeyMsg(Key::Down);
        $result = $panel->update($downMessage, $area);

        self::assertInstanceOf(UpdateResult::class, $result);
        self::assertInstanceOf(Panel::class, $result->model);
    }

    #[Test]
    public function updateReturnsSelfWhenUnfocused(): void
    {
        $panel = Panel::new('Test');
        $area = new Rect(0, 0, 20, 10);
        $result = $panel->update(new KeyMsg('x'), $area);

        self::assertInstanceOf(UpdateResult::class, $result);
        self::assertSame($panel, $result->model);
    }

    #[Test]
    public function updateReturnsSelfForUnhandledKeys(): void
    {
        $panel = Panel::new('Test');

        $area = new Rect(0, 0, 20, 10);
        $result = $panel->update(new KeyMsg('z'), $area);

        self::assertInstanceOf(UpdateResult::class, $result);
        self::assertSame($panel, $result->model);
    }

    #[Test]
    public function multipleChildrenRenderInSequence(): void
    {
        $frame = new Frame(20, 10);
        $panel = Panel::new('')
            ->add(Text::new('First'))
            ->add(Text::new('Second'));

        $panel->render($frame, new Rect(0, 0, 20, 10), new RenderContext(Theme::dark()));

        self::assertSame('F', $frame->characters[1 * 20 + 1]);
        self::assertSame('S', $frame->characters[2 * 20 + 1]);
    }

    #[Test]
    public function addMixedWidgetTypes(): void
    {
        $panel = Panel::new('Mixed')
            ->add(Text::new('Heading'))
            ->add(Spacer::new())
            ->add(Separator::new())
            ->add(Text::new('Footer'));

        self::assertInstanceOf(Panel::class, $panel);
        self::assertSame(1, $panel->sizing()->rows->flex);
    }

    #[Test]
    public function tabAtLastFocusableChildReturnsSelf(): void
    {
        $focusableA = $this->createFocusableWidget();
        $focusableB = $this->createFocusableWidget();

        $panel = Panel::new('Test')
            ->add($focusableA)
            ->add($focusableB);

        // Tab forward to second (last) focusable child
        $area = new Rect(0, 0, 30, 20);
        $result = $panel->update(new KeyMsg(Key::Tab), $area);
        $panel = $result->model;

        // Tab again — at last child, should return same instance (bubble up)
        $result = $panel->update(new KeyMsg(Key::Tab), $area);

        self::assertSame($panel, $result->model);
    }

    #[Test]
    public function shiftTabAtFirstFocusableChildReturnsSelf(): void
    {
        $focusableA = $this->createFocusableWidget();
        $focusableB = $this->createFocusableWidget();

        $panel = Panel::new('Test')
            ->add($focusableA)
            ->add($focusableB);

        // Focus starts at first child. Shift+Tab should return same instance.
        $area = new Rect(0, 0, 30, 20);
        $result = $panel->update(new KeyMsg(Key::Tab, shift: true), $area);

        self::assertSame($panel, $result->model);
    }

    #[Test]
    public function reportsTheScrollOffsetItComputed(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }
        $area = new Rect(0, 0, 20, 5);

        self::assertSame(0, $panel->scrollOffset());

        $scrolled = $panel->update(
            new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None),
            $area,
        )->model;

        self::assertGreaterThan(0, $scrolled->scrollOffset());
    }

    #[Test]
    public function mouseWheelDownScrollsPanel(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }
        $area = new Rect(0, 0, 20, 5);

        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $result = $panel->update($wheelDown, $area);
        self::assertNotSame($panel, $result->model);
    }

    #[Test]
    public function mouseWheelUpScrollsPanel(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }
        $area = new Rect(0, 0, 20, 5);

        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $scrolled = $panel->update($wheelDown, $area)->model;

        $wheelUp = new MouseMsg(5, 2, MouseAction::WheelUp, MouseButton::None);
        $result = $scrolled->update($wheelUp, $area);
        self::assertNotSame($scrolled, $result->model);
    }

    #[Test]
    public function aTabKeyIsLeftForTheRingAbove(): void
    {
        $panel = Panel::new('Test')
            ->add(Text::new('Static'))
            ->add(Separator::new());

        $result = $panel->update(new KeyMsg(Key::Tab), new Rect(0, 0, 40, 10));

        self::assertSame($panel, $result->model, 'an unanswered Tab falls through to App, which moves the flat ring');
        self::assertNull($result->command);
    }

    #[Test]
    public function rendersScrollbarWhenContentOverflows(): void
    {
        $panel = Panel::new('');
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }

        $frame = new Frame(20, 10);
        $area = new Rect(0, 0, 20, 10);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        $scrollbarX = 18;
        $hasThumb = false;
        $hasTrack = false;

        for ($row = 1; $row < 9; ++$row) {
            $character = $frame->characters[$row * 20 + $scrollbarX];
            if ($character === Scrollbar::THUMB) {
                $hasThumb = true;
            }
            if ($character === Scrollbar::TRACK) {
                $hasTrack = true;
            }
        }

        self::assertTrue($hasThumb, 'Scrollbar thumb should appear in rightmost content column');
        self::assertTrue($hasTrack, 'Scrollbar track should appear in rightmost content column');
    }

    #[Test]
    public function noScrollbarWhenContentFits(): void
    {
        $panel = Panel::new('')
            ->add(Text::new('Line 1'))
            ->add(Text::new('Line 2'));

        $frame = new Frame(20, 10);
        $area = new Rect(0, 0, 20, 10);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        $scrollbarX = 18;

        for ($row = 1; $row < 9; ++$row) {
            $character = $frame->characters[$row * 20 + $scrollbarX];
            self::assertSame(' ', $character, "No scrollbar character should appear at row $row");
        }
    }

    #[Test]
    public function withPaddingReturnsNewPanel(): void
    {
        $panel = Panel::new('Test');
        $padded = $panel->withPadding(Spacing::all(1));

        self::assertNotSame($panel, $padded);
        self::assertInstanceOf(Panel::class, $padded);
    }

    #[Test]
    public function withMarginReturnsNewPanel(): void
    {
        $panel = Panel::new('Test');
        $margined = $panel->withMargin(Spacing::all(1));

        self::assertNotSame($panel, $margined);
        self::assertInstanceOf(Panel::class, $margined);
    }

    #[Test]
    public function marginShiftsBorderInward(): void
    {
        $frame = new Frame(22, 12);
        $panel = Panel::new('')
            ->withMargin(Spacing::all(1));
        $area = new Rect(0, 0, 22, 12);

        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[0], 'Top-left corner should be empty margin');
        self::assertSame('╭', $frame->characters[1 * 22 + 1], 'Border top-left should be at (1,1)');
        self::assertSame('╮', $frame->characters[1 * 22 + 20], 'Border top-right at (20,1)');
        self::assertSame('╰', $frame->characters[10 * 22 + 1], 'Border bottom-left at (1,10)');
        self::assertSame('╯', $frame->characters[10 * 22 + 20], 'Border bottom-right at (20,10)');
    }

    #[Test]
    public function marginSkipsRenderWhenNoSpaceForBorder(): void
    {
        $frame = new Frame(5, 5);
        $panel = Panel::new('')
            ->withMargin(Spacing::all(1));

        $panel->render($frame, new Rect(0, 0, 4, 4), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function paddingShiftsContentInward(): void
    {
        $frame = new Frame(20, 10);
        $panel = Panel::new('')
            ->withPadding(Spacing::all(1))
            ->add(Text::new('Hello'));

        $panel->render($frame, new Rect(0, 0, 20, 10), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[1 * 20 + 1], 'Padding area should be empty');
        self::assertSame('H', $frame->characters[2 * 20 + 2], 'Content should start at (2,2)');
    }

    #[Test]
    public function verticalPaddingOnlyAffectsTopAndBottom(): void
    {
        $frame = new Frame(20, 10);
        $panel = Panel::new('')
            ->withPadding(Spacing::vertical(1))
            ->add(Text::new('Hello'));

        $panel->render($frame, new Rect(0, 0, 20, 10), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[1 * 20 + 1], 'Top padding row should be empty');
        self::assertSame('H', $frame->characters[2 * 20 + 1], 'Content x stays at 1 (no horizontal padding)');
    }

    #[Test]
    public function horizontalPaddingOnlyAffectsLeftAndRight(): void
    {
        $frame = new Frame(20, 10);
        $panel = Panel::new('')
            ->withPadding(Spacing::horizontal(1))
            ->add(Text::new('Hello'));

        $panel->render($frame, new Rect(0, 0, 20, 10), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[1 * 20 + 1], 'Left padding column should be empty');
        self::assertSame('H', $frame->characters[1 * 20 + 2], 'Content y stays at row 1 (no vertical padding)');
    }

    #[Test]
    public function paddingSkipsRenderWhenNoSpaceForContent(): void
    {
        $frame = new Frame(5, 5);
        $panel = Panel::new('')
            ->withPadding(Spacing::all(1))
            ->add(Text::new('X'));

        $panel->render($frame, new Rect(0, 0, 5, 5), new RenderContext(Theme::dark()));

        self::assertSame('╭', $frame->characters[0]);
        self::assertSame('X', $frame->characters[2 * 5 + 2]);
    }

    #[Test]
    public function marginAndPaddingCombined(): void
    {
        $frame = new Frame(22, 12);
        $panel = Panel::new('')
            ->withMargin(Spacing::all(1))
            ->withPadding(Spacing::all(1))
            ->add(Text::new('X'));

        $panel->render($frame, new Rect(0, 0, 22, 12), new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[0], 'Margin area');
        self::assertSame('╭', $frame->characters[1 * 22 + 1], 'Border at margin+1');
        self::assertSame(' ', $frame->characters[2 * 22 + 2], 'Padding area inside border');
        self::assertSame('X', $frame->characters[3 * 22 + 3], 'Content at margin+border+padding');
    }

    #[Test]
    public function tabIsLeftToWhoeverWalksTheRing(): void
    {
        $panel = Panel::new('Test')->add(Text::new('A'))->add(Text::new('B'));

        $result = $panel->update(new KeyMsg(Key::Tab), new Rect(0, 0, 30, 20));

        self::assertSame($panel, $result->model, 'Each child the keyboard can reach is a stop of its own');
    }

    #[Test]
    public function scrollDownWithArrowKeyIncreasesOffset(): void
    {
        $panel = Panel::new('Test');
        $labels = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel', 'India', 'Juliet'];
        foreach ($labels as $label) {
            $panel = $panel->add(Text::new($label));
        }

        $area = new Rect(0, 0, 20, 5);
        $result = $panel->update(new KeyMsg(Key::Down), $area);
        self::assertNotSame($panel, $result->model, 'Down arrow should change scroll offset');

        $buffer1 = new Frame(20, 5);
        $panel->render($buffer1, $area, new RenderContext(Theme::dark()));
        $buffer2 = new Frame(20, 5);
        $result->model->render($buffer2, $area, new RenderContext(Theme::dark()));
        self::assertSame('A', $buffer1->characters[1 * 20 + 1], 'Before scroll: Alpha at row 1');
        self::assertSame('B', $buffer2->characters[1 * 20 + 1], 'After scroll: Bravo at row 1');
    }

    #[Test]
    public function scrollUpWithArrowKeyDecreasesOffset(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }

        $area = new Rect(0, 0, 20, 5);
        $scrolled = $panel->update(new KeyMsg(Key::Down), $area)->model;
        $scrolled = $scrolled->update(new KeyMsg(Key::Down), $area)->model;
        $result = $scrolled->update(new KeyMsg(Key::Up), $area);
        self::assertNotSame($scrolled, $result->model, 'Up arrow should change scroll offset');
    }

    #[Test]
    public function scrollUpAtTopReturnsSelf(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }

        $area = new Rect(0, 0, 20, 5);
        $result = $panel->update(new KeyMsg(Key::Up), $area);
        self::assertSame($panel, $result->model, 'Up at offset 0 should return self');
    }

    #[Test]
    public function scrollDownClampsToMaxOffset(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 5; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }

        $area = new Rect(0, 0, 20, 5);
        $current = $panel;
        for ($step = 0; $step < 20; ++$step) {
            $result = $current->update(new KeyMsg(Key::Down), $area);
            if ($result->model === $current) {
                break;
            }
            $current = $result->model;
        }

        $result = $current->update(new KeyMsg(Key::Down), $area);
        self::assertSame($current, $result->model, 'Should stop scrolling at max offset');
    }

    #[Test]
    public function mouseWheelOutsidePanelAreaDoesNotScroll(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }
        $area = new Rect(5, 5, 20, 10);

        $wheelOutside = new MouseMsg(3, 3, MouseAction::WheelDown, MouseButton::None);
        $result = $panel->update($wheelOutside, $area);
        self::assertSame($panel, $result->model, 'Wheel outside area should not scroll');
    }

    #[Test]
    public function mouseWheelDownDoesNotScrollWhenContentFits(): void
    {
        $panel = Panel::new('Test')
            ->add(Text::new('Short'));
        $area = new Rect(0, 0, 20, 10);

        $wheelDown = new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None);
        $result = $panel->update($wheelDown, $area);
        self::assertSame($panel, $result->model, 'No overflow means no scroll');
    }

    #[Test]
    public function mouseWheelUpDoesNotScrollBelowZero(): void
    {
        $labels = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel'];
        $panel = Panel::new('Test');
        foreach ($labels as $label) {
            $panel = $panel->add(Text::new($label));
        }
        $area = new Rect(0, 0, 20, 5);

        $wheelUp = new MouseMsg(5, 2, MouseAction::WheelUp, MouseButton::None);
        $result = $panel->update($wheelUp, $area)->model;

        $frame = new Frame(20, 5);
        $result->render($frame, $area, new RenderContext(Theme::dark()));
        self::assertSame('A', $frame->characters[1 * 20 + 1], 'Alpha still at top after wheel up at offset 0');

        $result2 = $result->update($wheelUp, $area)->model;
        $buffer2 = new Frame(20, 5);
        $result2->render($buffer2, $area, new RenderContext(Theme::dark()));
        self::assertSame('A', $buffer2->characters[1 * 20 + 1], 'Alpha still at top after multiple wheel ups');
    }

    #[Test]
    public function mouseWheelDownClampsToMaxOffset(): void
    {
        $labels = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'];
        $panel = Panel::new('Test');
        foreach ($labels as $label) {
            $panel = $panel->add(Text::new($label));
        }
        $area = new Rect(0, 0, 20, 5);

        $current = $panel;
        for ($step = 0; $step < 50; ++$step) {
            $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
            $current = $current->update($wheelDown, $area)->model;
        }

        $buffer1 = new Frame(20, 5);
        $current->render($buffer1, $area, new RenderContext(Theme::dark()));

        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $afterExtra = $current->update($wheelDown, $area)->model;

        $buffer2 = new Frame(20, 5);
        $afterExtra->render($buffer2, $area, new RenderContext(Theme::dark()));

        self::assertSame(
            $buffer1->characters[1 * 20 + 1],
            $buffer2->characters[1 * 20 + 1],
            'Content should be identical at max scroll'
        );
    }

    #[Test]
    public function mouseClickOutsideContentAreaDoesNotChangeFocus(): void
    {
        $focusableA = $this->createFocusableWidget();
        $focusableB = $this->createFocusableWidget();

        $panel = Panel::new('Test')
            ->add($focusableA)
            ->add($focusableB);

        $area = new Rect(0, 0, 30, 20);
        $clickOnBorder = new MouseMsg(0, 0, MouseAction::Press, MouseButton::Left);
        $result = $panel->update($clickOnBorder, $area);
        self::assertSame($panel, $result->model, 'Click on border should not change focus');
    }

    #[Test]
    public function mouseClickOnUnfocusedPanelDelegatesToChildren(): void
    {
        $bar = ProgressBar::new()->withProgress(0.5);
        $panel = Panel::new('Test')->add($bar);
        $area = new Rect(0, 0, 40, 10);
        $click = new MouseMsg(20, 5, MouseAction::Press, MouseButton::Left);
        $result = $panel->update($click, $area);
        self::assertInstanceOf(UpdateResult::class, $result);
    }

    #[Test]
    public function clickOnNonFocusableAreaDoesNotChangeFocusIndex(): void
    {
        $panel = Panel::new('Test')
            ->add(Text::new('Not focusable'))
            ->add($this->createFocusableWidget());

        $area = new Rect(0, 0, 30, 20);
        $clickOnText = new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left);
        $result = $panel->update($clickOnText, $area);
        self::assertInstanceOf(UpdateResult::class, $result);
    }

    #[Test]
    public function resolveChildHeightsWithFixedConstraints(): void
    {
        $panel = Panel::new('')
            ->add(Text::new('Line 1'))
            ->add(Text::new('Line 2'))
            ->add(Text::new('Line 3'));

        $frame = new Frame(20, 10);
        $area = new Rect(0, 0, 20, 10);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('L', $frame->characters[1 * 20 + 1]);
        self::assertSame('L', $frame->characters[2 * 20 + 1]);
        self::assertSame('L', $frame->characters[3 * 20 + 1]);
    }

    #[Test]
    public function resolveChildHeightsFlexChildrenGetRemainingSpace(): void
    {
        $panel = Panel::new('')
            ->add(Text::new('Fixed'))
            ->add(Panel::new(''))
            ->add(Text::new('Bottom'));

        $frame = new Frame(20, 10);
        $area = new Rect(0, 0, 20, 10);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('F', $frame->characters[1 * 20 + 1], 'Fixed at row 1');
        self::assertSame('B', $frame->characters[8 * 20 + 1], 'Bottom pushed to last content row by flex child');
    }

    #[Test]
    public function renderWithBorderStyleOverride(): void
    {
        $customStyle = new Style(
            foreground: Color::hex('FF0000')
        );
        $panel = new Panel(
            title: 'Test',
            borderStyleOverride: $customStyle,
        );

        $frame = new Frame(20, 10);
        $area = new Rect(0, 0, 20, 10);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        $overrideForeground = $frame->foregrounds[0];
        $defaultBuffer = new Frame(20, 10);
        Panel::new('Test')->render($defaultBuffer, $area, new RenderContext(Theme::dark()));
        $defaultForeground = $defaultBuffer->foregrounds[0];

        self::assertNotSame($defaultForeground, $overrideForeground, 'Border style override should change color');
    }

    #[Test]
    public function renderWithFocusedBorderStyleOverride(): void
    {
        $customStyle = new Style(
            foreground: Color::hex('00FF00')
        );
        $panel = Panel::new('Test')->withFocusedBorderStyle($customStyle);

        $frame = new Frame(20, 10);
        $area = new Rect(0, 0, 20, 10);
        $panel->render($frame, $area, RenderContext::focused(Theme::dark()));

        $overrideForeground = $frame->foregrounds[0];
        $defaultBuffer = new Frame(20, 10);
        Panel::new('Test')->render($defaultBuffer, $area, RenderContext::focused(Theme::dark()));
        $defaultForeground = $defaultBuffer->foregrounds[0];

        self::assertNotSame($defaultForeground, $overrideForeground, 'Focused border style override should change color');
    }

    #[Test]
    public function renderWithTitleStyleOverride(): void
    {
        $customTitleStyle = new Style(
            foreground: Color::hex('00FFFF')
        );
        $panel = new Panel(
            title: 'Hello',
            titleStyleOverride: $customTitleStyle,
        );

        $frame = new Frame(20, 10);
        $area = new Rect(0, 0, 20, 10);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        $overrideForeground = $frame->foregrounds[2];
        $defaultBuffer = new Frame(20, 10);
        Panel::new('Hello')->render($defaultBuffer, $area, new RenderContext(Theme::dark()));
        $defaultForeground = $defaultBuffer->foregrounds[2];

        self::assertNotSame($defaultForeground, $overrideForeground, 'Title style override should change color');
    }

    #[Test]
    public function renderSmallAreaWidthDoesNotRender(): void
    {
        $frame = new Frame(10, 10);
        $panel = Panel::new('Test');
        $panel->render($frame, new Rect(0, 0, 2, 10), new RenderContext(Theme::dark()));
        self::assertSame(' ', $frame->characters[0], 'Width < 3 should skip render');
    }

    #[Test]
    public function renderSmallAreaHeightDoesNotRender(): void
    {
        $frame = new Frame(10, 10);
        $panel = Panel::new('Test');
        $panel->render($frame, new Rect(0, 0, 10, 2), new RenderContext(Theme::dark()));
        self::assertSame(' ', $frame->characters[0], 'Height < 3 should skip render');
    }

    #[Test]
    public function renderExactly3x3AreaRenders(): void
    {
        $frame = new Frame(10, 10);
        $panel = Panel::new('');
        $panel->render($frame, new Rect(0, 0, 3, 3), new RenderContext(Theme::dark()));
        self::assertSame('╭', $frame->characters[0], 'Exactly 3x3 should render border');
    }

    #[Test]
    public function scrolledChildrenNotVisibleAboveViewportAreSkipped(): void
    {
        $labels = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel', 'India', 'Juliet'];
        $panel = Panel::new('');
        foreach ($labels as $label) {
            $panel = $panel->add(Text::new($label));
        }

        $area = new Rect(0, 0, 20, 5);
        $scrolled = $panel;
        for ($step = 0; $step < 3; ++$step) {
            $scrolled = $scrolled->update(new KeyMsg(Key::Down), $area)->model;
        }

        $frame = new Frame(20, 5);
        $scrolled->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('D', $frame->characters[1 * 20 + 1], 'After 3 scrolls, Delta should be at first content row');
    }

    #[Test]
    public function scrollbarReducesChildWidth(): void
    {
        $panel = Panel::new('');
        for ($index = 0; $index < 20; ++$index) {
            $longLine = str_repeat('X', 50);
            $panel = $panel->add(Text::new($longLine));
        }

        $frame = new Frame(20, 5);
        $area = new Rect(0, 0, 20, 5);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        $scrollbarX = 18;
        $hasScrollbar = false;
        for ($row = 1; $row < 4; ++$row) {
            $character = $frame->characters[$row * 20 + $scrollbarX];
            if ($character === Scrollbar::THUMB || $character === Scrollbar::TRACK) {
                $hasScrollbar = true;
                break;
            }
        }
        self::assertTrue($hasScrollbar, 'Scrollbar should be present when content overflows');
    }

    #[Test]
    public function clampedScrollOffsetDoesNotExceedContentMinusViewport(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 5; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }

        $area = new Rect(0, 0, 20, 5);
        $current = $panel;
        for ($step = 0; $step < 50; ++$step) {
            $result = $current->update(new KeyMsg(Key::Down), $area);
            if ($result->model === $current) {
                break;
            }
            $current = $result->model;
        }

        $frame = new Frame(20, 5);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertNotSame(' ', $frame->characters[1 * 20 + 1], 'Some content should remain visible at max scroll');
    }

    #[Test]
    public function effectivePaddingDefaultsToNone(): void
    {
        $panelDefault = Panel::new('');
        $panelExplicitNone = Panel::new('')
            ->withPadding(Spacing::none());

        $buffer1 = new Frame(20, 10);
        $buffer2 = new Frame(20, 10);
        $area = new Rect(0, 0, 20, 10);
        $context = new RenderContext(Theme::dark());

        $panelDefault->add(Text::new('X'))->render($buffer1, $area, $context);
        $panelExplicitNone->add(Text::new('X'))->render($buffer2, $area, $context);

        self::assertSame(
            $buffer1->characters[1 * 20 + 1],
            $buffer2->characters[1 * 20 + 1],
            'Default padding should equal Spacing::none()'
        );
    }

    #[Test]
    public function effectiveMarginDefaultsToNone(): void
    {
        $panelDefault = Panel::new('');
        $panelExplicitNone = Panel::new('')
            ->withMargin(Spacing::none());

        $buffer1 = new Frame(20, 10);
        $buffer2 = new Frame(20, 10);
        $area = new Rect(0, 0, 20, 10);
        $context = new RenderContext(Theme::dark());

        $panelDefault->render($buffer1, $area, $context);
        $panelExplicitNone->render($buffer2, $area, $context);

        self::assertSame($buffer1->characters[0], $buffer2->characters[0]);
    }

    #[Test]
    public function updateMatchArmForKeyMsgUnfocusedReturnsSelf(): void
    {
        $panel = Panel::new('Test')
            ->add($this->createFocusableWidget());

        $area = new Rect(0, 0, 30, 20);
        $result = $panel->update(new KeyMsg(Key::Down), $area);
        self::assertSame($panel, $result->model, 'KeyMsg on unfocused panel returns self');
    }

    #[Test]
    public function updateMatchArmForMouseMsgDelegatesRegardlessOfFocus(): void
    {
        $bar = ProgressBar::new()->withProgress(0.5);
        $panel = Panel::new('Test')->add($bar);
        $area = new Rect(0, 0, 40, 10);

        $click = new MouseMsg(20, 5, MouseAction::Press, MouseButton::Left);
        $result = $panel->update($click, $area);
        self::assertInstanceOf(UpdateResult::class, $result);
    }

    #[Test]
    public function handleMouseWheelDownIncrementsScrollOffset(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }
        $area = new Rect(0, 0, 20, 5);

        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $result1 = $panel->update($wheelDown, $area);
        self::assertNotSame($panel, $result1->model);

        $result2 = $result1->model->update($wheelDown, $area);
        self::assertNotSame($result1->model, $result2->model, 'Second wheel down should scroll further');
    }

    #[Test]
    public function handleMouseWheelUpDecrementsScrollOffset(): void
    {
        $labels = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel', 'India', 'Juliet'];
        $panel = Panel::new('Test');
        foreach ($labels as $label) {
            $panel = $panel->add(Text::new($label));
        }
        $area = new Rect(0, 0, 20, 5);

        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $scrolled = $panel->update($wheelDown, $area)->model;
        $scrolled = $scrolled->update($wheelDown, $area)->model;

        $wheelUp = new MouseMsg(5, 2, MouseAction::WheelUp, MouseButton::None);
        $result = $scrolled->update($wheelUp, $area);
        self::assertNotSame($scrolled, $result->model);

        $buffer1 = new Frame(20, 5);
        $scrolled->render($buffer1, $area, new RenderContext(Theme::dark()));
        $buffer2 = new Frame(20, 5);
        $result->model->render($buffer2, $area, new RenderContext(Theme::dark()));

        self::assertSame('C', $buffer1->characters[1 * 20 + 1], 'Before wheel up: Charlie at first content row');
        self::assertSame('B', $buffer2->characters[1 * 20 + 1], 'After wheel up: Bravo at first content row');
    }

    #[Test]
    public function aKeyNoScrollBindingAnswersLeavesThePanelAlone(): void
    {
        $panel = Panel::new('Test')->add($this->createTrackingFocusableWidget());

        $result = $panel->update(new KeyMsg('x'), new Rect(0, 0, 30, 20));

        self::assertSame($panel, $result->model, 'the child is a stop of its own; the panel answers only its scroll keys');
        self::assertNull($result->command);
    }

    #[Test]
    public function applyFocusedWidgetsPreservesNonFocusableChildren(): void
    {
        $panel = Panel::new('Test')
            ->add(Text::new('Before'))
            ->add($this->createFocusableWidget())
            ->add(Separator::new())
            ->add($this->createFocusableWidget())
            ->add(Text::new('After'));

        $frame = new Frame(30, 20);
        $area = new Rect(0, 0, 30, 20);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('B', $frame->characters[1 * 30 + 1], 'Before text preserved');
        self::assertSame('A', $frame->characters[9 * 30 + 1], 'After text preserved');
    }

    #[Test]
    public function resolveChildHeightsWithFlexDistribution(): void
    {
        $panel = Panel::new('')
            ->add(Text::new('Top'))
            ->add(Panel::new(''))
            ->add(Text::new('Bottom'));

        $frame = new Frame(20, 12);
        $area = new Rect(0, 0, 20, 12);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('T', $frame->characters[1 * 20 + 1], 'Top at row 1');
        self::assertSame('B', $frame->characters[10 * 20 + 1], 'Bottom at last content row');
    }

    #[Test]
    public function resolveChildHeightsNoFlexNoRemaining(): void
    {
        $panel = Panel::new('')
            ->add(Text::new('A'))
            ->add(Text::new('B'))
            ->add(Text::new('C'));

        $frame = new Frame(20, 6);
        $area = new Rect(0, 0, 20, 6);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[1 * 20 + 1]);
        self::assertSame('B', $frame->characters[2 * 20 + 1]);
        self::assertSame('C', $frame->characters[3 * 20 + 1]);
    }

    #[Test]
    public function scrollOffsetClampedWhenContentShrinks(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }

        $smallArea = new Rect(0, 0, 20, 5);
        $current = $panel;
        for ($step = 0; $step < 10; ++$step) {
            $result = $current->update(new KeyMsg(Key::Down), $smallArea);
            if ($result->model === $current) {
                break;
            }
            $current = $result->model;
        }

        $largeArea = new Rect(0, 0, 20, 25);
        $frame = new Frame(20, 25);
        $current->render($frame, $largeArea, new RenderContext(Theme::dark()));

        self::assertSame('L', $frame->characters[1 * 20 + 1], 'Clamped offset: content should show at top when area grows');
    }

    #[Test]
    public function contentAreaWithZeroDimensions(): void
    {
        $panel = Panel::new('')
            ->withPadding(Spacing::all(1));

        $frame = new Frame(5, 5);
        $panel->render($frame, new Rect(0, 0, 4, 4), new RenderContext(Theme::dark()));
        self::assertSame('╭', $frame->characters[0], 'Border renders even with tight content');
    }

    #[Test]
    public function renderContextPassedToChildren(): void
    {
        $panel = Panel::new('')
            ->add(Text::new('Themed'));

        $darkBuffer = new Frame(20, 10);
        $lightBuffer = new Frame(20, 10);
        $area = new Rect(0, 0, 20, 10);

        $panel->render($darkBuffer, $area, new RenderContext(Theme::dark()));
        $panel->render($lightBuffer, $area, new RenderContext(Theme::light()));

        $darkFg = $darkBuffer->foregrounds[1 * 20 + 1];
        $lightFg = $lightBuffer->foregrounds[1 * 20 + 1];
        self::assertNotSame($darkFg, $lightFg, 'Different themes produce different foreground colors');
    }

    #[Test]
    public function mouseScrollWithMargin(): void
    {
        $panel = Panel::new('Test')
            ->withMargin(Spacing::all(1));
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }

        $area = new Rect(0, 0, 22, 7);
        $wheelDown = new MouseMsg(5, 3, MouseAction::WheelDown, MouseButton::None);
        $result = $panel->update($wheelDown, $area);
        self::assertNotSame($panel, $result->model, 'Wheel scroll should work with margin');
    }

    #[Test]
    public function resolveChildHeightsMinConstraintUsed(): void
    {
        $select = Select::new(['Alpha', 'Bravo', 'Charlie']);
        $panel = Panel::new('')
            ->add($select)
            ->add(Text::new('Footer'));

        $frame = new Frame(20, 8);
        $area = new Rect(0, 0, 20, 8);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[1 * 20 + 1], 'Select option Alpha');
        self::assertSame('B', $frame->characters[2 * 20 + 1], 'Select option Bravo');
        self::assertSame('C', $frame->characters[3 * 20 + 1], 'Select option Charlie');
        self::assertSame('F', $frame->characters[4 * 20 + 1], 'Footer at row 4');
    }

    #[Test]
    public function childrenWithFlexGetMinimumOneRow(): void
    {
        $panel = Panel::new('')
            ->add(Spacer::new());

        $frame = new Frame(20, 4);
        $area = new Rect(0, 0, 20, 4);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('╭', $frame->characters[0], 'Panel renders');
    }

    #[Test]
    public function resolveChildHeightsFlexRemainder(): void
    {
        $panel = Panel::new('')
            ->add(Text::new('Fixed'))
            ->add(Spacer::new())
            ->add(Spacer::new());

        $frame = new Frame(20, 10);
        $area = new Rect(0, 0, 20, 10);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('F', $frame->characters[1 * 20 + 1], 'Fixed at row 1');
    }

    #[Test]
    public function scrollBottomPaddingCreatesExtraSpace(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 5; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }

        $area = new Rect(0, 0, 20, 5);
        $current = $panel;
        $scrollCount = 0;
        for ($step = 0; $step < 30; ++$step) {
            $result = $current->update(new KeyMsg(Key::Down), $area);
            if ($result->model === $current) {
                break;
            }
            ++$scrollCount;
            $current = $result->model;
        }
        self::assertGreaterThan(0, $scrollCount, 'Panel should scroll at least once');
    }

    #[Test]
    public function keyScrollStepIsOne(): void
    {
        $labels = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel'];
        $panel = Panel::new('Test');
        foreach ($labels as $label) {
            $panel = $panel->add(Text::new($label));
        }

        $area = new Rect(0, 0, 20, 5);

        $bufferBefore = new Frame(20, 5);
        $panel->render($bufferBefore, $area, new RenderContext(Theme::dark()));

        $scrolled = $panel->update(new KeyMsg(Key::Down), $area)->model;
        $bufferAfter = new Frame(20, 5);
        $scrolled->render($bufferAfter, $area, new RenderContext(Theme::dark()));

        self::assertSame('A', $bufferBefore->characters[1 * 20 + 1], 'Before: Alpha at first row');
        self::assertSame('B', $bufferAfter->characters[1 * 20 + 1], 'After: Bravo at first row (shifted by 1)');
    }

    #[Test]
    public function wheelScrollStepIsOne(): void
    {
        $labels = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel'];
        $panel = Panel::new('Test');
        foreach ($labels as $label) {
            $panel = $panel->add(Text::new($label));
        }

        $area = new Rect(0, 0, 20, 5);

        $bufferBefore = new Frame(20, 5);
        $panel->render($bufferBefore, $area, new RenderContext(Theme::dark()));

        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $scrolled = $panel->update($wheelDown, $area)->model;
        $bufferAfter = new Frame(20, 5);
        $scrolled->render($bufferAfter, $area, new RenderContext(Theme::dark()));

        self::assertSame('A', $bufferBefore->characters[1 * 20 + 1], 'Before: Alpha at first row');
        self::assertSame('B', $bufferAfter->characters[1 * 20 + 1], 'After: Bravo at first row (shifted by 1)');
    }

    #[Test]
    public function childrenRenderedWithCorrectWidthWhenScrollbarPresent(): void
    {
        $panel = Panel::new('');
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new(str_repeat('X', 30)));
        }

        $frame = new Frame(20, 5);
        $area = new Rect(0, 0, 20, 5);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        $scrollbarX = 18;
        $scrollbarChar = $frame->characters[1 * 20 + $scrollbarX];
        self::assertTrue(
            $scrollbarChar === Scrollbar::THUMB || $scrollbarChar === Scrollbar::TRACK,
            "Scrollbar at x=$scrollbarX, found: $scrollbarChar"
        );

        $lastContentX = $scrollbarX - 1;
        self::assertSame('X', $frame->characters[1 * 20 + $lastContentX], 'Content fills up to scrollbar');
    }

    #[Test]
    public function childrenRenderedWithFullWidthWhenNoScrollbar(): void
    {
        $panel = Panel::new('')
            ->add(Text::new(str_repeat('Y', 30)));

        $frame = new Frame(20, 5);
        $area = new Rect(0, 0, 20, 5);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('Y', $frame->characters[1 * 20 + 17], 'Content fills last column before border');
    }

    #[Test]
    public function visibilityCheckSkipsChildBelowViewport(): void
    {
        $panel = Panel::new('');
        $panel = $panel->add(Text::new('Alpha'));
        $panel = $panel->add(Text::new('Bravo'));
        $panel = $panel->add(Text::new('Charlie'));

        $frame = new Frame(20, 4);
        $area = new Rect(0, 0, 20, 4);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[1 * 20 + 1], 'Alpha visible at row 1');
        self::assertSame('B', $frame->characters[2 * 20 + 1], 'Bravo visible at row 2');
        self::assertSame('╰', $frame->characters[3 * 20 + 0], 'Row 3 is bottom border, Charlie not rendered');
    }

    #[Test]
    public function visibilityCheckSkipsChildAboveViewportAfterScroll(): void
    {
        $panel = Panel::new('');
        for ($index = 0; $index < 10; ++$index) {
            $panel = $panel->add(Text::new("L$index"));
        }

        $area = new Rect(0, 0, 20, 5);
        $scrolled = $panel;
        for ($step = 0; $step < 3; ++$step) {
            $scrolled = $scrolled->update(new KeyMsg(Key::Down), $area)->model;
        }

        $frame = new Frame(20, 5);
        $scrolled->render($frame, $area, new RenderContext(Theme::dark()));
        self::assertSame('L', $frame->characters[1 * 20 + 1]);
    }

    #[Test]
    public function focusTreeCountMatchesFocusableChildrenNotTotalChildren(): void
    {
        $panel = Panel::new('Test')
            ->add(Text::new('A'))
            ->add(Text::new('B'))
            ->add(Text::new('C'))
            ->add($this->createFocusableWidget());

        $area = new Rect(0, 0, 30, 30);
        $result = $panel->update(new KeyMsg(Key::Tab), $area);
        self::assertSame($panel, $result->model, 'Only 1 focusable child, Tab should wrap immediately');
    }

    #[Test]
    public function handleKeyMatchArmUpDecreasesScrollOffset(): void
    {
        $labels = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'];
        $panel = Panel::new('Test');
        foreach ($labels as $label) {
            $panel = $panel->add(Text::new($label));
        }

        $area = new Rect(0, 0, 20, 5);
        $scrolled = $panel->update(new KeyMsg(Key::Down), $area)->model;
        $scrolled = $scrolled->update(new KeyMsg(Key::Down), $area)->model;

        $result = $scrolled->update(new KeyMsg(Key::Up), $area);
        self::assertNotSame($scrolled, $result->model, 'Up should decrease scroll');

        $frame = new Frame(20, 5);
        $result->model->render($frame, $area, new RenderContext(Theme::dark()));
        self::assertSame('B', $frame->characters[1 * 20 + 1], 'After scroll up, Bravo at top');
    }

    #[Test]
    public function handleKeyDefaultArmReturnsSameOffset(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 10; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }

        $area = new Rect(0, 0, 20, 5);
        $result = $panel->update(new KeyMsg('x'), $area);
        self::assertSame($panel, $result->model, 'Unhandled key returns self');
    }

    #[Test]
    public function handleKeyNewOffsetSameAsCurrentReturnsSelf(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 10; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }

        $area = new Rect(0, 0, 20, 5);
        $result = $panel->update(new KeyMsg(Key::Up), $area);
        self::assertSame($panel, $result->model, 'Already at 0, up returns self without new instance');
    }

    #[Test]
    public function handleKeyDownAtMaxOffsetReturnsSelf(): void
    {
        $labels = ['Alpha', 'Bravo', 'Charlie'];
        $panel = Panel::new('Test');
        foreach ($labels as $label) {
            $panel = $panel->add(Text::new($label));
        }

        $area = new Rect(0, 0, 20, 5);
        $current = $panel;
        for ($step = 0; $step < 20; ++$step) {
            $result = $current->update(new KeyMsg(Key::Down), $area);
            if ($result->model === $current) {
                break;
            }
            $current = $result->model;
        }
        $result = $current->update(new KeyMsg(Key::Down), $area);
        self::assertSame($current, $result->model, 'At max offset, down returns self');
    }

    #[Test]
    public function tabWouldWrapReturnsSelfWithNoCommand(): void
    {
        $panel = Panel::new('Test')
            ->add($this->createFocusableWidget());

        $area = new Rect(0, 0, 30, 20);
        $result = $panel->update(new KeyMsg(Key::Tab), $area);
        self::assertSame($panel, $result->model);
        self::assertNull($result->command);
    }

    #[Test]
    public function resolveChildHeightsLastFlexGetsRemainder(): void
    {
        $panel = Panel::new('')
            ->add(Panel::new('A'))
            ->add(Panel::new('B'))
            ->add(Panel::new('C'));

        $frame = new Frame(20, 10);
        $area = new Rect(0, 0, 20, 10);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('╭', $frame->characters[0], 'Panel renders');
    }

    #[Test]
    public function resolveChildHeightsFlexWithZeroRemainingGetsOne(): void
    {
        $panel = Panel::new('')
            ->add(Text::new('A'))
            ->add(Text::new('B'))
            ->add(Text::new('C'))
            ->add(Text::new('D'))
            ->add(Text::new('E'))
            ->add(Text::new('F'))
            ->add(Text::new('G'))
            ->add(Text::new('H'))
            ->add(Panel::new(''));

        $frame = new Frame(20, 12);
        $area = new Rect(0, 0, 20, 12);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[1 * 20 + 1], 'First child renders');
    }

    #[Test]
    public function updateFocusOnClickMatchesChildByXCoordinate(): void
    {
        $focusableA = $this->createFocusableWidget();

        $panel = Panel::new('Test')
            ->add($focusableA);

        $area = new Rect(0, 0, 30, 20);
        $clickOutsideX = new MouseMsg(0, 2, MouseAction::Press, MouseButton::Left);
        $result = $panel->update($clickOutsideX, $area);
        self::assertInstanceOf(UpdateResult::class, $result);
    }

    #[Test]
    public function clampedScrollOffsetWithZeroTotalHeight(): void
    {
        $panel = Panel::new('Test');

        $area = new Rect(0, 0, 20, 10);
        $result = $panel->update(new KeyMsg(Key::Down), $area);
        self::assertSame($panel, $result->model, 'No children means no scroll');
    }

    #[Test]
    public function clampedScrollOffsetWhenTotalLessThanViewport(): void
    {
        $panel = Panel::new('Test')
            ->add(Text::new('Only one'));

        $area = new Rect(0, 0, 20, 10);
        $result = $panel->update(new KeyMsg(Key::Down), $area);
        self::assertSame($panel, $result->model, 'Content fits, no scroll');
    }

    #[Test]
    public function renderWithMarginAndScrollableContent(): void
    {
        $labels = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf', 'Hotel'];
        $panel = Panel::new('Test')
            ->withMargin(Spacing::all(1));
        foreach ($labels as $label) {
            $panel = $panel->add(Text::new($label));
        }

        $area = new Rect(0, 0, 22, 7);
        $scrolled = $panel->update(new KeyMsg(Key::Down), $area)->model;

        $frame = new Frame(22, 7);
        $scrolled->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame(' ', $frame->characters[0], 'Margin preserved');
    }

    #[Test]
    public function renderContentAreaTooSmallAfterPadding(): void
    {
        $panel = Panel::new('')
            ->withPadding(Spacing::all(1))
            ->add(Text::new('X'));

        $frame = new Frame(5, 5);
        $panel->render($frame, new Rect(0, 0, 4, 4), new RenderContext(Theme::dark()));
        self::assertSame('╭', $frame->characters[0]);
    }

    #[Test]
    public function scrollOffsetWithScrollBottomPaddingConstant(): void
    {
        $labels = ['Alpha', 'Bravo', 'Charlie', 'Delta'];
        $panel = Panel::new('Test');
        foreach ($labels as $label) {
            $panel = $panel->add(Text::new($label));
        }

        $area = new Rect(0, 0, 20, 5);
        $current = $panel;
        $maxScrollCount = 0;
        for ($step = 0; $step < 20; ++$step) {
            $result = $current->update(new KeyMsg(Key::Down), $area);
            if ($result->model === $current) {
                break;
            }
            ++$maxScrollCount;
            $current = $result->model;
        }

        self::assertGreaterThanOrEqual(1, $maxScrollCount, 'Should scroll when content exceeds viewport');
    }

    #[Test]
    public function mouseClickOnFocusedPanelWithNoFocusableChildrenDelegates(): void
    {
        $bar = ProgressBar::new()->withProgress(0.5);
        $panel = Panel::new('Test')
            ->add($bar);

        $area = new Rect(0, 0, 40, 10);
        $click = new MouseMsg(20, 5, MouseAction::Press, MouseButton::Left);
        $result = $panel->update($click, $area);
        self::assertInstanceOf(UpdateResult::class, $result);
    }

    #[Test]
    public function scrollOffsetClampsMaxCantGoNegative(): void
    {
        $panel = Panel::new('Test')
            ->add(Text::new('Tiny'));

        $area = new Rect(0, 0, 20, 10);

        $frame = new Frame(20, 10);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));
        self::assertSame('T', $frame->characters[1 * 20 + 1], 'Content visible at correct position');
    }

    #[Test]
    public function clickOnExactTopEdgeOfFocusableChildSelectsIt(): void
    {
        $focusableA = $this->createFocusableWidget();
        $focusableB = $this->createFocusableWidget();

        $panel = Panel::new('Test')
            ->add($focusableA)
            ->add($focusableB);

        $area = new Rect(0, 0, 30, 20);

        $clickOnTopOfA = new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left);
        $result = $panel->update($clickOnTopOfA, $area);
        $afterClick = $result->model;

        $shiftTab = $afterClick->update(new KeyMsg(Key::Tab, shift: true), $area);
        self::assertSame($afterClick, $shiftTab->model, 'Click on top edge of A selects A, shift-tab wraps');
    }

    #[Test]
    public function clickOnBottomEdgeExcludedFromChild(): void
    {
        $focusableA = $this->createFocusableWidget();
        $focusableB = $this->createFocusableWidget();

        $panel = Panel::new('Test')
            ->add($focusableA)
            ->add($focusableB);

        $area = new Rect(0, 0, 30, 20);

        $clickOnTopOfB = new MouseMsg(5, 4, MouseAction::Press, MouseButton::Left);
        $result = $panel->update($clickOnTopOfB, $area);
        $afterClick = $result->model;

        $tab = $afterClick->update(new KeyMsg(Key::Tab), $area);
        self::assertSame($afterClick, $tab->model, 'Click on B top edge selects B, tab wraps');
    }

    #[Test]
    public function clickOnLeftEdgeOfContentArea(): void
    {
        $focusableA = $this->createFocusableWidget();

        $panel = Panel::new('Test')
            ->add($focusableA);

        $area = new Rect(0, 0, 30, 20);
        $clickOnLeftEdge = new MouseMsg(1, 2, MouseAction::Press, MouseButton::Left);
        $result = $panel->update($clickOnLeftEdge, $area);
        self::assertInstanceOf(UpdateResult::class, $result);
    }

    #[Test]
    public function clickRightOfContentAreaDoesNotSelectChild(): void
    {
        $focusableA = $this->createFocusableWidget();

        $panel = Panel::new('Test')
            ->add($focusableA);

        $area = new Rect(0, 0, 30, 20);
        $clickOutsideRight = new MouseMsg(29, 2, MouseAction::Press, MouseButton::Left);
        $result = $panel->update($clickOutsideRight, $area);
        self::assertInstanceOf(UpdateResult::class, $result);
    }

    #[Test]
    public function clickBetweenFocusableChildrenDoesNotChangeFocus(): void
    {
        $focusableA = $this->createFocusableWidget();
        $focusableB = $this->createFocusableWidget();

        $panel = Panel::new('Test')
            ->add($focusableA)
            ->add(Text::new('Gap'))
            ->add($focusableB);

        $area = new Rect(0, 0, 30, 20);
        $clickOnGap = new MouseMsg(5, 4, MouseAction::Press, MouseButton::Left);
        $result = $panel->update($clickOnGap, $area);

        $afterClick = $result->model;
        $shiftTab = $afterClick->update(new KeyMsg(Key::Tab, shift: true), $area);
        self::assertSame($afterClick, $shiftTab->model, 'Click on non-focusable gap keeps focus at 0');
    }

    #[Test]
    public function childYAdvancesForEachChildInClickDetection(): void
    {
        $focusableA = $this->createFocusableWidget();
        $focusableB = $this->createFocusableWidget();

        $panel = Panel::new('Test')
            ->add(Text::new('Row1'))
            ->add(Text::new('Row2'))
            ->add($focusableA)
            ->add($focusableB);

        $area = new Rect(0, 0, 30, 20);
        $clickOnB = new MouseMsg(5, 8, MouseAction::Press, MouseButton::Left);
        $result = $panel->update($clickOnB, $area);
        $afterClick = $result->model;

        $tab = $afterClick->update(new KeyMsg(Key::Tab), $area);
        self::assertSame($afterClick, $tab->model, 'Click on second focusable child, tab wraps at end');
    }

    #[Test]
    public function resolveChildHeightsFixedTotalCalculation(): void
    {
        $panel = Panel::new('')
            ->add(Text::new('A'))
            ->add(Text::new('B'))
            ->add(Panel::new(''));

        $frame = new Frame(20, 8);
        $area = new Rect(0, 0, 20, 8);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('A', $frame->characters[1 * 20 + 1], 'A at row 1');
        self::assertSame('B', $frame->characters[2 * 20 + 1], 'B at row 2');
    }

    #[Test]
    public function resolveChildHeightsFlexSizeCalculation(): void
    {
        $panel = Panel::new('')
            ->add(Panel::new('InnerA'))
            ->add(Panel::new('InnerB'));

        $frame = new Frame(30, 10);
        $area = new Rect(0, 0, 30, 10);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame('╭', $frame->characters[0], 'Outer panel renders');
    }

    #[Test]
    public function scrollbarTotalIncludesBottomPadding(): void
    {
        $panel = Panel::new('');
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new("Item $index"));
        }

        $frame = new Frame(20, 8);
        $area = new Rect(0, 0, 20, 8);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        $scrollbarColumn = 18;
        $hasThumb = false;
        for ($row = 1; $row < 7; ++$row) {
            $character = $frame->characters[$row * 20 + $scrollbarColumn];
            if ($character === Scrollbar::THUMB) {
                $hasThumb = true;
            }
        }
        self::assertTrue($hasThumb, 'Scrollbar thumb visible');
    }

    #[Test]
    public function aScrolledPanelPlacesItsChildrenHigherUp(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }

        $area = new Rect(0, 0, 20, 5);
        $wheelDown = new MouseMsg(5, 2, MouseAction::WheelDown, MouseButton::None);
        $scrolled = $panel->update($wheelDown, $area);
        assert($scrolled->model instanceof Panel);

        $childRects = $scrolled->model->placedChildren($area);
        $unscrolledChildRects = $panel->placedChildren($area);

        self::assertNotSame(
            $unscrolledChildRects[0][1]->y,
            $childRects[0][1]->y,
            'Scrolled panel should have different child Y position',
        );
        self::assertLessThan(
            $unscrolledChildRects[0][1]->y,
            $childRects[0][1]->y,
            'Scrolled child should be above unscrolled position',
        );
    }

    #[Test]
    public function panelBorderRenderPerformanceBudget(): void
    {
        self::skipUnderXdebug();

        $frame = new Frame(80, 24);
        $rect = new Rect(0, 0, 80, 24);
        $context = new RenderContext(Theme::dark());
        $panel = Panel::new('Perf');

        self::assertWithinBudget(
            200.0,
            self::medianMicroseconds(static fn (): mixed => $panel->render($frame, $rect, $context)),
            'Panel::render (empty, 80x24)',
        );
    }

    #[Test]
    public function supportsCustomScrollUpKeybinding(): void
    {
        $area = Rect::fromSize(20, 3);
        $panel = Panel::new('T');
        for ($i = 1; $i <= 10; ++$i) {
            $panel = $panel->add(Text::new("line{$i}"));
        }
        $panel = $panel->withScrollOffset(3);

        $afterDefault = $panel->update(new KeyMsg(Key::Up), $area)->model;
        self::assertLessThan(3, $afterDefault->scrollOffset());

        $custom = $panel->withKeybindings(new Keybindings([Actions::SCROLL_UP => ['x']]));
        self::assertSame($custom, $custom->update(new KeyMsg(Key::Up), $area)->model);

        $afterCustom = $custom->update(new KeyMsg('x'), $area)->model;
        self::assertLessThan(3, $afterCustom->scrollOffset());
    }

    #[Test]
    public function supportsCustomScrollDownKeybinding(): void
    {
        $area = Rect::fromSize(20, 3);
        $panel = Panel::new('T');
        for ($i = 1; $i <= 10; ++$i) {
            $panel = $panel->add(Text::new("line{$i}"));
        }

        $afterDefault = $panel->update(new KeyMsg(Key::Down), $area)->model;
        self::assertGreaterThan(0, $afterDefault->scrollOffset());

        $custom = $panel->withKeybindings(new Keybindings([Actions::SCROLL_DOWN => ['x']]));
        self::assertSame($custom, $custom->update(new KeyMsg(Key::Down), $area)->model);

        $afterCustom = $custom->update(new KeyMsg('x'), $area)->model;
        self::assertGreaterThan(0, $afterCustom->scrollOffset());
    }

    #[Test]
    public function scrolledMultiRowChildDoesNotRenderAbovePanelArea(): void
    {
        // Six-row child inside a Panel whose visible content is only 2 rows tall.
        // Without clipping, when scrolled, the child's top rows leak above the
        // Panel's own area into surrounding widgets.
        $multiLine = new PanelMultiLineWidget(6);
        $panel = Panel::new('p')->add($multiLine);

        // Scroll past the first 3 rows via the readonly with() helper
        $panel = $panel->withScrollOffset(3);

        $frame = new Frame(20, 15);
        for ($y = 0; $y < 5; ++$y) {
            for ($x = 0; $x < 20; ++$x) {
                $frame->putChar($x, $y, '#');
            }
        }

        $panel->render($frame, new Rect(0, 5, 20, 4), new RenderContext(Theme::dark()));

        for ($y = 0; $y < 5; ++$y) {
            $row = '';
            for ($x = 0; $x < 20; ++$x) {
                $row .= $frame->characters[$y * 20 + $x];
            }
            self::assertStringNotContainsString('row-', $row, "Scrolled child leaked into row {$y} above panel: \"{$row}\"");
        }
    }

    #[Test]
    public function aPanelWithAFocusableChildStillScrollsOnItsOwnKeys(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new("Line $index"));
        }
        $panel = $panel->add($this->createFocusableWidget());

        $result = $panel->update(new KeyMsg(Key::Down), new Rect(0, 0, 20, 5));

        self::assertNotSame($panel, $result->model);
    }

    #[Test]
    public function panelScrollsParagraphContentExceedingViewport(): void
    {
        // A Paragraph (flex constraint) with content taller than the panel's
        // viewport must allow scrolling — the panel must consult MeasureHeight.
        $lines = [];
        for ($i = 0; $i < 30; ++$i) {
            $lines[] = Line::raw("paragraph-line-{$i}");
        }

        $panel = Panel::new('About')
            ->add(Paragraph::fromLines($lines));

        $area = new Rect(0, 0, 40, 6);
        $panel = $panel->update(new KeyMsg(Key::Down), $area)->model;
        $panel = $panel->update(new KeyMsg(Key::Down), $area)->model;

        $frame = new Frame(40, 6);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        $foundLine0 = false;
        $foundLine2 = false;
        for ($y = 0; $y < 6; ++$y) {
            $row = '';
            for ($x = 0; $x < 40; ++$x) {
                $row .= $frame->characters[$y * 40 + $x];
            }
            if (str_contains($row, 'paragraph-line-0 ')) {
                $foundLine0 = true;
            }
            if (str_contains($row, 'paragraph-line-2 ')) {
                $foundLine2 = true;
            }
        }

        self::assertFalse($foundLine0, 'After scrolling down twice, paragraph-line-0 must no longer be visible inside the Panel');
        self::assertTrue($foundLine2, 'After scrolling down twice, paragraph-line-2 must be visible inside the Panel');
    }

    #[Test]
    public function aCursorLandingExactlyOneRowPastTheViewportStillScrolls(): void
    {
        $screen = $this->afterJumping(cursorRow: 0, jumpTarget: 3, scrollOffset: 0, area: new Rect(0, 0, 20, 7));

        self::assertStringContainsString('cur-3', $screen, 'The row the cursor landed on');
        self::assertStringNotContainsString('row-0', $screen, 'One row scrolled off the top is all it took');
        self::assertStringContainsString('row-1', $screen);
    }

    #[Test]
    public function aCursorThatStaysVisibleDoesNotScroll(): void
    {
        $screen = $this->afterJumping(cursorRow: 5, jumpTarget: 2, scrollOffset: 2, area: new Rect(0, 0, 20, 7));

        self::assertStringContainsString('cur-0', $screen, 'The view stayed where the reader left it');
        self::assertStringContainsString('cur-2', $screen, 'The row the cursor landed on');
    }

    #[Test]
    public function aPanelTooShortForItsBorderStillFollowsTheCursor(): void
    {
        $screen = $this->afterJumping(cursorRow: 0, jumpTarget: 5, scrollOffset: 0, area: new Rect(0, 0, 20, 2));

        self::assertStringContainsString('cur-5', $screen);
    }

    #[Test]
    public function theFollowedCursorCountsTheHeightOfChildrenAboveIt(): void
    {
        $area = new Rect(0, 0, 20, 7);
        $moved = Panel::new()
            ->withChildren([$this->cursorJumpChild(0, 5)])
            ->withKeyboardAt(0)
            ->withChildAt(0, $this->cursorJumpChild(5, 5));

        $withSpacerAbove = $this->afterJumping(cursorRow: 0, jumpTarget: 5, scrollOffset: 0, area: $area);

        self::assertStringContainsString('cur-5', $this->screenOf($moved, $area), 'Alone, one row off the top brings the cursor into view');
        self::assertStringContainsString('cur-5', $withSpacerAbove);
        self::assertStringNotContainsString('row-1', $withSpacerAbove, 'The two rows above went with it, so the same cursor cost three');
    }

    /**
     * The guard against rebuilding compared the children against what the pane
     * reports, which is its children with focus applied — so a focused panel never
     * matched itself and dropped its scroll position and inner cursor every frame.
     */
    #[Test]
    public function aFocusedPanelKeepsItsPaneWhenHandedTheSameChildren(): void
    {
        $children = [TextInput::new(), TextInput::new()];
        $panel = Panel::new('Log')->withChildren($children);

        self::assertSame($panel, $panel->withChildren($children));
    }

    protected function createFocusable(): Focusable
    {
        return Panel::new('Test');
    }

    private function eightOptions(): Select
    {
        return Select::new(['opt0', 'opt1', 'opt2', 'opt3', 'opt4', 'opt5', 'opt6', 'opt7']);
    }

    private function eightOptionsAtRow(int $row): Select
    {
        $select = $this->eightOptions();

        for ($step = 0; $step < $row; ++$step) {
            $moved = $select->update(new KeyMsg(Key::Down), new Rect(0, 0, 22, 4))->model;
            self::assertInstanceOf(Select::class, $moved);
            $select = $moved;
        }

        return $select;
    }

    private static function widgetDrawingPastItsArea(): Widget
    {
        return new class implements Widget {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex(), Constraint::flex());
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putString($area->x, $area->y, str_repeat('X', 36));
                $frame->putString($area->x, $area->bottom(), str_repeat('X', 6));
            }
        };
    }

    private function screenWith(Panel $panel, Rect $area, Theme $theme, bool $focused = false): string
    {
        $frame = new Frame($area->width, $area->height);
        $context = $focused ? RenderContext::focused($theme) : new RenderContext($theme);
        $panel->render($frame, $area, $context);

        return implode('', $frame->characters);
    }

    private function borderColour(Panel $panel, Rect $area, ?Theme $theme = null, bool $focused = false): int
    {
        $frame = new Frame($area->width, $area->height);
        $context = $focused ? RenderContext::focused($theme ?? Theme::dark()) : new RenderContext($theme ?? Theme::dark());
        $panel->render($frame, $area, $context);

        return $frame->foregrounds[0];
    }

    private function titleColour(Panel $panel, Rect $area): int
    {
        $frame = new Frame($area->width, $area->height);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        for ($column = 0; $column < $area->width; ++$column) {
            if ($frame->characters[$column] === 'H') {
                return $frame->foregrounds[$column];
            }
        }

        self::fail('The title was not drawn on the top border');
    }

    private function panelOfLines(int $count): Panel
    {
        $panel = Panel::new('t');

        for ($index = 0; $index < $count; ++$index) {
            $panel = $panel->add(Text::new("line{$index}"));
        }

        return $panel;
    }

    private function screenOf(Panel $panel, Rect $area): string
    {
        $frame = new Frame($area->width, $area->height);
        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        $screen = '';
        for ($row = 0; $row < $area->height; ++$row) {
            for ($column = 0; $column < $area->width; ++$column) {
                $screen .= $frame->characters[$row * $area->width + $column];
            }
            $screen .= "\n";
        }

        return $screen;
    }

    private function afterJumping(int $cursorRow, int $jumpTarget, int $scrollOffset, Rect $area): string
    {
        $panel = $this->panelFollowingACursor($cursorRow, $jumpTarget, $scrollOffset)
            ->withKeyboardAt(1)
            ->withChildAt(1, $this->cursorJumpChild($jumpTarget, $jumpTarget));

        return $this->screenOf($panel, $area);
    }

    private function panelFollowingACursor(int $cursorRow, int $jumpTarget, int $scrollOffset): Panel
    {
        return Panel::new()
            ->withChildren([new PanelMultiLineWidget(2), $this->cursorJumpChild($cursorRow, $jumpTarget)])
            ->withScrollOffset($scrollOffset);
    }

    private function cursorJumpChild(int $cursorRow, int $jumpTarget): Interactive
    {
        return new class($cursorRow, $jumpTarget) implements Interactive, Focusable, CursorAware {
            public function __construct(
                private readonly int $cursorRow,
                private readonly int $jumpTarget,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(6), Constraint::exact(6));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                for ($row = 0; $row < 6; ++$row) {
                    $frame->putString($area->x, $area->y + $row, 'cur-' . $row);
                }
            }

            public function update(mixed $message, Rect $area): UpdateResult
            {
                return $message instanceof KeyMsg
                    ? new UpdateResult(new self($this->jumpTarget, $this->jumpTarget))
                    : new UpdateResult($this);
            }

            public function cursorRow(): int
            {
                return $this->cursorRow;
            }
        };
    }

    private function scrollOffsetOf(Panel $panel): int
    {
        return $panel->scrollOffset();
    }

    private function createFocusableWidget(): Focusable
    {
        return new class implements Focusable {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(3), Constraint::exact(3));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }

            public function update(mixed $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }
        };
    }

    private function createFocusableWidgetWithCommand(): Focusable
    {
        return new class implements Focusable {
            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(3), Constraint::exact(3));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }

            public function update(mixed $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this, Cmd::quit());
            }
        };
    }

    private function createTrackingFocusableWidget(): Focusable
    {
        return new class(0) implements Focusable {
            public function __construct(public readonly int $updateCount)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(3), Constraint::exact(3));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }

            public function update(mixed $message, Rect $area): UpdateResult
            {
                return new UpdateResult(new static($this->updateCount + 1));
            }
        };
    }
}

final readonly class PanelMultiLineWidget implements Widget
{
    public function __construct(private int $lineCount)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact($this->lineCount), Constraint::exact($this->lineCount));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        for ($i = 0; $i < $this->lineCount; ++$i) {
            $frame->putString($area->x, $area->y + $i, "row-{$i}");
        }
    }
}

final readonly class PanelCommandWidget implements Interactive
{
    public function __construct(private Cmd $command)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
    }

    public function update(mixed $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this, $this->command);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final readonly class TabConsumingWidget implements Focusable
{
    public function __construct(private bool $focused = false, private bool $consumed = false)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
    }

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function withFocus(bool $focused): static
    {
        return new static($focused, $this->consumed);
    }

    public function update(mixed $message, Rect $area): UpdateResult
    {
        if (!$message instanceof KeyMsg || $message->key !== Key::Tab) {
            return new UpdateResult($this);
        }

        return new UpdateResult(new static($this->focused, true));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $frame->putString($area->x, $area->y, $this->consumed ? 'consumed' : 'waiting');
    }
}
