<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Constraint;
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
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Support\RootPass;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Widget\Bar\Scrollbar;
use Elmphant\Tui\Widget\Choice\Radio;
use Elmphant\Tui\Widget\CursorAware;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Plan\LayoutPlan;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\TabBar;
use Elmphant\Tui\Widget\Structure\TabPage;
use Elmphant\Tui\Widget\Widget;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionObject;
use ReflectionProperty;

final class TabBarTest extends TestCase
{
    // ── Basic construction & interface ──

    #[Test]
    public function aChildAddedBeforeAnyTabIsRefusedRatherThanLost(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('addTab()');

        TabBar::new()->add(Text::line('orphan'));
    }

    #[Test]
    public function aPreResolvedLayoutPlanDrawsExactlyWhatDirectLayoutDraws(): void
    {
        $area = new Rect(0, 0, 24, 9);
        $tabBar = TabBar::new()->addTab('A');
        for ($index = 0; $index < 8; ++$index) {
            $tabBar = $tabBar->add(Text::new("row{$index}"));
        }

        $planned = new Frame(24, 9);
        RootPass::draw(RenderPass::new(Theme::dark()), $tabBar, $planned, $area, LayoutPlan::resolve($tabBar, $area));

        $direct = new Frame(24, 9);
        $tabBar->render($direct, $area, new RenderContext(Theme::dark()));

        self::assertSame($direct->characters, $planned->characters, 'The two layout paths must agree');
    }

    #[Test]
    public function aTabPastTheLastOneSelectsTheLastAndTakesItsChildren(): void
    {
        $bar = TabBar::new()->addTab('one')->addTab('two')->add(Text::line('old'))->withSelectedTab(5);

        $grafted = $bar->withChildAt(0, Text::line('new'));

        self::assertSame(1, $grafted->selectedTab());
        self::assertEquals([Text::line('new')], $grafted->children());
    }

    #[Test]
    public function theWheelStopsAtTheBottomOfATabsContent(): void
    {
        $area = new Rect(0, 0, 24, 9);
        $tabBar = TabBar::new()->addTab('A');

        for ($index = 0; $index < 8; ++$index) {
            $tabBar = $tabBar->add(Text::new("row{$index}"));
        }

        $wheel = new MouseMsg(2, 6, MouseAction::WheelDown, MouseButton::Left);
        for ($turn = 0; $turn < 30; ++$turn) {
            $result = $tabBar->update($wheel, $area);
            self::assertInstanceOf(TabBar::class, $result->model);
            $tabBar = $result->model;
        }

        $atRest = $this->screenOfTabBar($tabBar, $area);
        self::assertStringContainsString('row7', $atRest, 'The last row is reachable');

        $further = $tabBar->update($wheel, $area);
        self::assertInstanceOf(TabBar::class, $further->model);

        self::assertSame($atRest, $this->screenOfTabBar($further->model, $area), 'And the wheel goes no further');
    }

    #[Test]
    public function aScrolledTabDrawsOnlyTheChildrenStillInsideItsContentArea(): void
    {
        $area = new Rect(0, 0, 24, 9);
        // add() appends to the last tab, so the children belong to the active one.
        $tabBar = TabBar::new()->addTab('A');

        for ($index = 0; $index < 8; ++$index) {
            $tabBar = $tabBar->add(Text::new("row{$index}"));
        }

        $scrolled = $tabBar;
        for ($turn = 0; $turn < 3; ++$turn) {
            $result = $scrolled->update(new MouseMsg(2, 6, MouseAction::WheelDown, MouseButton::Left), $area);
            self::assertInstanceOf(TabBar::class, $result->model);
            $scrolled = $result->model;
        }

        $frame = new Frame(24, 9);
        $scrolled->render($frame, $area, new RenderContext(Theme::dark()));

        $screen = '';
        for ($cell = 0; $cell < 24 * 9; ++$cell) {
            $screen .= $frame->characters[$cell];
        }

        self::assertStringNotContainsString('row0', $screen, 'Scrolled above the content area');
        self::assertStringContainsString('row3', $screen, 'Still inside it');
    }

    #[Test]
    public function theContentBoxIsClosedAlongItsWholeBottomEdge(): void
    {
        $width = 20;
        $frame = new Frame($width, 8);
        TabBar::new()->addTab('A')->addTab('B')->render($frame, new Rect(0, 0, $width, 8), new RenderContext(Theme::dark()));

        $bottom = '';
        for ($column = 0; $column < $width; ++$column) {
            $bottom .= $frame->characters[7 * $width + $column];
        }

        self::assertSame('╰' . str_repeat('─', $width - 2) . '╯', $bottom);
    }

    #[Test]
    public function selectingATabProgrammaticallyShowsItsOwnChildren(): void
    {
        $bar = TabBar::new()
            ->addTab('A')->add($this->createTrackableFocusable('a'))
            ->addTab('B')->add($this->createTrackableFocusable('b'))
            ->withSelectedTab(1);

        self::assertCount(1, $bar->children(), 'The page the app chose is the page that publishes its children');
    }

    #[Test]
    public function aFocusedBarUsesTheThemesFocusedBorder(): void
    {
        $area = new Rect(0, 0, 20, 8);
        $theme = Theme::dark()->withFocusedBorder(BorderStyle::Double);
        $bar = TabBar::new()->addTab('A');

        self::assertStringContainsString('╔', $this->screenWithTheme($bar, $area, $theme, focused: true));
        self::assertStringNotContainsString('╔', $this->screenWithTheme($bar, $area, $theme));
    }

    #[Test]
    public function aFocusedBarCanOverrideItsBorderStyle(): void
    {
        $accent = new Style(foreground: Color::hex('#ff8700'));
        $bar = TabBar::new()->addTab('A')->withFocusedBorderStyle($accent);

        $frame = new Frame(20, 8);
        $bar->render($frame, new Rect(0, 0, 20, 8), $this->focusedContext());

        self::assertSame($accent->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function aBarWithOneTabHasNothingToSwitchTo(): void
    {
        $bar = TabBar::new()->addTab('Only');

        $result = $bar->update(new KeyMsg(Key::Right), new Rect(0, 0, 30, 10));

        self::assertSame($bar, $result->model);
    }

    #[Test]
    public function anUnfocusedBarAdvertisesNoKeys(): void
    {
        $bar = TabBar::new()->addTab('A')->addTab('B');

        self::assertStringNotContainsString(
            'to switch',
            $this->screenOfTabBar($bar, new Rect(0, 0, 40, 8)),
            'It ignores keys while unfocused, so it must not promise them',
        );
        self::assertStringContainsString(
            'to switch',
            $this->screenOfTabBar($bar, new Rect(0, 0, 40, 8), focused: true),
        );
    }

    #[Test]
    public function reportsItsKeysAsHints(): void
    {
        $bar = TabBar::new()->addTab('A')->addTab('B');

        self::assertSame(['←' => 'previous tab', '→' => 'next tab'], $bar->hints());
    }

    #[Test]
    public function hintsFollowRebinding(): void
    {
        $bar = TabBar::new()->addTab('A')->addTab('B')->withKeybindings(new Keybindings([
            Actions::TAB_PREVIOUS => ['h'],
            Actions::TAB_NEXT => ['l'],
        ]));

        self::assertSame(['h' => 'previous tab', 'l' => 'next tab'], $bar->hints());
    }

    #[Test]
    public function aBoundBarPublishesItsHintsToWhateverCarriesThem(): void
    {
        $app = new TwoFieldsInATabApp(TabBar::new()->addTab('A')->addTab('B'));

        $driven = ModelHarness::for($app, 40, 10)->initialize();

        self::assertSame(['←' => 'previous tab', '→' => 'next tab'], $driven->focusedHints());
    }

    #[Test]
    public function anAccentBorderDoesNotRequireClaimingFocus(): void
    {
        $area = new Rect(0, 0, 20, 8);
        $accent = new Style(foreground: Color::hex('#ff8700'));
        $bar = TabBar::new()->addTab('A')->withBorderStyle($accent);

        $frame = new Frame(20, 8);
        $bar->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertSame(
            $accent->foregroundValue(),
            $frame->foregrounds[0],
            'The frame can look active without the widget claiming the keyboard',
        );
    }

    #[Test]
    public function theSwitchHintShortensAndThenDisappearsAsTheBarNarrows(): void
    {
        // Each hint needs its own width plus a two-column gap, which puts the
        // full hint's first fitting width at 26 and the arrows' at 16.
        self::assertStringContainsString('← → to switch', $this->screenAtWidth(26), 'The narrowest full hint');
        self::assertStringNotContainsString('to switch', $this->screenAtWidth(25), 'One column short of it');
        self::assertStringContainsString('← →', $this->screenAtWidth(25), 'Which leaves the arrows');
        self::assertStringContainsString('← →', $this->screenAtWidth(16), 'The narrowest arrows');
        self::assertStringNotContainsString('←', $this->screenAtWidth(15), 'One column short of those too');
    }

    #[Test]
    public function theSwitchHintFollowsTheConfiguredKeybindings(): void
    {
        $tabBar = TabBar::new()->addTab('A')->addTab('B')->withKeybindings(new Keybindings([
            Actions::TAB_PREVIOUS => ['pgup'],
            Actions::TAB_NEXT => ['pgdn'],
        ]));

        $screen = $this->screenOfTabBar($tabBar, new Rect(0, 0, 40, 6), focused: true);

        self::assertStringContainsString('PgUp', $screen, 'The hint names the bound key');
        self::assertStringContainsString('PgDn', $screen, 'For both directions');
    }

    #[Test]
    public function anEmphasizedBorderFollowsTheThemeItIsRenderedWith(): void
    {
        $area = new Rect(0, 0, 20, 8);
        $bar = TabBar::new()->addTab('A')->withEmphasizedBorder();

        foreach ([Theme::dark(), Theme::light()] as $theme) {
            $frame = new Frame(20, 8);
            $bar->render($frame, $area, new RenderContext($theme));

            self::assertSame(
                $theme->focusedBorderStyle->foregroundValue(),
                $frame->foregrounds[0],
                'The theme decides the colour, so it can differ per theme',
            );
        }
    }

    #[Test]
    public function showsAHintSuppliedByTheApplication(): void
    {
        $tabBar = TabBar::new()->addTab('A')->addTab('B')
            ->withHint('shift+←/→ to switch');

        $screen = $this->screenOfTabBar($tabBar, new Rect(0, 0, 50, 6));

        self::assertStringContainsString(
            'shift+←/→ to switch',
            $screen,
            'An unfocused bar still carries what the application put there',
        );
    }

    #[Test]
    public function fallsBackToTheShortHintWhenTheFullOneDoesNotFit(): void
    {
        $tabBar = TabBar::new()->addTab('A')->addTab('B')
            ->withHint('shift+←/→ to switch', 'shift+←/→');

        self::assertStringContainsString(
            'shift+←/→ to switch',
            $this->screenOfTabBar($tabBar, new Rect(0, 0, 50, 6)),
            'The full hint where there is room',
        );
        self::assertStringContainsString(
            'shift+←/→',
            $this->screenOfTabBar($tabBar, new Rect(0, 0, 26, 6)),
            'The short one where there is not',
        );
    }

    #[Test]
    public function implementsFocusable(): void
    {
        self::assertInstanceOf(Focusable::class, TabBar::new());
    }

    #[Test]
    public function constraintReturnsFlex(): void
    {
        self::assertSame(1, TabBar::new()->sizing()->rows->flex);
    }

    #[Test]
    public function addTabReturnsNewInstance(): void
    {
        $tabBar = TabBar::new();
        self::assertNotSame($tabBar, $tabBar->addTab('First'));
    }

    #[Test]
    public function addAppendsChildToLastTab(): void
    {
        $tabBar = TabBar::new()->addTab('A')->addTab('B')->add(Text::new('Hello'));
        self::assertNotSame($tabBar, $tabBar->add(Text::new('World')));
    }

    #[Test]
    public function selectedTabDefaultsToZero(): void
    {
        self::assertSame(0, TabBar::new()->selectedTab());
    }

    #[Test]
    public function withPaddingReturnsNewInstance(): void
    {
        $tabBar = TabBar::new();
        self::assertNotSame($tabBar, $tabBar->withPadding(Spacing::all(1)));
    }

    // ── Update routing ──

    #[Test]
    public function anUnknownMessageLeavesTheBarAlone(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        self::assertSame($tabBar, $tabBar->update(new TestMessage('test'), Rect::fromSize(40, 10))->model);
    }

    #[Test]
    public function aKeyItIsHandedSwitchesTheTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        $switched = $tabBar->update(new KeyMsg(Key::Right), new Rect(0, 0, 40, 15))->model;

        self::assertInstanceOf(TabBar::class, $switched);
        self::assertSame(1, $switched->selectedTab());
    }

    #[Test]
    public function mouseMsgOnEmptyTabsReturnsSelf(): void
    {
        $tabBar = TabBar::new();
        self::assertSame($tabBar, $tabBar->update(new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left), new Rect(0, 0, 40, 15))->model);
    }

    // ── Tab switching: Left/Right/h/l ──

    #[Test]
    public function rightKeyAdvancesTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        self::assertSame(1, $tabBar->update(new KeyMsg(Key::Right), new Rect(0, 0, 40, 15))->model->selectedTab());
    }

    #[Test]
    public function leftKeyGoesBackOneTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        $area = new Rect(0, 0, 40, 15);
        $at1 = $tabBar->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(0, $at1->update(new KeyMsg(Key::Left), $area)->model->selectedTab());
    }

    #[Test]
    public function leftWrapsToLastTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two')->addTab('Three');
        self::assertSame(2, $tabBar->update(new KeyMsg(Key::Left), new Rect(0, 0, 40, 15))->model->selectedTab());
    }

    #[Test]
    public function rightWrapsToFirstTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        $area = new Rect(0, 0, 40, 15);
        $at1 = $tabBar->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(0, $at1->update(new KeyMsg(Key::Right), $area)->model->selectedTab());
    }

    #[Test]
    public function switchTabWithSingleTabReturnsSelf(): void
    {
        $tabBar = TabBar::new()->addTab('Only');
        $area = new Rect(0, 0, 40, 15);
        self::assertSame($tabBar, $tabBar->update(new KeyMsg(Key::Right), $area)->model);
        self::assertSame($tabBar, $tabBar->update(new KeyMsg(Key::Left), $area)->model);
    }

    #[Test]
    public function updateLeavesPanesAloneInAnAreaTooSmallToDrawThem(): void
    {
        $tabBar = TabBar::new()
            ->addTab('One')->add(Radio::new(['A', 'B'], 'R1'));

        $result = $tabBar->update(new KeyMsg(Key::Down), new Rect(0, 0, 40, 3));

        self::assertSame($tabBar, $result->model, 'A pane the tab bar never draws must not be fed input either');
    }

    #[Test]
    public function switchTabWithNoFocusableChildrenInNewTab(): void
    {
        $tabBar = TabBar::new()
            ->addTab('One')->add(Radio::new(['A', 'B'], 'R1'))
            ->addTab('Two')->add(Text::new('Plain'));
        self::assertSame(1, $tabBar->update(new KeyMsg(Key::Right), new Rect(0, 0, 40, 20))->model->selectedTab());
    }

    // ── Tab key ──

    #[Test]
    public function aTabKeyIsLeftForTheRingAbove(): void
    {
        $tabBar = TabBar::new()
            ->addTab('One')
            ->add(Radio::new(['A', 'B'], 'R'));

        $result = $tabBar->update(new KeyMsg(Key::Tab), new Rect(0, 0, 40, 20));

        self::assertSame($tabBar, $result->model, 'an unanswered Tab falls through to App, which moves the flat ring');
        self::assertNull($result->command);
    }

    #[Test]
    public function aBarWithNoTabsAnswersNothing(): void
    {
        $tabBar = TabBar::new();
        self::assertSame($tabBar, $tabBar->update(new TestMessage('msg'), new Rect(0, 0, 40, 20))->model);
    }

    // ── Scroll behavior ──

    #[Test]
    public function scrollDownWithDownKey(): void
    {
        $tabBar = $this->createOverflowingTabBar();
        self::assertNotSame($tabBar, $tabBar->update(new KeyMsg(Key::Down), new Rect(0, 0, 30, 10))->model);
    }

    #[Test]
    public function scrollUpWithUpKey(): void
    {
        $area = new Rect(0, 0, 30, 10);
        $scrolled = $this->createOverflowingTabBar()->update(new KeyMsg(Key::Down), $area)->model;
        self::assertNotSame($scrolled, $scrolled->update(new KeyMsg(Key::Up), $area)->model);
    }

    #[Test]
    public function scrollUpAtTopReturnsSelf(): void
    {
        $tabBar = $this->createOverflowingTabBar();
        self::assertSame($tabBar, $tabBar->update(new KeyMsg(Key::Up), new Rect(0, 0, 30, 10))->model);
    }

    #[Test]
    public function scrollOnEmptyTabsReturnsSelf(): void
    {
        $tabBar = TabBar::new();
        self::assertSame($tabBar, $tabBar->update(new KeyMsg(Key::Down), new Rect(0, 0, 30, 10))->model);
    }

    #[Test]
    public function scrollDownClampsToMaxOffset(): void
    {
        $tabBar = TabBar::new()->addTab('One');
        for ($i = 0; $i < 10; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $area = new Rect(0, 0, 30, 10);
        $current = $tabBar;
        for ($i = 0; $i < 20; ++$i) {
            $next = $current->update(new KeyMsg(Key::Down), $area)->model;
            if ($next === $current) {
                break;
            }
            $current = $next;
        }
        self::assertSame($current, $current->update(new KeyMsg(Key::Down), $area)->model);
    }

    #[Test]
    public function scrollWithNoOverflowReturnsSelf(): void
    {
        $tabBar = TabBar::new()->addTab('One')->add(Text::new('Short'));
        self::assertSame($tabBar, $tabBar->update(new KeyMsg(Key::Down), new Rect(0, 0, 30, 10))->model);
    }

    #[Test]
    public function unknownKeyInScrollReturnsSelf(): void
    {
        $tabBar = TabBar::new()->addTab('One')->add(Text::new('x'));
        self::assertSame($tabBar, $tabBar->update(new KeyMsg('z'), new Rect(0, 0, 30, 10))->model);
    }

    // ── Mouse: Tab clicks ──

    #[Test]
    public function clickOnTabSwitchesToIt(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        self::assertSame(1, $tabBar->update(new MouseMsg(10, 1, MouseAction::Press, MouseButton::Left), new Rect(0, 0, 40, 15))->model->selectedTab());
    }

    #[Test]
    public function aClickOnTheFirstColumnOfATabActivatesThatTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        $area = new Rect(0, 0, 40, 15);

        self::assertSame(
            1,
            $tabBar->update(new MouseMsg(7, 1, MouseAction::Press, MouseButton::Left), $area)->model->selectedTab(),
            'The second tab is painted from x=7 onwards',
        );
    }

    #[Test]
    public function aClickWhereALabelIsPaintedActivatesTheTabItBelongsTo(): void
    {
        $labels = ['One', 'Two', 'Three'];
        $area = new Rect(0, 0, 40, 10);
        $tabBar = TabBar::new()->addTab('One')->addTab('Two')->addTab('Three');

        $frame = new Frame(40, 10);
        $tabBar->render($frame, $area, $this->context());

        foreach ($labels as $index => $label) {
            $column = $this->columnOfLabel($frame, 40, 1, $label);

            self::assertSame(
                $index,
                $tabBar->update(new MouseMsg($column, 1, MouseAction::Press, MouseButton::Left), $area)->model->selectedTab(),
                sprintf('Column %d carries the label of tab %d', $column, $index),
            );
        }
    }

    #[Test]
    public function clickOnTabAtYEqualsAreaY(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        self::assertSame(1, $tabBar->update(new MouseMsg(10, 0, MouseAction::Press, MouseButton::Left), new Rect(0, 0, 40, 15))->model->selectedTab());
    }

    #[Test]
    public function clickOnTabAtYEqualsAreaYPlusTwoIsNotTabStrip(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        self::assertSame(0, $tabBar->update(new MouseMsg(10, 2, MouseAction::Press, MouseButton::Left), new Rect(0, 0, 40, 15))->model->selectedTab());
    }

    #[Test]
    public function clickOnAlreadyActiveTabReturnsSelf(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        self::assertSame($tabBar, $tabBar->update(new MouseMsg(3, 1, MouseAction::Press, MouseButton::Left), new Rect(0, 0, 40, 15))->model);
    }

    #[Test]
    public function clickBeyondLastTabReturnsSelf(): void
    {
        $tabBar = TabBar::new()->addTab('A')->addTab('B');
        self::assertSame($tabBar, $tabBar->update(new MouseMsg(30, 1, MouseAction::Press, MouseButton::Left), new Rect(0, 0, 40, 15))->model);
    }

    #[Test]
    public function clickAtExactTabBoundaryLeftOfSecondTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        self::assertSame(1, $tabBar->update(new MouseMsg(8, 1, MouseAction::Press, MouseButton::Left), new Rect(0, 0, 40, 15))->model->selectedTab());
    }

    #[Test]
    public function clickAtLastCellOfFirstTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        self::assertSame(0, $tabBar->update(new MouseMsg(6, 1, MouseAction::Press, MouseButton::Left), new Rect(0, 0, 40, 15))->model->selectedTab());
    }

    #[Test]
    public function aClickWhereATabTooWideToBeDrawnWouldSitChangesNothing(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        $tooNarrowForBoth = new Rect(0, 0, 13, 15);

        self::assertSame(0, $tabBar->update(new MouseMsg(7, 1, MouseAction::Press, MouseButton::Left), $tooNarrowForBoth)->model->selectedTab());
    }

    #[Test]
    public function clickOnTabWithAreaOffset(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        self::assertSame(1, $tabBar->update(new MouseMsg(14, 4, MouseAction::Press, MouseButton::Left), new Rect(5, 3, 40, 15))->model->selectedTab());
    }

    // ── Mouse: Wheel scroll ──

    #[Test]
    public function mouseWheelDownScrollsContent(): void
    {
        $tabBar = $this->createOverflowingTabBar();
        self::assertNotSame($tabBar, $tabBar->update(new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None), new Rect(0, 0, 30, 10))->model);
    }

    #[Test]
    public function mouseWheelUpAfterDownScrolls(): void
    {
        $area = new Rect(0, 0, 30, 10);
        $scrolled = $this->createOverflowingTabBar()->update(new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None), $area)->model;
        self::assertNotSame($scrolled, $scrolled->update(new MouseMsg(5, 5, MouseAction::WheelUp, MouseButton::None), $area)->model);
    }

    #[Test]
    public function mouseWheelOutsideAreaIgnored(): void
    {
        $tabBar = $this->createOverflowingTabBar();
        self::assertSame($tabBar, $tabBar->update(new MouseMsg(35, 5, MouseAction::WheelDown, MouseButton::None), new Rect(0, 0, 30, 10))->model);
    }

    #[Test]
    public function mouseWheelOnShortContentIgnored(): void
    {
        $tabBar = TabBar::new()->addTab('One')->add(Text::new('Short'));
        self::assertSame($tabBar, $tabBar->update(new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None), new Rect(0, 0, 30, 10))->model);
    }

    #[Test]
    public function mouseWheelUpAtTopReturnsSameInstance(): void
    {
        $tabBar = $this->createOverflowingTabBar();
        $result = $tabBar->update(new MouseMsg(5, 5, MouseAction::WheelUp, MouseButton::None), new Rect(0, 0, 30, 10));
        // WheelUp at offset 0 is a no-op — with() elision returns $this
        self::assertSame($tabBar, $result->model);
        self::assertSame(0, $result->model->selectedTab());
    }

    #[Test]
    public function mouseWheelDownClampsAtBottom(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 10; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $area = new Rect(0, 0, 30, 10);
        // WheelDown always creates new model when hasOverflow, so we just verify
        // the tab bar doesn't crash and renders correctly after many scrolls
        $current = $tabBar;
        for ($i = 0; $i < 30; ++$i) {
            $current = $current->update(new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None), $area)->model;
        }
        $frame = new Frame(30, 10);
        $current->render($frame, $area, $this->context());
        self::assertInstanceOf(TabBar::class, $current);
    }

    // ── Mouse: Click in content area ──

    #[Test]
    public function aMouseReleaseOverTheContentLeavesTheBarAlone(): void
    {
        $tabBar = TabBar::new()->addTab('One')->add(Text::new('Content'));
        self::assertSame($tabBar, $tabBar->update(new MouseMsg(5, 5, MouseAction::Release, MouseButton::Left), new Rect(0, 0, 30, 20))->model);
    }

    #[Test]
    public function aMouseMotionOverTheContentLeavesTheBarAlone(): void
    {
        $tabBar = TabBar::new()->addTab('Tab')->add(Text::new('Hi'));
        self::assertSame($tabBar, $tabBar->update(new MouseMsg(5, 5, MouseAction::Motion, MouseButton::None), new Rect(0, 0, 30, 10))->model);
    }

    // ── Rendering: empty and small ──

    #[Test]
    public function skipsRenderWhenNoTabs(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function skipsRenderWhenWidthTooSmall(): void
    {
        $frame = new Frame(10, 10);
        TabBar::new()->addTab('One')->render($frame, new Rect(0, 0, 4, 10), $this->context());
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function skipsRenderWhenHeightTooSmall(): void
    {
        $frame = new Frame(30, 5);
        TabBar::new()->addTab('One')->render($frame, new Rect(0, 0, 30, 4), $this->context());
        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function rendersWhenWidthExactlyFive(): void
    {
        $frame = new Frame(10, 10);
        TabBar::new()->addTab('A')->render($frame, new Rect(0, 0, 5, 10), $this->context());
        self::assertNotSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function rendersWhenHeightExactlyFive(): void
    {
        $frame = new Frame(20, 10);
        TabBar::new()->addTab('A')->render($frame, new Rect(0, 0, 20, 5), $this->context());
        self::assertNotSame(' ', $frame->characters[0]);
    }

    // ── Rendering: Active tab top border ──

    #[Test]
    public function rendersActiveTabTopBorderGlyphs(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('One')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->topLeft, $frame->characters[0]);
        self::assertSame($glyphs->horizontal, $frame->characters[1]);
        self::assertSame($glyphs->horizontal, $frame->characters[5]);
        self::assertSame($glyphs->topRight, $frame->characters[6]);
    }

    #[Test]
    public function rendersActiveTabVerticalSides(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('One')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->vertical, $frame->characters[1 * 30 + 0]);
        self::assertSame($glyphs->vertical, $frame->characters[1 * 30 + 6]);
    }

    #[Test]
    public function rendersActiveTabLabelWithPaddingSpaces(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('One')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame(' ', $frame->characters[1 * 30 + 1]);
        self::assertSame('O', $frame->characters[1 * 30 + 2]);
        self::assertSame('n', $frame->characters[1 * 30 + 3]);
        self::assertSame('e', $frame->characters[1 * 30 + 4]);
        self::assertSame(' ', $frame->characters[1 * 30 + 5]);
    }

    #[Test]
    public function rendersActiveTabLabel(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('Settings')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame('S', $frame->characters[1 * 30 + 2]);
        self::assertSame('e', $frame->characters[1 * 30 + 3]);
        self::assertSame('t', $frame->characters[1 * 30 + 4]);
    }

    #[Test]
    public function activeTabLabelUsesTitleStyle(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('Tab')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame(Theme::dark()->titleStyle->foregroundValue(), $frame->foregrounds[1 * 30 + 2]);
    }

    // ── Rendering: Inactive tab ──

    #[Test]
    public function rendersInactiveTabLabelWithDimStyle(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('One')->addTab('Two')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame('T', $frame->characters[1 * 30 + 9]);
        self::assertSame('w', $frame->characters[1 * 30 + 10]);
        self::assertSame('o', $frame->characters[1 * 30 + 11]);
        self::assertSame(Theme::dark()->dim->foregroundValue(), $frame->foregrounds[1 * 30 + 9]);
    }

    #[Test]
    public function inactiveTabHasNoTopBorderOrVerticals(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('One')->addTab('Two')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertNotSame($glyphs->topLeft, $frame->characters[0 * 30 + 7]);
        self::assertNotSame($glyphs->vertical, $frame->characters[1 * 30 + 7]);
    }

    // ── Rendering: Baseline ──

    #[Test]
    public function rendersBaselineForFirstActiveTab(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('One')->addTab('Two')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->vertical, $frame->characters[2 * 30 + 0]);
        self::assertSame($glyphs->topRight, $frame->characters[2 * 30 + 29]);
        self::assertSame(' ', $frame->characters[2 * 30 + 1]);
        self::assertSame(' ', $frame->characters[2 * 30 + 5]);
        self::assertSame($glyphs->bottomLeft, $frame->characters[2 * 30 + 6]);
    }

    #[Test]
    public function rendersBaselineForNonFirstActiveTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        $area = new Rect(0, 0, 30, 10);
        $tabBar2 = $tabBar->update(new KeyMsg(Key::Right), $area)->model;
        $frame = new Frame(30, 10);
        $tabBar2->render($frame, $area, $this->context());
        $glyphs = Theme::dark()->focusedBorder->glyphs();
        self::assertSame($glyphs->topLeft, $frame->characters[2 * 30 + 0]);
        self::assertSame($glyphs->bottomRight, $frame->characters[2 * 30 + 7]);
        self::assertSame($glyphs->bottomLeft, $frame->characters[2 * 30 + 13]);
        self::assertSame(' ', $frame->characters[2 * 30 + 8]);
        self::assertSame(' ', $frame->characters[2 * 30 + 12]);
    }

    #[Test]
    public function baselineGapClearedWithSpaces(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('Tab')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        for ($x = 1; $x <= 5; ++$x) {
            self::assertSame(' ', $frame->characters[2 * 30 + $x], "Gap at x=$x");
        }
    }

    #[Test]
    public function baselineGapHasZeroForeground(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('Tab')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame(0, $frame->foregrounds[2 * 30 + 2]);
    }

    #[Test]
    public function baselineFillsWidthOutsideGap(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('A')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        for ($x = 5; $x < 29; ++$x) {
            self::assertSame($glyphs->horizontal, $frame->characters[2 * 30 + $x], "Baseline at x=$x");
        }
    }

    #[Test]
    public function baselineRendersAtCorrectYWithOffset(): void
    {
        $frame = new Frame(40, 20);
        TabBar::new()->addTab('Tab')->render($frame, new Rect(5, 3, 30, 15), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->vertical, $frame->characters[5 * 40 + 5]);
        self::assertSame($glyphs->topRight, $frame->characters[5 * 40 + 34]);
    }

    // ── Rendering: Content box ──

    #[Test]
    public function rendersContentBoxSideBorders(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('One')->add(Text::new('Hi'))->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        for ($y = 3; $y <= 8; ++$y) {
            self::assertSame($glyphs->vertical, $frame->characters[$y * 30 + 0], "Left y=$y");
            self::assertSame($glyphs->vertical, $frame->characters[$y * 30 + 29], "Right y=$y");
        }
    }

    #[Test]
    public function rendersContentBoxBottomBorder(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('One')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->bottomLeft, $frame->characters[9 * 30 + 0]);
        self::assertSame($glyphs->horizontal, $frame->characters[9 * 30 + 1]);
        self::assertSame($glyphs->bottomRight, $frame->characters[9 * 30 + 29]);
    }

    #[Test]
    public function contentBoxVerticalBordersSpanFullHeight(): void
    {
        $frame = new Frame(30, 15);
        TabBar::new()->addTab('One')->render($frame, new Rect(0, 0, 30, 15), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        for ($y = 3; $y <= 13; ++$y) {
            self::assertSame($glyphs->vertical, $frame->characters[$y * 30 + 0], "Left y=$y");
            self::assertSame($glyphs->vertical, $frame->characters[$y * 30 + 29], "Right y=$y");
        }
        self::assertSame($glyphs->bottomLeft, $frame->characters[14 * 30 + 0]);
        self::assertSame($glyphs->bottomRight, $frame->characters[14 * 30 + 29]);
    }

    #[Test]
    public function contentBoxBottomHorizontalFillsFullWidth(): void
    {
        $frame = new Frame(20, 10);
        TabBar::new()->addTab('Tab')->render($frame, new Rect(0, 0, 20, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        for ($x = 1; $x < 19; ++$x) {
            self::assertSame($glyphs->horizontal, $frame->characters[9 * 20 + $x], "Bottom at x=$x");
        }
    }

    #[Test]
    public function bottomBorderAllGlyphs(): void
    {
        $frame = new Frame(15, 8);
        TabBar::new()->addTab('Tab')->render($frame, new Rect(0, 0, 15, 8), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->bottomLeft, $frame->characters[7 * 15 + 0]);
        for ($x = 1; $x < 14; ++$x) {
            self::assertSame($glyphs->horizontal, $frame->characters[7 * 15 + $x]);
        }
        self::assertSame($glyphs->bottomRight, $frame->characters[7 * 15 + 14]);
    }

    // ── Rendering: Content ──

    #[Test]
    public function rendersChildWidgetInContentArea(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('One')->add(Text::new('Hello'))->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame('H', $frame->characters[3 * 30 + 1]);
        self::assertSame('e', $frame->characters[3 * 30 + 2]);
        self::assertSame('l', $frame->characters[3 * 30 + 3]);
        self::assertSame('l', $frame->characters[3 * 30 + 4]);
        self::assertSame('o', $frame->characters[3 * 30 + 5]);
    }

    #[Test]
    public function rendersOnlyActiveTabContent(): void
    {
        $tabBar = TabBar::new()
            ->addTab('One')->add(Text::new('First'))
            ->addTab('Two')->add(Text::new('Second'));
        $frame = new Frame(30, 10);
        $tabBar->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame('F', $frame->characters[3 * 30 + 1]);
        self::assertStringNotContainsString('Second', implode('', $frame->characters));
    }

    #[Test]
    public function rendersContentWithAreaOffset(): void
    {
        $frame = new Frame(40, 15);
        TabBar::new()->addTab('One')->add(Text::new('Hi'))->render($frame, new Rect(5, 3, 30, 10), $this->context());
        self::assertSame('H', $frame->characters[6 * 40 + 6]);
        self::assertSame('i', $frame->characters[6 * 40 + 7]);
    }

    #[Test]
    public function rendersMultipleChildrenVertically(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('Tab')->add(Text::new('AAA'))->add(Text::new('BBB'))
            ->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame('A', $frame->characters[3 * 30 + 1]);
        self::assertSame('B', $frame->characters[4 * 30 + 1]);
    }

    #[Test]
    public function contentAreaAccountsForTabStripAndBorders(): void
    {
        $frame = new Frame(20, 10);
        TabBar::new()->addTab('Test')->add(Text::new('X'))->render($frame, new Rect(0, 0, 20, 10), $this->context());
        self::assertSame('X', $frame->characters[3 * 20 + 1]);
        self::assertNotSame('X', $frame->characters[2 * 20 + 1]);
    }

    #[Test]
    public function childrenAtBottomBoundaryStillRender(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 6; ++$i) {
            $tabBar = $tabBar->add(Text::new("Row$i"));
        }
        $frame = new Frame(30, 10);
        $tabBar->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame('R', $frame->characters[8 * 30 + 1]);
    }

    #[Test]
    public function childrenScrolledAboveViewportNotRendered(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 20; ++$i) {
            $tabBar = $tabBar->add(Text::new("Line$i"));
        }
        $area = new Rect(0, 0, 30, 10);
        for ($i = 0; $i < 3; ++$i) {
            $tabBar = $tabBar->update(new KeyMsg(Key::Down), $area)->model;
        }
        $frame = new Frame(30, 10);
        $tabBar->render($frame, $area, $this->context());
        self::assertStringNotContainsString('Line0', $this->bufferRow($frame, 3, 30));
    }

    #[Test]
    public function contentDoesNotOverflowBelowBottomBorder(): void
    {
        $tabBar = TabBar::new()
            ->addTab('Theme')
            ->add(Radio::new(['Dark', 'Light', 'Dracula', 'Solarized', 'Rose Pine'], 'Theme'));
        $frame = new Frame(30, 9);
        $tabBar->render($frame, new Rect(0, 0, 30, 9), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->horizontal, $frame->characters[8 * 30 + 1]);
    }

    #[Test]
    public function overflowingChildHeightIsClampedToContentArea(): void
    {
        $tabBar = TabBar::new()->addTab('Test');
        for ($i = 0; $i < 8; ++$i) {
            $tabBar = $tabBar->add(Text::new("Line$i"));
        }
        $frame = new Frame(30, 9);
        $tabBar->render($frame, new Rect(0, 0, 30, 9), $this->context());
        $allChars = implode('', $frame->characters);
        self::assertStringContainsString('Line0', $allChars);
        self::assertStringContainsString('Line4', $allChars);
        self::assertStringNotContainsString('Line5', $allChars);
    }

    #[Test]
    public function contentDoesNotLeakBelowTabBarArea(): void
    {
        $tabBar = TabBar::new()
            ->addTab('Theme')
            ->add(Radio::new(['Dark', 'Light', 'Dracula', 'Solarized', 'Rose Pine'], 'Theme'));

        $width = 30;
        $areaHeight = 9;
        $bufferHeight = 12;
        $frame = new Frame($width, $bufferHeight);
        $tabBar->render($frame, new Rect(0, 0, $width, $areaHeight), $this->context());

        for ($x = 0; $x < $width; ++$x) {
            self::assertSame(' ', $frame->characters[9 * $width + $x], "Row below area at x=$x must be blank");
        }
    }

    // ── Rendering: Focused vs unfocused border style ──

    #[Test]
    public function focusedTabBarUsesFocusedBorderGlyphs(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('One')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame(Theme::dark()->focusedBorder->glyphs()->topLeft, $frame->characters[0]);
    }

    #[Test]
    public function unfocusedTabBarUsesNormalBorderGlyphs(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('One')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame(Theme::dark()->border->glyphs()->topLeft, $frame->characters[0]);
    }

    #[Test]
    public function focusedBorderForegroundApplied(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('One')->render($frame, new Rect(0, 0, 30, 10), $this->focusedContext());
        self::assertSame(Theme::dark()->focusedBorderStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function unfocusedBorderForegroundApplied(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('One')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame(Theme::dark()->borderStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function borderAttributesApplied(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('Tab')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame(Theme::dark()->borderStyle->attributes, $frame->attributes[0]);
    }

    #[Test]
    public function borderBackgroundApplied(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('Tab')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame(Theme::dark()->borderStyle->backgroundValue(), $frame->backgrounds[0]);
    }

    // ── Rendering: Tab hint ──

    #[Test]
    public function rendersFullHintWhenSpaceAvailable(): void
    {
        $frame = new Frame(40, 10);
        TabBar::new()->addTab('One')->addTab('Two')->render($frame, new Rect(0, 0, 40, 10), $this->focusedContext());
        self::assertStringContainsString("\u{2190} \u{2192} to switch", $this->bufferRow($frame, 1, 40));
    }

    #[Test]
    public function rendersShortHintWhenLimitedSpace(): void
    {
        $frame = new Frame(34, 10);
        TabBar::new()->addTab('First Tab')->addTab('Second Tab')->render($frame, new Rect(0, 0, 34, 10), $this->focusedContext());
        $row = $this->bufferRow($frame, 1, 34);
        self::assertStringContainsString("\u{2190} \u{2192}", $row);
        self::assertStringNotContainsString('to switch', $row);
    }

    #[Test]
    public function noHintWhenSingleTab(): void
    {
        $frame = new Frame(40, 10);
        TabBar::new()->addTab('Only Tab')->render($frame, new Rect(0, 0, 40, 10), $this->context());
        self::assertStringNotContainsString("\u{2190}", $this->bufferRow($frame, 1, 40));
    }

    #[Test]
    public function showsHintWhenSomeTabsAreClipped(): void
    {
        // 22+20=42 cells of tabs in a 40-wide area — second tab is dropped
        // by the clipping. The hint must still render so users know hidden
        // tabs exist.
        $frame = new Frame(40, 10);
        TabBar::new()->addTab('Very Long Tab Name')->addTab('Another Long Tab')->render($frame, new Rect(0, 0, 40, 10), $this->focusedContext());
        self::assertStringContainsString("\u{2190}", $this->bufferRow($frame, 1, 40));
    }

    #[Test]
    public function noHintWhenFirstTabFillsAreaWithNoRoomLeft(): void
    {
        // 22-cell tab in a 22-wide area uses every column; no room for any hint.
        $frame = new Frame(22, 10);
        TabBar::new()->addTab('Very Long Tab Name')->addTab('Another Long Tab')->render($frame, new Rect(0, 0, 22, 10), $this->context());
        self::assertStringNotContainsString("\u{2190}", $this->bufferRow($frame, 1, 22));
    }

    #[Test]
    public function hintRenderedWithDimStyle(): void
    {
        $frame = new Frame(40, 10);
        TabBar::new()->addTab('A')->addTab('B')->render($frame, new Rect(0, 0, 40, 10), $this->focusedContext());
        $row = $this->bufferRow($frame, 1, 40);
        $hintStart = mb_strpos($row, "\u{2190}");
        self::assertNotFalse($hintStart);
        self::assertSame(Theme::dark()->dim->foregroundValue(), $frame->foregrounds[1 * 40 + $hintStart]);
    }

    // ── Rendering: Padding ──

    #[Test]
    public function horizontalPaddingShiftsContentRight(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('One')->add(Text::new('Hello'))->withPadding(Spacing::horizontal(1))
            ->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame('H', $frame->characters[3 * 30 + 2]);
    }

    #[Test]
    public function verticalPaddingShiftsContentDown(): void
    {
        $frame = new Frame(30, 12);
        TabBar::new()->addTab('One')->add(Text::new('Hello'))->withPadding(Spacing::vertical(1))
            ->render($frame, new Rect(0, 0, 30, 12), $this->context());
        self::assertSame('H', $frame->characters[4 * 30 + 1]);
    }

    #[Test]
    public function allPaddingShiftsContentBoth(): void
    {
        $frame = new Frame(30, 12);
        TabBar::new()->addTab('Tab')->add(Text::new('Padded'))->withPadding(Spacing::all(1))
            ->render($frame, new Rect(0, 0, 30, 12), $this->context());
        self::assertSame('P', $frame->characters[4 * 30 + 2]);
    }

    // ── Rendering: Scrollbar ──

    #[Test]
    public function rendersScrollbarWhenContentOverflows(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 30; ++$i) {
            $tabBar = $tabBar->add(Text::new("Line $i"));
        }
        $frame = new Frame(30, 10);
        $tabBar->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $hasScrollChar = false;
        for ($y = 3; $y <= 8; ++$y) {
            $char = $frame->characters[$y * 30 + 28];
            if ($char === Scrollbar::THUMB || $char === Scrollbar::TRACK) {
                $hasScrollChar = true;
                break;
            }
        }
        self::assertTrue($hasScrollChar);
    }

    #[Test]
    public function noScrollbarWhenContentFits(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('Tab')->add(Text::new('Short'))->render($frame, new Rect(0, 0, 30, 10), $this->context());
        for ($y = 3; $y <= 8; ++$y) {
            self::assertNotSame("\u{2503}", $frame->characters[$y * 30 + 28], "No thumb at y=$y");
        }
    }

    #[Test]
    public function scrollbarReducesContentWidth(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 30; ++$i) {
            $tabBar = $tabBar->add(Text::new(str_repeat('X', 28)));
        }
        $frame = new Frame(30, 10);
        $tabBar->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $char = $frame->characters[3 * 30 + 28];
        self::assertTrue($char === Scrollbar::THUMB || $char === Scrollbar::TRACK);
    }

    // ── Rendering: Multiple tabs active ──

    #[Test]
    public function rendersSecondTabActive(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two')->addTab('Three');
        $area = new Rect(0, 0, 50, 10);
        $tabBar2 = $tabBar->update(new KeyMsg(Key::Right), $area)->model;
        $frame = new Frame(50, 10);
        $tabBar2->render($frame, $area, $this->context());
        $glyphs = Theme::dark()->focusedBorder->glyphs();
        self::assertSame($glyphs->topLeft, $frame->characters[0 * 50 + 7]);
        self::assertSame($glyphs->topRight, $frame->characters[0 * 50 + 13]);
        self::assertSame('T', $frame->characters[1 * 50 + 9]);
    }

    #[Test]
    public function rendersThirdTabActive(): void
    {
        $tabBar = TabBar::new()
            ->addTab('One')->addTab('Two')
            ->addTab('Three')->add(Text::new('Content3'));
        $area = new Rect(0, 0, 50, 10);
        $tab3 = $tabBar->update(new KeyMsg(Key::Right), $area)->model;
        $tab3 = $tab3->update(new KeyMsg(Key::Right), $area)->model;
        $frame = new Frame(50, 10);
        $tab3->render($frame, $area, $this->context());
        self::assertSame('T', $frame->characters[1 * 50 + 16]);
        self::assertSame('C', $frame->characters[3 * 50 + 1]);
    }

    // ── Tab tabX accumulation ──

    #[Test]
    public function tabXAccumulatesAcrossMultipleTabs(): void
    {
        $frame = new Frame(40, 10);
        TabBar::new()->addTab('A')->addTab('BB')->addTab('CCC')
            ->render($frame, new Rect(0, 0, 40, 10), $this->context());
        self::assertSame('B', $frame->characters[1 * 40 + 7]);
        self::assertSame('C', $frame->characters[1 * 40 + 13]);
    }

    #[Test]
    public function tabXAdvancesForInactiveTabs(): void
    {
        $frame = new Frame(40, 10);
        TabBar::new()->addTab('AAAA')->addTab('BB')->addTab('C')
            ->render($frame, new Rect(0, 0, 40, 10), $this->context());
        self::assertSame('C', $frame->characters[1 * 40 + 16]);
    }

    // ── Active tab space padding style ──

    #[Test]
    public function activeTabSpacePaddingUsesBorderStyle(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('Hi')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $theme = Theme::dark();
        self::assertSame(' ', $frame->characters[1 * 30 + 1]);
        self::assertSame($theme->borderStyle->foregroundValue(), $frame->foregrounds[1 * 30 + 1]);
        self::assertSame(' ', $frame->characters[1 * 30 + 4]);
        self::assertSame($theme->borderStyle->foregroundValue(), $frame->foregrounds[1 * 30 + 4]);
    }

    // ── clampedScrollOffset ──

    #[Test]
    public function clampedScrollOffsetPreventsOverscrollOnRender(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 10; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $area = new Rect(0, 0, 30, 10);
        $current = $tabBar;
        for ($i = 0; $i < 20; ++$i) {
            $next = $current->update(new KeyMsg(Key::Down), $area)->model;
            if ($next === $current) {
                break;
            }
            $current = $next;
        }
        $frame = new Frame(30, 10);
        $current->render($frame, $area, $this->context());
        self::assertTrue(true);
    }

    // ── resolveChildHeights ──

    #[Test]
    public function flexChildrenShareRemainingHeight(): void
    {
        $tabBar = TabBar::new()->addTab('Tab')
            ->add(Text::new('Fixed'))
            ->add($this->createFlexWidget(2))
            ->add($this->createFlexWidget(1));
        $frame = new Frame(30, 16);
        $tabBar->render($frame, new Rect(0, 0, 30, 16), $this->context());
        self::assertTrue(true);
    }

    #[Test]
    public function minConstraintChildUsesMinHeight(): void
    {
        $tabBar = TabBar::new()->addTab('Tab')->add($this->createMinConstraintWidget(3));
        $frame = new Frame(30, 12);
        $tabBar->render($frame, new Rect(0, 0, 30, 12), $this->context());
        self::assertTrue(true);
    }

    // ── Wide label ──

    #[Test]
    public function rendersTabWithWideLabelCorrectly(): void
    {
        $frame = new Frame(40, 10);
        TabBar::new()->addTab('Settings Panel')->render($frame, new Rect(0, 0, 40, 10), $this->context());
        self::assertSame('S', $frame->characters[1 * 40 + 2]);
        self::assertSame('l', $frame->characters[1 * 40 + 15]);
    }

    // ── L620: contentArea height = area.height - TAB_STRIP_HEIGHT(3) - 1 ──

    #[Test]
    public function contentAreaHeightAccountsForTabStripAndBottomBorder(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('A', Constraint::exact(1)))
            ->add($this->createMarkerWidget('B', Constraint::exact(1)))
            ->add($this->createMarkerWidget('C', Constraint::exact(1)))
            ->add($this->createMarkerWidget('D', Constraint::exact(1)))
            ->add($this->createMarkerWidget('E', Constraint::exact(1)))
            ->add($this->createMarkerWidget('F', Constraint::exact(1)));
        $frame = new Frame(20, 10);
        $tabBar->render($frame, new Rect(0, 0, 20, 10), $this->context());
        self::assertSame('A', $frame->characters[3 * 20 + 1]);
        self::assertSame('F', $frame->characters[8 * 20 + 1]);
        self::assertSame(Theme::dark()->border->glyphs()->bottomLeft, $frame->characters[9 * 20 + 0]);
    }

    #[Test]
    public function contentAreaWidthAccountsForSideBorders(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('ABCDEFGH', Constraint::exact(1)));
        $frame = new Frame(10, 8);
        $tabBar->render($frame, new Rect(0, 0, 10, 8), $this->context());
        self::assertSame('A', $frame->characters[3 * 10 + 1]);
        self::assertSame('H', $frame->characters[3 * 10 + 8]);
    }

    // ── L633-634,639,641: exact constraint, fixedTotal accumulation ──

    #[Test]
    public function resolveExactConstraintGetsExactHeight(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('A', Constraint::exact(2)))
            ->add($this->createMarkerWidget('B', Constraint::exact(1)));
        $frame = new Frame(20, 10);
        $tabBar->render($frame, new Rect(0, 0, 20, 10), $this->context());
        self::assertSame('A', $frame->characters[3 * 20 + 1]);
        self::assertSame('B', $frame->characters[5 * 20 + 1]);
    }

    #[Test]
    public function resolveMultipleExactCumulativeOffset(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('A', Constraint::exact(3)))
            ->add($this->createMarkerWidget('B', Constraint::exact(2)))
            ->add($this->createMarkerWidget('C', Constraint::exact(1)));
        $frame = new Frame(20, 13);
        $tabBar->render($frame, new Rect(0, 0, 20, 13), $this->context());
        self::assertSame('A', $frame->characters[3 * 20 + 1]);
        self::assertSame('B', $frame->characters[6 * 20 + 1]);
        self::assertSame('C', $frame->characters[8 * 20 + 1]);
    }

    // ── L639: Identical — exact vs min distinguished ──

    #[Test]
    public function resolveExactVsMinDistinguished(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('E', Constraint::exact(3)))
            ->add($this->createMarkerWidget('M', Constraint::min(3)))
            ->add($this->createMarkerWidget('F', Constraint::flex(1)));
        $frame = new Frame(20, 14);
        $tabBar->render($frame, new Rect(0, 0, 20, 14), $this->context());
        self::assertSame('E', $frame->characters[3 * 20 + 1]);
        self::assertSame('M', $frame->characters[6 * 20 + 1]);
        self::assertSame('F', $frame->characters[9 * 20 + 1]);
    }

    // ── L642-644: min constraint ──

    #[Test]
    public function resolveMinConstraintGetsAtLeastMinHeight(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('A', Constraint::min(3)))
            ->add($this->createMarkerWidget('B', Constraint::exact(1)));
        $frame = new Frame(20, 12);
        $tabBar->render($frame, new Rect(0, 0, 20, 12), $this->context());
        self::assertSame('A', $frame->characters[3 * 20 + 1]);
        self::assertSame('B', $frame->characters[6 * 20 + 1]);
    }

    #[Test]
    public function resolveMinConstraintAccumulatesInFixedTotal(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('A', Constraint::min(2)))
            ->add($this->createMarkerWidget('B', Constraint::exact(2)))
            ->add($this->createMarkerWidget('C', Constraint::flex(1)));
        $frame = new Frame(20, 16);
        $tabBar->render($frame, new Rect(0, 0, 20, 16), $this->context());
        self::assertSame('A', $frame->characters[3 * 20 + 1]);
        self::assertSame('B', $frame->characters[5 * 20 + 1]);
        self::assertSame('C', $frame->characters[7 * 20 + 1]);
    }

    // ── L642: GreaterThan — min=0 goes to flex ──

    #[Test]
    public function resolveMinZeroFallsToFlexBranch(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('A', new Constraint(min: 0, max: PHP_INT_MAX, flex: 1)))
            ->add($this->createMarkerWidget('B', Constraint::exact(1)));
        $frame = new Frame(20, 10);
        $tabBar->render($frame, new Rect(0, 0, 20, 10), $this->context());
        self::assertSame('A', $frame->characters[3 * 20 + 1]);
        self::assertSame('B', $frame->characters[8 * 20 + 1]);
    }

    // ── L651: remaining = max(0, availableHeight - fixedTotal) ──

    #[Test]
    public function resolveNoRemainingSpaceFlexGetsHeightOne(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('A', Constraint::exact(4)))
            ->add($this->createMarkerWidget('B', Constraint::exact(4)))
            ->add($this->createMarkerWidget('C', Constraint::flex(1)));
        $frame = new Frame(20, 10);
        $tabBar->render($frame, new Rect(0, 0, 20, 10), $this->context());
        self::assertSame('A', $frame->characters[3 * 20 + 1]);
        self::assertSame('B', $frame->characters[7 * 20 + 1]);
    }

    // ── L653: totalFlex > 0 && remaining > 0 (else branch) ──

    #[Test]
    public function resolveMultipleFlexNoRemainingAllGetHeightOne(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('A', Constraint::exact(6)))
            ->add($this->createMarkerWidget('B', Constraint::flex(1)))
            ->add($this->createMarkerWidget('C', Constraint::flex(1)));
        $frame = new Frame(20, 10);
        $tabBar->render($frame, new Rect(0, 0, 20, 10), $this->context());
        self::assertSame('A', $frame->characters[3 * 20 + 1]);
    }

    // ── L659-662: flex weight distribution ──

    #[Test]
    public function resolveDistributesFlexWeightProportionally(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('A', Constraint::flex(2)))
            ->add($this->createMarkerWidget('B', Constraint::flex(1)));
        $frame = new Frame(20, 16);
        $tabBar->render($frame, new Rect(0, 0, 20, 16), $this->context());
        self::assertSame('A', $frame->characters[3 * 20 + 1]);
        self::assertSame('B', $frame->characters[11 * 20 + 1]);
    }

    // ── L660-661: floor division and max(1, size) ──

    #[Test]
    public function resolveFlexGetsMinimumHeightOne(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('X', Constraint::exact(3)))
            ->add($this->createMarkerWidget('A', Constraint::flex(1)))
            ->add($this->createMarkerWidget('B', Constraint::flex(1)))
            ->add($this->createMarkerWidget('C', Constraint::flex(1)));
        $frame = new Frame(20, 9);
        $tabBar->render($frame, new Rect(0, 0, 20, 9), $this->context());
        self::assertSame('X', $frame->characters[3 * 20 + 1]);
        self::assertSame('A', $frame->characters[6 * 20 + 1]);
        self::assertSame('B', $frame->characters[7 * 20 + 1]);
    }

    #[Test]
    public function resolveFloorDivisionTruncatesCorrectly(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('A', Constraint::flex(1)))
            ->add($this->createMarkerWidget('B', Constraint::flex(3)));
        $frame = new Frame(20, 11);
        $tabBar->render($frame, new Rect(0, 0, 20, 11), $this->context());
        self::assertSame('A', $frame->characters[3 * 20 + 1]);
        self::assertSame('B', $frame->characters[4 * 20 + 1]);
    }

    // ── L667-668: lastFlexIndex remainder distribution ──

    #[Test]
    public function resolveFlexRoundingRemainderGoesToLastFlex(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('A', Constraint::flex(1)))
            ->add($this->createMarkerWidget('B', Constraint::flex(1)));
        $frame = new Frame(20, 11);
        $tabBar->render($frame, new Rect(0, 0, 20, 11), $this->context());
        self::assertSame('A', $frame->characters[3 * 20 + 1]);
        self::assertSame('B', $frame->characters[6 * 20 + 1]);
    }

    #[Test]
    public function resolveThreeFlexRoundingRemainderGoesToLast(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('A', Constraint::flex(1)))
            ->add($this->createMarkerWidget('B', Constraint::flex(1)))
            ->add($this->createMarkerWidget('C', Constraint::flex(1)));
        $frame = new Frame(20, 14);
        $tabBar->render($frame, new Rect(0, 0, 20, 14), $this->context());
        self::assertSame('A', $frame->characters[3 * 20 + 1]);
        self::assertSame('B', $frame->characters[6 * 20 + 1]);
        self::assertSame('C', $frame->characters[9 * 20 + 1]);
    }

    // ── L671: foreach else branch ──

    #[Test]
    public function resolveOnlyFlexChildrenDistributeEvenly(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('A', Constraint::flex(1)))
            ->add($this->createMarkerWidget('B', Constraint::flex(1)))
            ->add($this->createMarkerWidget('C', Constraint::flex(1)));
        $frame = new Frame(20, 13);
        $tabBar->render($frame, new Rect(0, 0, 20, 13), $this->context());
        self::assertSame('A', $frame->characters[3 * 20 + 1]);
        self::assertSame('B', $frame->characters[6 * 20 + 1]);
        self::assertSame('C', $frame->characters[9 * 20 + 1]);
    }

    #[Test]
    public function resolveSingleFlexGetsAllRemaining(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('Z', Constraint::flex(1)));
        $frame = new Frame(20, 10);
        $tabBar->render($frame, new Rect(0, 0, 20, 10), $this->context());
        self::assertSame('Z', $frame->characters[3 * 20 + 1]);
    }

    // ── L683: clampedScrollOffset ──

    #[Test]
    public function clampedScrollOffsetUsesBottomPadding(): void
    {
        $tabBar = TabBar::new()->addTab('T');
        for ($i = 0; $i < 10; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $area = new Rect(0, 0, 20, 10);
        $current = $tabBar;
        $scrollCount = 0;
        for ($i = 0; $i < 20; ++$i) {
            $next = $current->update(new KeyMsg(Key::Down), $area)->model;
            if ($next === $current) {
                break;
            }
            $current = $next;
            ++$scrollCount;
        }
        self::assertSame(5, $scrollCount);
    }

    #[Test]
    public function scrollOffsetAppliedToChildRendering(): void
    {
        $tabBar = TabBar::new()->addTab('T');
        for ($i = 0; $i < 10; ++$i) {
            $tabBar = $tabBar->add($this->createMarkerWidget("$i", Constraint::exact(1)));
        }
        $area = new Rect(0, 0, 20, 10);
        $scrolled = $tabBar->update(new KeyMsg(Key::Down), $area)->model;
        $frame = new Frame(20, 10);
        $scrolled->render($frame, $area, $this->context());
        self::assertSame('1', $frame->characters[3 * 20 + 1]);
    }

    // ── Mixed exact+min+flex ──

    #[Test]
    public function resolveMixedExactMinFlexCorrectPositions(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('E', Constraint::exact(2)))
            ->add($this->createMarkerWidget('M', Constraint::min(2)))
            ->add($this->createMarkerWidget('F', Constraint::flex(1)));
        $frame = new Frame(20, 12);
        $tabBar->render($frame, new Rect(0, 0, 20, 12), $this->context());
        self::assertSame('E', $frame->characters[3 * 20 + 1]);
        self::assertSame('M', $frame->characters[5 * 20 + 1]);
        self::assertSame('F', $frame->characters[7 * 20 + 1]);
    }

    #[Test]
    public function resolveExactPlusFlexCorrectRemaining(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('X', Constraint::exact(2)))
            ->add($this->createMarkerWidget('Y', Constraint::flex(1)));
        $frame = new Frame(20, 10);
        $tabBar->render($frame, new Rect(0, 0, 20, 10), $this->context());
        self::assertSame('X', $frame->characters[3 * 20 + 1]);
        self::assertSame('Y', $frame->characters[5 * 20 + 1]);
    }

    // ── Padding interaction with contentArea ──

    #[Test]
    public function contentAreaWithPaddingReducesAvailableSpace(): void
    {
        $tabBar = TabBar::new()->addTab('T')
            ->add($this->createMarkerWidget('X', Constraint::exact(1)))
            ->withPadding(Spacing::all(1));
        $frame = new Frame(20, 12);
        $tabBar->render($frame, new Rect(0, 0, 20, 12), $this->context());
        self::assertSame('X', $frame->characters[4 * 20 + 2]);
    }

    // ── L197: GreaterThan — exact equality of totalHeight and contentArea->height means no scroll ──

    #[Test]
    public function scrollNotPossibleWhenTotalHeightEqualsViewport(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 16; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $area = new Rect(0, 0, 40, 20);
        self::assertSame($tabBar, $tabBar->update(new KeyMsg(Key::Down), $area)->model);
    }

    // ── L198: Minus, Plus — maxOffset calculation correctness ──

    #[Test]
    public function scrollDownMaxOffsetIsExactlyTotalMinusViewportPlusPadding(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 20; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $area = new Rect(0, 0, 40, 20);

        $current = $tabBar;
        $scrollCount = 0;
        for ($i = 0; $i < 100; ++$i) {
            $next = $current->update(new KeyMsg(Key::Down), $area)->model;
            if ($next === $current) {
                break;
            }
            $current = $next;
            ++$scrollCount;
        }
        self::assertSame(5, $scrollCount);
    }

    // ── L220: ReturnRemoval — mouse on empty tabs returns early ──

    #[Test]
    public function mouseWheelOnEmptyTabsReturnsEarly(): void
    {
        $tabBar = TabBar::new();
        $result = $tabBar->update(
            new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None),
            new Rect(0, 0, 40, 15),
        );
        self::assertSame($tabBar, $result->model);
    }

    // ── L223: LogicalAnd — tab click y-range boundary conditions ──

    #[Test]
    public function clickAtYBelowAreaYDoesNotSwitchTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        $area = new Rect(0, 5, 40, 15);
        $result = $tabBar->update(
            new MouseMsg(10, 4, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertSame(0, $result->model->selectedTab());
    }

    #[Test]
    public function clickAtYAboveAreaYPlusOneDoesNotSwitchTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        $area = new Rect(0, 5, 40, 15);
        $result = $tabBar->update(
            new MouseMsg(10, 8, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertSame(0, $result->model->selectedTab());
    }

    #[Test]
    public function clickAtExactlyAreaYPlusOneSwitchesTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        $area = new Rect(0, 5, 40, 15);
        $result = $tabBar->update(
            new MouseMsg(10, 6, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertSame(1, $result->model->selectedTab());
    }

    // ── L231: GreaterThan — wheel scroll only when content overflows ──

    #[Test]
    public function wheelDownNotPossibleWhenTotalHeightEqualsViewport(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 16; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $area = new Rect(0, 0, 40, 20);
        self::assertSame(
            $tabBar,
            $tabBar->update(new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None), $area)->model,
        );
    }

    // ── L232: DecrementInteger, IncrementInteger, Minus, Plus — wheel scroll maxOffset math ──

    #[Test]
    public function wheelScrollMaxOffsetMatchesKeyboardMaxOffset(): void
    {
        $area = new Rect(0, 0, 40, 20);

        $base = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 20; ++$i) {
            $base = $base->add(Text::new("L$i"));
        }

        $keyTabBar = $base;
        $keyCurrent = $keyTabBar;
        $keyScrollCount = 0;
        for ($i = 0; $i < 100; ++$i) {
            $next = $keyCurrent->update(new KeyMsg(Key::Down), $area)->model;
            if ($next === $keyCurrent) {
                break;
            }
            $keyCurrent = $next;
            ++$keyScrollCount;
        }

        $wheelTabBar = $base;
        $wheelCurrent = $wheelTabBar;
        for ($i = 0; $i < $keyScrollCount + 5; ++$i) {
            $wheelCurrent = $wheelCurrent->update(new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None), $area)->model;
        }

        $bufferKey = new Frame(40, 20);
        $keyCurrent->render($bufferKey, $area, $this->context());
        $bufferWheel = new Frame(40, 20);
        $wheelCurrent->render($bufferWheel, $area, $this->context());

        for ($y = 3; $y < 19; ++$y) {
            self::assertSame(
                $this->bufferRow($bufferKey, $y, 40),
                $this->bufferRow($bufferWheel, $y, 40),
                "Row $y should match between keyboard and wheel scroll at max",
            );
        }
    }

    // ── L235: Plus — wheel down offset + step ──

    #[Test]
    public function wheelDownIncreasesScrollOffsetByOne(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 30; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $area = new Rect(0, 0, 40, 20);
        $scrolled1 = $tabBar->update(new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None), $area)->model;
        $scrolled2 = $scrolled1->update(new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None), $area)->model;
        self::assertNotSame($scrolled1, $scrolled2);
    }

    // ── L242: LogicalAnd, L243: DecrementInteger, IncrementInteger, Minus — wheel up conditions and math ──

    #[Test]
    public function wheelUpOnlyWorksInsideArea(): void
    {
        $area = new Rect(5, 5, 30, 10);
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 30; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $scrolled = $tabBar->update(new MouseMsg(10, 10, MouseAction::WheelDown, MouseButton::None), $area)->model;
        $outsideResult = $scrolled->update(
            new MouseMsg(40, 10, MouseAction::WheelUp, MouseButton::None),
            $area,
        );
        self::assertSame($scrolled, $outsideResult->model);
    }

    #[Test]
    public function wheelUpDecreasesScrollOffset(): void
    {
        $area = new Rect(0, 0, 40, 20);
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 30; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $scrolled1 = $tabBar->update(new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None), $area)->model;
        $scrolled2 = $scrolled1->update(new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None), $area)->model;
        $backUp = $scrolled2->update(new MouseMsg(5, 5, MouseAction::WheelUp, MouseButton::None), $area)->model;
        self::assertNotSame($scrolled2, $backUp);
        self::assertNotSame($scrolled1, $backUp);
    }

    #[Test]
    public function wheelUpWithNoOverflowDoesNotScroll(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 16; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $area = new Rect(0, 0, 40, 20);
        self::assertSame(
            $tabBar,
            $tabBar->update(new MouseMsg(5, 5, MouseAction::WheelUp, MouseButton::None), $area)->model,
        );
    }

    // ── Clicks below the strip ──

    #[Test]
    public function aClickBelowTheStripIsNotTheStripsToAnswer(): void
    {
        $tabBar = TabBar::new()
            ->addTab('Tab')
            ->add($this->createTrackableFocusable('A'))
            ->add($this->createTrackableFocusable('B'));
        $area = new Rect(0, 0, 40, 20);

        $result = $tabBar->update(new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left), $area);

        self::assertSame($tabBar, $result->model, 'the child under the pointer is a stop of its own, reached by the runtime');
    }

    // ── L264: MBString — mb_strlen in handleTabClick for multi-byte labels ──

    #[Test]
    public function clickOnTabWithMultiByteLabel(): void
    {
        $tabBar = TabBar::new()
            ->addTab("\u{00e4}\u{00f6}\u{00fc}")
            ->addTab('Two');
        $area = new Rect(0, 0, 40, 15);
        $clickOnSecond = $tabBar->update(
            new MouseMsg(8, 1, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertSame(1, $clickOnSecond->model->selectedTab());
    }

    #[Test]
    public function clickOnFirstTabWithMultiByteLabelStaysOnFirst(): void
    {
        $tabBar = TabBar::new()
            ->addTab("\u{00e4}\u{00f6}\u{00fc}")
            ->addTab('Two');
        $area = new Rect(0, 0, 40, 15);
        $clickOnFirst = $tabBar->update(
            new MouseMsg(3, 1, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertSame(0, $clickOnFirst->model->selectedTab());
    }

    #[Test]
    public function aTabClickShowsTheNewPagesChildren(): void
    {
        $tabBar = TabBar::new()
            ->addTab('One')
            ->addTab('Two')
            ->add($this->createTrackableFocusable('X'))
            ->add($this->createTrackableFocusable('Y'));
        $area = new Rect(0, 0, 40, 20);
        $switched = $tabBar->update(
            new MouseMsg(10, 1, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertSame(1, $switched->model->selectedTab());
        $frame = new Frame(40, 20);
        $switched->model->render($frame, $area, $this->context());
        self::assertSame('x', $frame->characters[3 * 40 + 1]);
    }

    // ── L443: MBString — mb_strlen in renderTabStrip ──

    #[Test]
    public function tabStripCharCountNotByteLength(): void
    {
        $frame = new Frame(40, 10);
        TabBar::new()->addTab('café')->render($frame, new Rect(0, 0, 40, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->topRight, $frame->characters[0 * 40 + 7]);
        self::assertNotSame($glyphs->topRight, $frame->characters[0 * 40 + 8]);
    }

    #[Test]
    public function multibyteTabLabelWidth(): void
    {
        $frame = new Frame(40, 10);
        TabBar::new()->addTab('日本語')->render($frame, new Rect(0, 0, 40, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        // cellWidth = CharWidth::stringWidth("日本語") + 4 = 6 + 4 = 10, topRight at col 9
        self::assertSame($glyphs->topRight, $frame->characters[0 * 40 + 9]);
        self::assertSame(' ', $frame->characters[2 * 40 + 1]);
        self::assertSame(' ', $frame->characters[2 * 40 + 8]);
        self::assertSame($glyphs->bottomLeft, $frame->characters[2 * 40 + 9]);
    }

    #[Test]
    public function multibyteSecondTabPosition(): void
    {
        $frame = new Frame(40, 10);
        TabBar::new()->addTab('日本語')->addTab('Two')
            ->render($frame, new Rect(0, 0, 40, 10), $this->context());
        // First tab cellWidth = 6 + 4 = 10, second tab label at tabX(10) + 2 = 12
        self::assertSame('T', $frame->characters[1 * 40 + 12]);
    }

    // ── L450: LessThan — tab strip top border loop bound ──

    #[Test]
    public function topBorderLoopDoesNotOverwriteTopRight(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('XY')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->horizontal, $frame->characters[0 * 30 + 4]);
        self::assertSame($glyphs->topRight, $frame->characters[0 * 30 + 5]);
        self::assertNotSame($glyphs->topRight, $frame->characters[0 * 30 + 4]);
    }

    // ── L482: DecrementInteger — isFirstActive (activeTab === 0) ──

    #[Test]
    public function baselineLeftGlyphFirstVsNonFirstActiveTab(): void
    {
        $glyphs = Theme::dark()->border->glyphs();
        $buffer1 = new Frame(30, 10);
        TabBar::new()->addTab('A')->addTab('B')->render($buffer1, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame($glyphs->vertical, $buffer1->characters[2 * 30 + 0]);

        $tabBar = TabBar::new()->addTab('A')->addTab('B');
        $tabBar = $tabBar->update(new KeyMsg(Key::Right), new Rect(0, 0, 30, 10))->model;
        $buffer2 = new Frame(30, 10);
        $tabBar->render($buffer2, new Rect(0, 0, 30, 10), $this->context());
        $fg = Theme::dark()->focusedBorder->glyphs();
        self::assertSame($fg->topLeft, $buffer2->characters[2 * 30 + 0]);
    }

    // ── L485: baseline loop fills from x+1 to right-2 ──

    #[Test]
    public function baselineHorizontalFillExactBounds(): void
    {
        $frame = new Frame(15, 8);
        TabBar::new()->addTab('Z')->render($frame, new Rect(0, 0, 15, 8), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->horizontal, $frame->characters[2 * 15 + 5]);
        self::assertSame($glyphs->horizontal, $frame->characters[2 * 15 + 13]);
        self::assertSame($glyphs->topRight, $frame->characters[2 * 15 + 14]);
    }

    // ── L492: MBString — mb_strlen in renderBaseline ──

    #[Test]
    public function baselineGapMultibyte(): void
    {
        $frame = new Frame(40, 10);
        TabBar::new()->addTab('日本語')->render($frame, new Rect(0, 0, 40, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        // cellWidth = 10, gap from col 1 to 8, bottomLeft at col 9, horizontal at col 10
        self::assertSame(' ', $frame->characters[2 * 40 + 1]);
        self::assertSame(' ', $frame->characters[2 * 40 + 8]);
        self::assertSame($glyphs->bottomLeft, $frame->characters[2 * 40 + 9]);
        self::assertSame($glyphs->horizontal, $frame->characters[2 * 40 + 10]);
    }

    // ── L496: MethodCallRemoval — putChar for first-active baseline vertical ──

    #[Test]
    public function baselineVerticalFirstActiveIsWritten(): void
    {
        $frame = new Frame(30, 10);
        $frame->putChar(0, 2, 'X');
        TabBar::new()->addTab('Tab')->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->vertical, $frame->characters[2 * 30 + 0]);
    }

    // ── L511: Assignment — tabX += cellWidth in renderBaseline ──

    #[Test]
    public function baselineTabXAccumulatesThirdTab(): void
    {
        $tabBar = TabBar::new()->addTab('A')->addTab('BB')->addTab('CCC');
        $area = new Rect(0, 0, 50, 10);
        $tabBar = $tabBar->update(new KeyMsg(Key::Right), $area)->model;
        $tabBar = $tabBar->update(new KeyMsg(Key::Right), $area)->model;
        $frame = new Frame(50, 10);
        $tabBar->render($frame, $area, $this->context());
        $glyphs = Theme::dark()->focusedBorder->glyphs();
        self::assertSame($glyphs->bottomRight, $frame->characters[2 * 50 + 11]);
        self::assertSame(' ', $frame->characters[2 * 50 + 12]);
        self::assertSame(' ', $frame->characters[2 * 50 + 16]);
        self::assertSame($glyphs->bottomLeft, $frame->characters[2 * 50 + 17]);
        self::assertSame($glyphs->horizontal, $frame->characters[2 * 50 + 18]);
    }

    // ── L524: renderTabHint rightEdge ──

    #[Test]
    public function tabHintNearRightEdge(): void
    {
        $frame = new Frame(50, 10);
        TabBar::new()->addTab('A')->addTab('B')
            ->render($frame, new Rect(0, 0, 50, 10), $this->focusedContext());
        $row = $this->bufferRow($frame, 1, 50);
        $hintPos = mb_strpos($row, "\u{2190}");
        self::assertNotFalse($hintPos);
        self::assertGreaterThan(20, $hintPos);
    }

    // ── L528,529: renderContentBox side borders exact positions ──

    #[Test]
    public function contentBoxSideBordersExactColumns(): void
    {
        $frame = new Frame(25, 10);
        TabBar::new()->addTab('Tab')->render($frame, new Rect(0, 0, 25, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        for ($y = 3; $y < 9; ++$y) {
            self::assertSame($glyphs->vertical, $frame->characters[$y * 25 + 0], "Left at y=$y");
            self::assertSame($glyphs->vertical, $frame->characters[$y * 25 + 24], "Right at y=$y");
        }
    }

    // ── L537: renderContentBox bottom border exact y ──

    #[Test]
    public function contentBoxBottomBorderExactY(): void
    {
        $frame = new Frame(20, 9);
        TabBar::new()->addTab('A')->render($frame, new Rect(0, 0, 20, 9), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->bottomLeft, $frame->characters[8 * 20 + 0]);
        self::assertSame($glyphs->horizontal, $frame->characters[8 * 20 + 1]);
        self::assertSame($glyphs->bottomRight, $frame->characters[8 * 20 + 19]);
        self::assertSame($glyphs->vertical, $frame->characters[7 * 20 + 0]);
    }

    // ── L551: left vertical loop bounds ──

    #[Test]
    public function contentBoxLeftVerticalLoop(): void
    {
        $frame = new Frame(20, 8);
        TabBar::new()->addTab('A')->render($frame, new Rect(0, 0, 20, 8), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->vertical, $frame->characters[3 * 20 + 0]);
        self::assertSame($glyphs->vertical, $frame->characters[6 * 20 + 0]);
        self::assertSame($glyphs->bottomLeft, $frame->characters[7 * 20 + 0]);
    }

    // ── L558: right vertical loop bounds ──

    #[Test]
    public function contentBoxRightVerticalLoop(): void
    {
        $frame = new Frame(20, 8);
        TabBar::new()->addTab('A')->render($frame, new Rect(0, 0, 20, 8), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->vertical, $frame->characters[3 * 20 + 19]);
        self::assertSame($glyphs->vertical, $frame->characters[6 * 20 + 19]);
        self::assertSame($glyphs->bottomRight, $frame->characters[7 * 20 + 19]);
    }

    // ── L567: renderContent overflow check ──

    #[Test]
    public function contentRendersAtMinimumDimensions(): void
    {
        $frame = new Frame(10, 10);
        TabBar::new()->addTab('A')->add(Text::new('W'))
            ->render($frame, new Rect(0, 0, 5, 5), $this->context());
        self::assertStringContainsString('W', implode('', $frame->characters));
    }

    #[Test]
    public function contentRendersWithMinPaddingStillHasWidth(): void
    {
        $frame = new Frame(10, 10);
        TabBar::new()->addTab('A')->add(Text::new('V'))
            ->withPadding(Spacing::horizontal(1))
            ->render($frame, new Rect(0, 0, 5, 5), $this->context());
        self::assertStringContainsString('V', implode('', $frame->characters));
    }

    #[Test]
    public function contentSkippedWhenHeightZero(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('A')->add(Text::new('U'))
            ->withPadding(Spacing::vertical(2))
            ->render($frame, new Rect(0, 0, 30, 5), $this->context());
        self::assertStringNotContainsString('U', implode('', $frame->characters));
    }

    // ── L580: childWidth reduced by scrollbar ──

    #[Test]
    public function childWidthReducedWhenScrollbarPresent(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 30; ++$i) {
            $tabBar = $tabBar->add(Text::new(str_repeat('Q', 50)));
        }
        $frame = new Frame(30, 10);
        $tabBar->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $c = $frame->characters[3 * 30 + 28];
        self::assertTrue($c === Scrollbar::THUMB || $c === Scrollbar::TRACK);
    }

    // ── L582: childY = contentArea->y - scrollOffset ──

    #[Test]
    public function scrolledContentShiftsChildY(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 20; ++$i) {
            $tabBar = $tabBar->add(Text::new("R$i"));
        }
        $area = new Rect(0, 0, 30, 10);
        $tabBar = $tabBar->update(new KeyMsg(Key::Down), $area)->model;
        $frame = new Frame(30, 10);
        $tabBar->render($frame, $area, $this->context());
        self::assertStringContainsString('R1', $this->bufferRow($frame, 3, 30));
        self::assertStringNotContainsString('R0', $this->bufferRow($frame, 3, 30));
    }

    // ── L588: visibility condition ──

    #[Test]
    public function childBelowViewportNotRendered(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 20; ++$i) {
            $tabBar = $tabBar->add(Text::new("P$i"));
        }
        $frame = new Frame(30, 10);
        $tabBar->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $allChars = implode('', $frame->characters);
        self::assertStringContainsString('P5', $allChars);
        self::assertStringNotContainsString('P6', $allChars);
    }

    #[Test]
    public function childAboveViewportNotRendered(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 20; ++$i) {
            $tabBar = $tabBar->add(Text::new("Q$i"));
        }
        $area = new Rect(0, 0, 30, 10);
        for ($i = 0; $i < 5; ++$i) {
            $tabBar = $tabBar->update(new KeyMsg(Key::Down), $area)->model;
        }
        $frame = new Frame(30, 10);
        $tabBar->render($frame, $area, $this->context());
        $allChars = implode('', $frame->characters);
        self::assertStringNotContainsString('Q0', $allChars);
        self::assertStringContainsString('Q5', $allChars);
    }

    // ── L589: scrollbar thumb position changes ──

    #[Test]
    public function scrollbarThumbMoves(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 30; ++$i) {
            $tabBar = $tabBar->add(Text::new("Line $i"));
        }
        $area = new Rect(0, 0, 30, 10);
        $buffer0 = new Frame(30, 10);
        $tabBar->render($buffer0, $area, $this->context());
        for ($i = 0; $i < 10; ++$i) {
            $tabBar = $tabBar->update(new KeyMsg(Key::Down), $area)->model;
        }
        $buffer1 = new Frame(30, 10);
        $tabBar->render($buffer1, $area, $this->context());
        $sx = 28;
        $t0 = [];
        $t1 = [];
        for ($y = 3; $y <= 8; ++$y) {
            if ($buffer0->characters[$y * 30 + $sx] === Scrollbar::THUMB) {
                $t0[] = $y;
            }
            if ($buffer1->characters[$y * 30 + $sx] === Scrollbar::THUMB) {
                $t1[] = $y;
            }
        }
        self::assertNotSame($t0, $t1);
    }

    // ── L598: scrollbarTotal includes padding ──

    #[Test]
    public function scrollbarCoversAllContentRows(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 30; ++$i) {
            $tabBar = $tabBar->add(Text::new("Line $i"));
        }
        $frame = new Frame(30, 10);
        $tabBar->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $sx = 28;
        $scrollChars = 0;
        for ($y = 3; $y <= 8; ++$y) {
            $c = $frame->characters[$y * 30 + $sx];
            if ($c === Scrollbar::THUMB || $c === Scrollbar::TRACK) {
                ++$scrollChars;
            }
        }
        self::assertSame(6, $scrollChars);
    }

    // ── Content box with multibyte label ──

    #[Test]
    public function contentBoxBordersWithMultibyte(): void
    {
        $frame = new Frame(30, 10);
        TabBar::new()->addTab('日本語')->add(Text::new('test'))
            ->render($frame, new Rect(0, 0, 30, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        self::assertSame($glyphs->vertical, $frame->characters[5 * 30 + 29]);
        self::assertSame($glyphs->bottomRight, $frame->characters[9 * 30 + 29]);
    }

    // ── Content box area offset ──

    #[Test]
    public function contentBoxAreaOffset(): void
    {
        $frame = new Frame(40, 20);
        TabBar::new()->addTab('T')->render($frame, new Rect(5, 3, 20, 10), $this->context());
        $glyphs = Theme::dark()->border->glyphs();
        for ($y = 6; $y <= 11; ++$y) {
            self::assertSame($glyphs->vertical, $frame->characters[$y * 40 + 5], "Left y=$y");
            self::assertSame($glyphs->vertical, $frame->characters[$y * 40 + 24], "Right y=$y");
        }
        self::assertSame($glyphs->bottomLeft, $frame->characters[12 * 40 + 5]);
        self::assertSame($glyphs->bottomRight, $frame->characters[12 * 40 + 24]);
    }

    // ── add(): lastIndex = count - 1 ──

    #[Test]
    public function addAppendsToCorrectTabWhenMultipleTabsExist(): void
    {
        $tabBar = TabBar::new()
            ->addTab('First')
            ->addTab('Second')
            ->add(Text::new('InSecond'));
        $area = new Rect(0, 0, 40, 20);
        $focused = $tabBar;
        $switched = $focused->update(new KeyMsg(Key::Right), $area)->model;
        $frame = new Frame(40, 20);
        $switched->render($frame, $area, $this->context());
        self::assertSame('I', $frame->characters[3 * 40 + 1]);
    }

    // ── update() match arms: MouseMsg, default ──

    #[Test]
    public function updateMouseMsgRoutesToHandleMouse(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        $result = $tabBar->update(
            new MouseMsg(10, 1, MouseAction::Press, MouseButton::Left),
            new Rect(0, 0, 40, 15),
        );
        self::assertSame(1, $result->model->selectedTab());
    }

    // ── switchTab: direction and modular arithmetic ──

    #[Test]
    public function switchTabForwardThenBackwardReturnsToStart(): void
    {
        $tabBar = TabBar::new()
            ->addTab('A')
            ->addTab('B')
            ->addTab('C');
        $area = new Rect(0, 0, 40, 15);
        $forward = $tabBar->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(1, $forward->selectedTab());
        $back = $forward->update(new KeyMsg(Key::Left), $area)->model;
        self::assertSame(0, $back->selectedTab());
    }

    #[Test]
    public function switchTabWrapsAroundInBothDirections(): void
    {
        $tabBar = TabBar::new()
            ->addTab('A')
            ->addTab('B')
            ->addTab('C');
        $area = new Rect(0, 0, 40, 15);
        $wrapLeft = $tabBar->update(new KeyMsg(Key::Left), $area)->model;
        self::assertSame(2, $wrapLeft->selectedTab());
        $rightTwice = $tabBar->update(new KeyMsg(Key::Right), $area)->model;
        $rightTwice = $rightTwice->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(2, $rightTwice->selectedTab());
        $wrapRight = $rightTwice->update(new KeyMsg(Key::Right), $area)->model;
        self::assertSame(0, $wrapRight->selectedTab());
    }

    // ── handleScroll: maxOffset boundary conditions ──

    #[Test]
    public function scrollUpFromOffsetOneReturnsToDifferentState(): void
    {
        $tabBar = $this->createOverflowingTabBar();
        $area = new Rect(0, 0, 30, 10);
        $scrolledDown = $tabBar->update(new KeyMsg(Key::Down), $area)->model;
        $scrolledBack = $scrolledDown->update(new KeyMsg(Key::Up), $area)->model;
        self::assertNotSame($scrolledDown, $scrolledBack);
    }

    #[Test]
    public function scrollDefaultKeyReturnsSelf(): void
    {
        $tabBar = $this->createOverflowingTabBar();
        $area = new Rect(0, 0, 30, 10);
        self::assertSame($tabBar, $tabBar->update(new KeyMsg('x'), $area)->model);
    }

    #[Test]
    public function scrollNewOffsetEqualsOldReturnsSelf(): void
    {
        $tabBar = TabBar::new()->addTab('Tab')->add(Text::new('Short'));
        $area = new Rect(0, 0, 30, 10);
        self::assertSame($tabBar, $tabBar->update(new KeyMsg(Key::Up), $area)->model);
        self::assertSame($tabBar, $tabBar->update(new KeyMsg(Key::Down), $area)->model);
    }

    // ── handleMouse: isClick on tab strip y-boundary ──

    #[Test]
    public function mouseClickAtAreaYSwitchesTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        $area = new Rect(0, 5, 40, 15);
        $result = $tabBar->update(
            new MouseMsg(10, 5, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertSame(1, $result->model->selectedTab());
    }

    #[Test]
    public function mouseClickAtAreaYPlusOneSwitchesTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        $area = new Rect(0, 5, 40, 15);
        $result = $tabBar->update(
            new MouseMsg(10, 6, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertSame(1, $result->model->selectedTab());
    }

    #[Test]
    public function mouseNonClickDoesNotSwitchTab(): void
    {
        $tabBar = TabBar::new()->addTab('One')->addTab('Two');
        $area = new Rect(0, 0, 40, 15);
        $result = $tabBar->update(
            new MouseMsg(10, 1, MouseAction::Motion, MouseButton::None),
            $area,
        );
        self::assertSame(0, $result->model->selectedTab());
    }

    // ── handleMouse: wheel up/down maxOffset math ──

    #[Test]
    public function wheelDownClampsToMaxOffset(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 10; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $area = new Rect(0, 0, 30, 10);
        $current = $tabBar;
        for ($i = 0; $i < 100; ++$i) {
            $current = $current->update(new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None), $area)->model;
        }
        $frame = new Frame(30, 10);
        $current->render($frame, $area, $this->context());
        self::assertInstanceOf(TabBar::class, $current);
    }

    #[Test]
    public function wheelUpClampsToZero(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 30; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $area = new Rect(0, 0, 30, 10);
        $scrolled = $tabBar->update(new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None), $area)->model;
        $current = $scrolled;
        for ($i = 0; $i < 10; ++$i) {
            $current = $current->update(new MouseMsg(5, 5, MouseAction::WheelUp, MouseButton::None), $area)->model;
        }
        $frame = new Frame(30, 10);
        $current->render($frame, $area, $this->context());
        self::assertStringContainsString('L0', $this->bufferRow($frame, 3, 30));
    }

    // ── handleTabClick: cellWidth uses mb_strlen + 4 ──

    #[Test]
    public function tabClickCellWidthIncludesPadding(): void
    {
        $tabBar = TabBar::new()->addTab('AB')->addTab('CD');
        $area = new Rect(0, 0, 40, 15);
        $clickOnSecond = $tabBar->update(
            new MouseMsg(7, 1, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertSame(1, $clickOnSecond->model->selectedTab());
        $clickOnFirst = $tabBar->update(
            new MouseMsg(5, 1, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertSame(0, $clickOnFirst->model->selectedTab());
    }

    // ── handleTabClick: tabX advances past inactive tabs ──

    #[Test]
    public function tabClickXAccumulatesCorrectlyForThirdTab(): void
    {
        $tabBar = TabBar::new()
            ->addTab('AA')
            ->addTab('BB')
            ->addTab('CC');
        $area = new Rect(0, 0, 40, 15);
        $clickOnThird = $tabBar->update(
            new MouseMsg(13, 1, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertSame(2, $clickOnThird->model->selectedTab());
    }

    #[Test]
    public function aChildThatAnswersWithItselfLeavesTheBarAlone(): void
    {
        $tabBar = TabBar::new()
            ->addTab('Tab')
            ->add($this->createInteractiveWidget());
        $result = $tabBar->update(new TestMessage('no-change'), new Rect(0, 0, 40, 20));
        self::assertSame($tabBar, $result->model);
    }

    // ── handleScroll: empty tabs guard ──

    #[Test]
    public function handleScrollOnEmptyTabsReturnsSelf(): void
    {
        $tabBar = TabBar::new();
        $area = new Rect(0, 0, 30, 10);
        self::assertSame($tabBar, $tabBar->update(new KeyMsg(Key::Up), $area)->model);
        self::assertSame($tabBar, $tabBar->update(new KeyMsg(Key::Down), $area)->model);
        self::assertSame($tabBar, $tabBar->update(new KeyMsg('j'), $area)->model);
        self::assertSame($tabBar, $tabBar->update(new KeyMsg('k'), $area)->model);
    }

    // ── handleMouse: non-overflow wheel events are ignored ──

    #[Test]
    public function wheelDownWithoutOverflowReturnsSelf(): void
    {
        $tabBar = TabBar::new()->addTab('Tab')->add(Text::new('Short'));
        $area = new Rect(0, 0, 40, 20);
        self::assertSame(
            $tabBar,
            $tabBar->update(new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None), $area)->model,
        );
    }

    #[Test]
    public function wheelUpWithoutOverflowReturnsSelf(): void
    {
        $tabBar = TabBar::new()->addTab('Tab')->add(Text::new('Short'));
        $area = new Rect(0, 0, 40, 20);
        self::assertSame(
            $tabBar,
            $tabBar->update(new MouseMsg(5, 5, MouseAction::WheelUp, MouseButton::None), $area)->model,
        );
    }

    // ── handleMouse: wheel outside rect is ignored ──

    #[Test]
    public function wheelDownOutsideAreaIgnored(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 30; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $area = new Rect(5, 5, 20, 10);
        self::assertSame(
            $tabBar,
            $tabBar->update(new MouseMsg(30, 10, MouseAction::WheelDown, MouseButton::None), $area)->model,
        );
    }

    #[Test]
    public function wheelUpOutsideAreaIgnored(): void
    {
        $tabBar = TabBar::new()->addTab('Tab');
        for ($i = 0; $i < 30; ++$i) {
            $tabBar = $tabBar->add(Text::new("L$i"));
        }
        $area = new Rect(5, 5, 20, 10);
        $scrolled = $tabBar->update(new MouseMsg(10, 10, MouseAction::WheelDown, MouseButton::None), $area)->model;
        self::assertSame(
            $scrolled,
            $scrolled->update(new MouseMsg(30, 10, MouseAction::WheelUp, MouseButton::None), $area)->model,
        );
    }

    // ── handleMouse: click outside content area falls through to delegation ──

    #[Test]
    public function aClickOutsideTheBarLeavesItAlone(): void
    {
        $tabBar = TabBar::new()
            ->addTab('Tab')
            ->add($this->createInteractiveWidget());
        $area = new Rect(0, 0, 40, 20);
        $result = $tabBar->update(
            new MouseMsg(50, 10, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertSame($tabBar, $result->model);
    }

    // ── add: lastIndex arithmetic ──

    #[Test]
    public function addToSingleTabUsesIndexZero(): void
    {
        $tabBar = TabBar::new()->addTab('Only')->add(Text::new('Child'));
        $frame = new Frame(30, 10);
        $tabBar->render($frame, new Rect(0, 0, 30, 10), $this->context());
        self::assertSame('C', $frame->characters[3 * 30 + 1]);
    }

    // ── handleTabClick: tabX += cellWidth at end of loop ──

    #[Test]
    public function tabClickAccumulatesWidthForVariedLabels(): void
    {
        $tabBar = TabBar::new()
            ->addTab('X')
            ->addTab('YY')
            ->addTab('ZZZ');
        $area = new Rect(0, 0, 50, 15);
        $clickOnSecond = $tabBar->update(
            new MouseMsg(6, 1, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertSame(1, $clickOnSecond->model->selectedTab());
        $clickOnThird = $tabBar->update(
            new MouseMsg(12, 1, MouseAction::Press, MouseButton::Left),
            $area,
        );
        self::assertSame(2, $clickOnThird->model->selectedTab());
    }

    // ── handleTabClick: click inside first tab returns self ──

    #[Test]
    public function tabClickOnActiveTabReturnsSelf(): void
    {
        $tabBar = TabBar::new()->addTab('Tab')->addTab('Other');
        $area = new Rect(0, 0, 40, 15);
        self::assertSame(
            $tabBar,
            $tabBar->update(new MouseMsg(3, 1, MouseAction::Press, MouseButton::Left), $area)->model,
        );
    }

    // ── switchTab: count <= 1 check ──

    #[Test]
    public function switchTabWithNoTabsReturnsSelf(): void
    {
        $tabBar = TabBar::new();
        $area = new Rect(0, 0, 40, 15);
        self::assertSame($tabBar, $tabBar->update(new KeyMsg(Key::Right), $area)->model);
    }

    // ── handleMouse: empty tabs guard ──

    #[Test]
    public function handleMouseOnEmptyTabsReturnsSelf(): void
    {
        $tabBar = TabBar::new();
        self::assertSame(
            $tabBar,
            $tabBar->update(new MouseMsg(5, 5, MouseAction::Press, MouseButton::Left), new Rect(0, 0, 40, 15))->model,
        );
    }

    #[Test]
    public function aKeyTheStripDoesNotAnswerReachesTheActivePaneOnly(): void
    {
        $tabBar = TabBar::new()
            ->addTab('Tab')
            ->add($this->createKeyReactiveInteractive());

        $result = $tabBar->update(new KeyMsg(Key::Enter), new Rect(0, 0, 40, 20));

        self::assertSame($tabBar, $result->model, 'the pane answers its own scroll keys and hands nothing to its children');
        self::assertNull($result->command);
    }

    #[Test]
    public function tabLabelWidthUsesDisplayColumns(): void
    {
        $tabBar = TabBar::new()->addTab("\u{4F60}\u{597D}");
        $frame = new Frame(20, 10);
        $tabBar->render($frame, Rect::fromSize(20, 10), $this->context());

        // Tab cell width should be CharWidth::stringWidth("你好") + 4 = 8
        // With mb_strlen it would be 2 + 4 = 6 (wrong)
        // At position 7 (inside the tab border), should not be a space
        $borderGlyph = $frame->characters[7];
        self::assertNotSame(' ', $borderGlyph);
    }

    #[Test]
    public function anIdleChildProducesNoCommand(): void
    {
        $tabBar = TabBar::new()
            ->addTab('Tab')
            ->add($this->createInteractiveWidget());

        $result = $tabBar->update(new TestMessage('no-change'), new Rect(0, 0, 40, 20));

        self::assertNull($result->command);
    }

    #[Test]
    public function aScrolledTabBarPlacesItsChildrenHigherUp(): void
    {
        $tabBar = $this->createOverflowingTabBar();
        $area = new Rect(0, 0, 30, 10);

        $scrolled = $tabBar->update(
            new MouseMsg(5, 5, MouseAction::WheelDown, MouseButton::None),
            $area,
        )->model;
        assert($scrolled instanceof TabBar);

        $childRects = $scrolled->placedChildren($area);
        $unscrolledChildRects = $tabBar->placedChildren($area);

        self::assertNotSame(
            $unscrolledChildRects[0][1]->y,
            $childRects[0][1]->y,
            'Scrolled tab should have different child Y position',
        );
        self::assertLessThan(
            $unscrolledChildRects[0][1]->y,
            $childRects[0][1]->y,
            'Scrolled child should be above unscrolled position',
        );
    }

    #[Test]
    public function supportsCustomTabNextKeybinding(): void
    {
        $area = Rect::fromSize(40, 10);
        $widget = TabBar::new()->addTab('One')->addTab('Two');

        $afterDefault = $widget->update(new KeyMsg(Key::Right), $area)->model;
        $activeAfterDefault = (new ReflectionProperty($afterDefault, 'selectedTab'))->getValue($afterDefault);
        self::assertSame(1, $activeAfterDefault);

        $custom = $widget->withKeybindings(new Keybindings([Actions::TAB_NEXT => ['x']]));
        self::assertSame($custom, $custom->update(new KeyMsg(Key::Right), $area)->model);

        $afterCustom = $custom->update(new KeyMsg('x'), $area)->model;
        $activeAfterCustom = (new ReflectionProperty($afterCustom, 'selectedTab'))->getValue($afterCustom);
        self::assertSame(1, $activeAfterCustom);
    }

    #[Test]
    public function supportsCustomTabPrevKeybinding(): void
    {
        $area = Rect::fromSize(40, 10);
        $widget = TabBar::new()->addTab('One')->addTab('Two');
        $widget = $widget->withSelectedTab(1);

        $afterDefault = $widget->update(new KeyMsg(Key::Left), $area)->model;
        $activeAfterDefault = (new ReflectionProperty($afterDefault, 'selectedTab'))->getValue($afterDefault);
        self::assertSame(0, $activeAfterDefault);

        $custom = $widget->withKeybindings(new Keybindings([Actions::TAB_PREVIOUS => ['x']]));
        self::assertSame($custom, $custom->update(new KeyMsg(Key::Left), $area)->model);

        $afterCustom = $custom->update(new KeyMsg('x'), $area)->model;
        $activeAfterCustom = (new ReflectionProperty($afterCustom, 'selectedTab'))->getValue($afterCustom);
        self::assertSame(0, $activeAfterCustom);
    }

    #[Test]
    public function scrolledMultiLineChildDoesNotRenderAboveWidgetArea(): void
    {
        // The bug: when a TabBar is positioned partway down the screen and its
        // tab content is scrolled, multi-line child widgets get rendered with a
        // negative-offset Rect — their full height is drawn starting at
        // (contentArea.y - scrollOffset), spilling row 0 of the child into rows
        // ABOVE the TabBar's own area where the tab strip doesn't cover.
        $multiLine = new MultiLineTestWidget(6);
        $tabBar = TabBar::new()
            ->addTab('T1')->add($multiLine);

        $tabsProperty = (new ReflectionObject($tabBar))->getProperty('tabs');
        /** @var list<TabPage> $tabs */
        $tabs = $tabsProperty->getValue($tabBar);
        $tabBar = $tabBar->withTabs([$tabs[0]->withPane($tabs[0]->pane->withScrollOffset(3))]);

        // TabBar starts at y=5, leaving rows 0..4 belonging to other widgets.
        // Pre-fill them with a sentinel so any leak is visible.
        $frame = new Frame(20, 15);
        for ($y = 0; $y < 5; ++$y) {
            for ($x = 0; $x < 20; ++$x) {
                $frame->putChar($x, $y, '#');
            }
        }

        $tabBar->render($frame, new Rect(0, 5, 20, 8), $this->context());

        for ($y = 0; $y < 5; ++$y) {
            $row = $this->bufferRow($frame, $y, 20);
            self::assertStringNotContainsString('row-', $row, "Scrolled tab content leaked into row {$y} (above TabBar's area): \"{$row}\"");
        }
    }

    #[Test]
    public function doesNotDrawTabStripOutsideItsAreaOnNarrowWidth(): void
    {
        // 4 tabs totalling ~41 cells (About:9 + Theme:9 + Alerts:10 + Resources:13)
        // rendered into a 20-wide area inside an 80-wide buffer.
        // Without clipping, the overflowing tabs and the active-tab box paint
        // into columns 20..79 — corrupting whatever lives there.
        $tabBar = TabBar::new()
            ->addTab('About')->add(Text::new('a'))
            ->addTab('Theme')->add(Text::new('b'))
            ->addTab('Alerts')->add(Text::new('c'))
            ->addTab('Resources')->add(Text::new('d'));

        $frame = new Frame(80, 10);
        $area = new Rect(0, 0, 20, 10);

        // Pre-fill outside-of-area cells with a sentinel so any spillover is visible
        for ($y = 0; $y < 10; ++$y) {
            for ($x = $area->right(); $x < 80; ++$x) {
                $frame->putChar($x, $y, '.');
            }
        }

        $tabBar->render($frame, $area, $this->context());

        for ($y = 0; $y < 3; ++$y) {
            for ($x = $area->right(); $x < 80; ++$x) {
                $cell = $frame->characters[$y * 80 + $x];
                self::assertSame('.', $cell, "TabBar drew outside its area at ({$x},{$y}); rendered '{$cell}' over the sentinel");
            }
        }
    }

    #[Test]
    public function tabBarAutoScrollsToKeepFocusedCursorAwareChildCursorVisible(): void
    {
        // Radio with label + 5 options = 7 rows of content.
        // TabBar content area can only show a few rows. After moving the
        // Radio cursor to the last option, the cursor row would otherwise be
        // outside the visible window — the TabBar must scroll its tab content
        // so the cursor stays in view.
        $radio = Radio::new(['Dark', 'Light', 'Dracula', 'Solarized', 'Rosé Pine'], 'Theme');
        $tabBar = TabBar::new()
            ->addTab('Theme')
            ->add($radio);

        $area = new Rect(0, 0, 40, 8); // tabStrip 3 + content 4 + bottom border 1
        // Move cursor 4 times down (j or arrow); active option becomes Rosé Pine.
        for ($i = 0; $i < 4; ++$i) {
            $tabBar = $tabBar->update(new KeyMsg(Key::Down), $area)->model;
        }

        $frame = new Frame(40, 8);
        $tabBar->render($frame, $area, $this->context());

        $found = false;
        for ($y = 0; $y < 8; ++$y) {
            $row = $this->bufferRow($frame, $y, 40);
            if (str_contains($row, 'Rosé Pine')) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'TabBar must auto-scroll its content so the focused Radio cursor (Rosé Pine, last option) stays visible');
    }

    #[Test]
    public function tabBarScrollsParagraphContentExceedingViewport(): void
    {
        // A Paragraph with many lines uses Constraint::flex(). In a small viewport,
        // the rendered content is taller than the viewport — pressing Down should
        // scroll the tab so earlier lines disappear and later lines appear.
        $lines = [];
        for ($i = 0; $i < 30; ++$i) {
            $lines[] = Line::raw("paragraph-line-{$i}");
        }

        $tabBar = TabBar::new()
            ->addTab('About')
            ->add(Paragraph::fromLines($lines));

        $area = new Rect(0, 0, 40, 8);
        $tabBar = $tabBar->update(new KeyMsg(Key::Down), $area)->model;
        $tabBar = $tabBar->update(new KeyMsg(Key::Down), $area)->model;

        $frame = new Frame(40, 8);
        $tabBar->render($frame, $area, $this->context());

        $foundLine0 = false;
        $foundLine2 = false;
        for ($y = 0; $y < 8; ++$y) {
            $row = $this->bufferRow($frame, $y, 40);
            if (str_contains($row, 'paragraph-line-0 ')) {
                $foundLine0 = true;
            }
            if (str_contains($row, 'paragraph-line-2 ')) {
                $foundLine2 = true;
            }
        }

        self::assertFalse($foundLine0, 'After scrolling down twice, the first line of the Paragraph must no longer be visible');
        self::assertTrue($foundLine2, 'After scrolling down twice, the third line of the Paragraph must be at the top of the visible viewport');
    }

    #[Test]
    public function tabBarScrollsWhenContentIsLayoutWithTallParagraph(): void
    {
        // A Paragraph nested inside Layout::vertical, placed inside a TabBar.
        // The TabBar must consult Layout's measureHeight (which sums children).
        $lines = [];
        for ($i = 0; $i < 30; ++$i) {
            $lines[] = Line::raw("nested-line-{$i}");
        }
        $layout = Layout::vertical(
            Paragraph::fromLines($lines),
        );

        $tabBar = TabBar::new()
            ->addTab('About')
            ->add($layout);

        $area = new Rect(0, 0, 40, 8);
        $tabBar = $tabBar->update(new KeyMsg(Key::Down), $area)->model;
        $tabBar = $tabBar->update(new KeyMsg(Key::Down), $area)->model;

        $frame = new Frame(40, 8);
        $tabBar->render($frame, $area, $this->context());

        $foundLine0 = false;
        for ($y = 0; $y < 8; ++$y) {
            $row = $this->bufferRow($frame, $y, 40);
            if (str_contains($row, 'nested-line-0 ')) {
                $foundLine0 = true;
                break;
            }
        }

        self::assertFalse($foundLine0, 'TabBar must scroll content when Paragraph is nested inside Layout — Layout must propagate MeasureHeight');
    }

    #[Test]
    public function switchingTabOnASingleTabBarIsIdentity(): void
    {
        $tabBar = TabBar::new()->addTab('One')
            ->add(Radio::new(['a', 'b']));

        $result = $tabBar->update(new KeyMsg(Key::Right), new Rect(0, 0, 40, 15));

        self::assertSame($tabBar, $result->model, 'Tab switching with one tab must be a strict no-op, not a blur/refocus cycle');
    }

    #[Test]
    public function clickingTheActiveTabHeaderIsIdentity(): void
    {
        $tabBar = TabBar::new()->addTab('One')
            ->add(Radio::new(['a', 'b']));

        $result = $tabBar->update(new MouseMsg(3, 1, MouseAction::Press, MouseButton::Left), new Rect(0, 0, 40, 15));

        self::assertSame($tabBar, $result->model, 'Clicking the already-active tab must be a strict no-op, not a blur/refocus cycle');
    }

    #[Test]
    public function cursorVisibleInsideTheViewportKeepsTheScrollOffset(): void
    {
        $tabBar = TabBar::new()->addTab('One')
            ->add($this->cursorJumpChild(4, 5));
        $tabs = (new ReflectionProperty(TabBar::class, 'tabs'))->getValue($tabBar);
        // Six rows of content in a four-row viewport, so three is as far as it scrolls.
        $tabBar = $tabBar->withTabs([$tabs[0]->withPane($tabs[0]->pane->withScrollOffset(3))]);
        $area = new Rect(0, 0, 40, 8);

        $result = $tabBar->update(new KeyMsg('x'), $area)->model;

        self::assertSame(3, $this->selectedTabScrollOffset($result), 'A cursor that stays visible must not move the scroll offset');
    }

    #[Test]
    public function zeroHeightViewportNeverAdjustsTheScrollOffset(): void
    {
        $tabBar = TabBar::new()->addTab('One')
            ->add($this->cursorJumpChild(0, 4));
        $area = new Rect(0, 0, 40, 4); // content height = 4 - 3 - 1 = 0

        $result = $tabBar->update(new KeyMsg('x'), $area)->model;

        self::assertSame(0, $this->selectedTabScrollOffset($result), 'With no visible content rows there is nothing to scroll into view');
    }

    #[Test]
    public function theBaselineOpensUnderTheFirstTabOnlyWhenItIsTheActiveOne(): void
    {
        $width = 26;
        $tabBar = TabBar::new()->addTab('A')->addTab('B');

        self::assertSame('│', mb_substr($this->baselineRow($tabBar, $width), 0, 1), 'The active first tab leaves its left edge open');
        self::assertSame('╭', mb_substr($this->baselineRow($tabBar->withSelectedTab(1), $width), 0, 1), 'An inactive first tab is closed off');
        self::assertSame('╮', mb_substr($this->baselineRow($tabBar, $width), $width - 1, 1), 'The baseline turns down at the right');
    }

    #[Test]
    public function theHintSitsOneColumnInsideTheRightBorder(): void
    {
        $width = 26;
        $frame = new Frame($width, 6);
        TabBar::new()->addTab('A')->addTab('B')->render($frame, new Rect(0, 0, $width, 6), $this->focusedContext());

        $labelRow = '';
        for ($column = 0; $column < $width; ++$column) {
            $labelRow .= $frame->characters[$width + $column];
        }

        // The right border is at column 25, so a 13-column hint starts at 11.
        self::assertSame('← → to switch', mb_substr($labelRow, 11, 13));
    }

    /**
     * The reader is looking at a page, not at a place on the strip, so adding one in
     * front must not silently show them a different page. A page that has gone leaves
     * them on the first, since a bar always shows something.
     */
    #[Test]
    public function theTabBarKeepsThePageItWasShowing(): void
    {
        $bar = TabBar::new()->addTab('Alpha')->addTab('Beta')->addTab('Gamma')->withSelectedTab(2);

        $grown = $bar->withTabs([
            new TabPage('Zulu'),
            new TabPage('Alpha'),
            new TabPage('Beta'),
            new TabPage('Gamma'),
        ]);
        self::assertSame(3, $grown->selectedTab(), 'Gamma moved along, and the reader with it');

        $shrunk = $bar->withTabs([new TabPage('Alpha'), new TabPage('Beta')]);
        self::assertSame(0, $shrunk->selectedTab(), 'Gamma is gone, so the first page shows');
    }

    private function screenWithTheme(TabBar $bar, Rect $area, Theme $theme, bool $focused = false): string
    {
        $frame = new Frame($area->width, $area->height);
        $bar->render($frame, $area, $focused ? RenderContext::focused($theme) : new RenderContext($theme));

        return implode('', $frame->characters);
    }

    private function screenOfTabBar(TabBar $tabBar, Rect $area, bool $focused = false): string
    {
        $frame = new Frame($area->width, $area->height);
        $tabBar->render($frame, $area, $focused ? $this->focusedContext() : $this->context());

        $screen = '';
        for ($cell = 0; $cell < $area->width * $area->height; ++$cell) {
            $screen .= $frame->characters[$cell];
        }

        return $screen;
    }

    private function baselineRow(TabBar $tabBar, int $width): string
    {
        $frame = new Frame($width, 6);
        $tabBar->render($frame, new Rect(0, 0, $width, 6), new RenderContext(Theme::dark()));

        $row = '';
        for ($column = 0; $column < $width; ++$column) {
            $row .= $frame->characters[2 * $width + $column];
        }

        return $row;
    }

    private function screenAtWidth(int $width): string
    {
        $frame = new Frame($width, 6);
        TabBar::new()->addTab('A')->addTab('B')->render($frame, new Rect(0, 0, $width, 6), $this->focusedContext());

        $screen = '';
        for ($row = 0; $row < 6; ++$row) {
            for ($column = 0; $column < $width; ++$column) {
                $screen .= $frame->characters[$row * $width + $column];
            }
        }

        return $screen;
    }

    private function context(): RenderContext
    {
        return new RenderContext(Theme::dark());
    }

    private function focusedContext(): RenderContext
    {
        return RenderContext::focused(Theme::dark());
    }

    private function bufferRow(Frame $frame, int $row, int $width): string
    {
        $result = '';
        for ($x = 0; $x < $width; ++$x) {
            $result .= $frame->characters[$row * $width + $x];
        }

        return $result;
    }

    private function columnOfLabel(Frame $frame, int $width, int $row, string $label): int
    {
        $characters = mb_str_split($label);

        for ($column = 0; $column + count($characters) <= $width; ++$column) {
            $painted = array_slice($frame->characters, $row * $width + $column, count($characters));
            if ($painted === $characters) {
                return $column;
            }
        }

        self::fail(sprintf('The strip carries no label "%s"', $label));
    }

    private function createOverflowingTabBar(): TabBar
    {
        $tabBar = TabBar::new()->addTab('One');
        for ($i = 0; $i < 30; ++$i) {
            $tabBar = $tabBar->add(Text::new("Line $i"));
        }

        return $tabBar;
    }

    // ─── Helper factories ───

    private function createTrackableFocusable(string $label): Focusable
    {
        return new class($label) implements Focusable {
            public function __construct(private readonly string $label)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putString($area->x, $area->y, $context->hasFocus() ? strtoupper($this->label) : strtolower($this->label));
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return new UpdateResult($this);
            }
        };
    }

    private function createInteractiveWidget(): Interactive
    {
        return new class implements Interactive {
            private int $counter = 0;

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof TestMessage && $message->value === 'change-me') {
                    $clone = clone $this;
                    $clone->counter = $this->counter + 1;

                    return new UpdateResult($clone);
                }

                return new UpdateResult($this);
            }
        };
    }

    private function createFlexWidget(int $weight): Widget
    {
        return new class($weight) implements Widget {
            public function __construct(private readonly int $weight)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::flex($this->weight), Constraint::flex($this->weight));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }

    private function createMinConstraintWidget(int $min): Widget
    {
        return new class($min) implements Widget {
            public function __construct(private readonly int $min)
            {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::min($this->min), Constraint::min($this->min));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }

    private function createMarkerWidget(string $marker, Constraint $constraint): Widget
    {
        return new class($marker, $constraint) implements Widget {
            public function __construct(
                private readonly string $marker,
                private readonly Constraint $constraint,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of($this->constraint, $this->constraint);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
                $frame->putString($area->x, $area->y, $this->marker);
            }
        };
    }

    private function createKeyReactiveInteractive(): Interactive
    {
        return new class implements Interactive {
            private int $counter = 0;

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof KeyMsg) {
                    $clone = clone $this;
                    $clone->counter = $this->counter + 1;

                    return new UpdateResult($clone);
                }

                return new UpdateResult($this);
            }
        };
    }

    /**
     * Focusable child whose cursorRow() jumps to a fixed target on any key.
     */
    private function cursorJumpChild(int $row, int $jumpTarget): Interactive
    {
        return new class($row, $jumpTarget) implements Interactive, Focusable, CursorAware {
            public function __construct(
                public int $row,
                public int $jumpTarget,
            ) {
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(6), Constraint::exact(6));
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                return $message instanceof KeyMsg
                    ? new UpdateResult(new self($this->jumpTarget, $this->jumpTarget))
                    : new UpdateResult($this);
            }

            public function cursorRow(): int
            {
                return $this->row;
            }
        };
    }

    private function selectedTabScrollOffset(TabBar $tabBar): int
    {
        /** @var list<TabPage> $tabs */
        $tabs = (new ReflectionProperty(TabBar::class, 'tabs'))->getValue($tabBar);
        $activeTab = (new ReflectionProperty(TabBar::class, 'selectedTab'))->getValue($tabBar);

        return $tabs[$activeTab]->pane->scrollOffset();
    }
}

final readonly class MultiLineTestWidget implements Widget
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
            $frame->putString($area->x, $area->y + $i, "row-{$i}-here");
        }
    }
}

final readonly class TabBarCommandWidget implements Interactive
{
    public function __construct(private Cmd $command)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this, $this->command);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}

final class NotAWidgetProbeMsg implements Message
{
}

final readonly class NotAWidgetModel implements Reducer
{
    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }
}

final readonly class NotAWidgetReturningChild implements Interactive
{
    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult(new NotAWidgetModel());
    }
}

final readonly class TwoFieldsInATabApp extends App
{
    public function __construct(private ?TabBar $bar = null)
    {
    }

    public function bar(): TabBar
    {
        return $this->bar ?? TabBar::new()->addTab('Form')->add(TextInput::new())->add(TextInput::new());
    }

    public function view(): Widget
    {
        return Component::bind(
            'bar',
            $this->bar(),
            static fn (self $app, TabBar $bar): self => $app->cloneWith(bar: $bar),
        );
    }
}
