<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\BatchCmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Tests\Support\TickTally;
use Elmphant\Tui\Widget\Alignment;
use Elmphant\Tui\Widget\Bar\FocusHints;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Bar\StatusBar;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FooterTest extends TestCase
{
    private RenderContext $context;

    #[Test]
    public function itsChildrenAreEverySlotFilledInOrder(): void
    {
        $left = Text::new('l');
        $right = Text::new('r');

        self::assertSame([$left, $right], Footer::new()->withLeft($left)->withRight($right)->children());
    }

    #[Test]
    public function aGlobalRebindingReachesTheKeysASlotAnswers(): void
    {
        $footer = Footer::new()
            ->withRight(TextInput::new()->withValue('typed'))
            ->withMarkedSlot(Alignment::Right)
            ->withGlobalKeybindings(new Keybindings([Actions::CLEAR_LINE => ['ctrl+k']]));

        $result = $footer->update(new KeyMsg('k', ctrl: true), Rect::fromSize(80, 1));

        self::assertInstanceOf(Footer::class, $result->model);
        $slot = [...$result->model->children()][0];
        self::assertInstanceOf(TextInput::class, $slot);
        self::assertSame('', $slot->value(), 'a bar must hand its slots the bindings the app made active');
    }

    #[Test]
    public function implementsWidget(): void
    {
        self::assertInstanceOf(Widget::class, Footer::new());
    }

    #[Test]
    public function constraintIsExactOne(): void
    {
        $footer = Footer::new();
        self::assertEquals(Constraint::exact(1), $footer->sizing()->rows);
    }

    #[Test]
    public function rendersLeftSlot(): void
    {
        $footer = Footer::new()
            ->withLeft(Text::new('Left'));
        $frame = new Frame(30, 1);
        $footer->render($frame, Rect::fromSize(30, 1), $this->context);

        $text = $this->extractText($frame, 0, 4);
        self::assertSame('Left', $text);
    }

    #[Test]
    public function rendersCenterSlot(): void
    {
        $footer = Footer::new()
            ->withCenter(Text::new('Center'));
        $frame = new Frame(20, 1);
        $footer->render($frame, Rect::fromSize(20, 1), $this->context);

        $centerStart = (int)floor((20 - 6) / 2);
        $text = $this->extractText($frame, $centerStart, 6);
        self::assertSame('Center', $text);
    }

    #[Test]
    public function rendersRightSlotRightAligned(): void
    {
        $footer = Footer::new()
            ->withRight(Text::new('Right'));
        $frame = new Frame(30, 1);
        $footer->render($frame, Rect::fromSize(30, 1), $this->context);

        $text = $this->extractText($frame, 25, 5);
        self::assertSame('Right', $text);
    }

    #[Test]
    public function hintsShortcutRendersKeyDescriptionPairs(): void
    {
        $footer = Footer::forHints(['Tab' => 'cycle', 'q' => 'quit']);
        $frame = new Frame(60, 1);
        $footer->render($frame, Rect::fromSize(60, 1), $this->context);

        $fullText = $this->extractText($frame, 0, 60);
        self::assertStringContainsString('Tab', $fullText);
        self::assertStringContainsString('cycle', $fullText);
        self::assertStringContainsString('q', $fullText);
        self::assertStringContainsString('quit', $fullText);
    }

    #[Test]
    public function appliesBarBackground(): void
    {
        $footer = Footer::new();
        $frame = new Frame(20, 1);
        $footer->render($frame, Rect::fromSize(20, 1), $this->context);

        $hasNonZeroBackground = false;
        for ($x = 0; $x < 20; ++$x) {
            if ($frame->backgrounds[$x] !== 0) {
                $hasNonZeroBackground = true;
                break;
            }
        }
        self::assertTrue($hasNonZeroBackground, 'Bar background should be non-zero across the row');
    }

    #[Test]
    public function renderWithZeroWidthDoesNotCrash(): void
    {
        $footer = Footer::new();
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 0, 1);
        $footer->render($frame, $area, $this->context);

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function paddingShiftsContent(): void
    {
        $footer = Footer::new()
            ->withLeft(Text::new('Left'))
            ->withPadding(Spacing::horizontal(1));
        $frame = new Frame(20, 1);
        $footer->render($frame, Rect::fromSize(20, 1), $this->context);

        $text = $this->extractText($frame, 1, 4);
        self::assertSame('Left', $text);

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function constraintIncludesPadding(): void
    {
        $footer = Footer::new()
            ->withPadding(Spacing::vertical(1));

        self::assertEquals(Constraint::exact(3), $footer->sizing()->rows);
    }

    #[Test]
    public function implementsInteractive(): void
    {
        self::assertInstanceOf(Interactive::class, Footer::new());
    }

    #[Test]
    public function updateForwardsTickToInteractiveSlot(): void
    {
        $spinner = new TickTally();
        $footer = Footer::new()->withRight($spinner);
        $area = Rect::fromSize(80, 1);

        $result = $footer->update(new TickMsg(1.0), $area);

        self::assertInstanceOf(Footer::class, $result->model);
        self::assertNotSame($footer, $result->model);
    }

    #[Test]
    public function updateLeavesSlotsAloneInAnAreaTooSmallToDrawThem(): void
    {
        $footer = Footer::new()->withRight(new TickTally());

        $result = $footer->update(new TickMsg(1.0), Rect::fromSize(80, 0));

        self::assertSame($footer, $result->model, 'A slot the footer never draws must not be fed input either');
    }

    #[Test]
    public function updateReturnsUnchangedWhenNoInteractiveSlots(): void
    {
        $footer = Footer::new()->withRight(Text::new('static'));
        $area = Rect::fromSize(80, 1);

        $result = $footer->update(new TickMsg(1.0), $area);

        self::assertSame($footer, $result->model);
    }

    #[Test]
    public function updateForwardsToAllInteractiveSlots(): void
    {
        $left = new TickTally();
        $right = new TickTally();
        $footer = Footer::new()->withLeft($left)->withRight($right);
        $area = Rect::fromSize(80, 1);

        $result = $footer->update(new TickMsg(1.0), $area);

        self::assertNotSame($footer, $result->model);
    }

    // --- Mutation-killing tests ---

    #[Test]
    public function updateWithNoCommandsReturnsNull(): void
    {
        $footer = Footer::new()->withLeft(Text::new('static'));
        $result = $footer->update(new TickMsg(0.0), Rect::fromSize(80, 1));
        self::assertNull($result->command);
    }

    #[Test]
    public function updateWithSingleCommandReturnsCmdDirectly(): void
    {
        $command = Cmd::tick(1.0);
        $slot = $this->createInteractiveReturningCommand($command);
        $footer = Footer::new()->withLeft($slot);
        $result = $footer->update(new TickMsg(0.0), Rect::fromSize(80, 1));
        self::assertNotNull($result->command);
        self::assertNotInstanceOf(BatchCmd::class, $result->command);
        self::assertSame($command, $result->command);
    }

    /**
     * Kills L74 Coalesce: constraint without padding uses Spacing::none() fallback.
     */
    #[Test]
    public function constraintWithoutPaddingUsesZeroFallback(): void
    {
        $footer = Footer::new();
        self::assertEquals(Constraint::exact(1), $footer->sizing()->rows);
    }

    /**
     * Kills L76 Plus x2: constraint with padding correctly sums all vertical spacing.
     */
    #[Test]
    public function constraintWithPaddingAndMarginSumsCorrectly(): void
    {
        $footer = Footer::new()
            ->withPadding(Spacing::vertical(1))
            ->withMargin(Spacing::vertical(1));

        // 1 (content) + 1 (padding top) + 1 (padding bottom) + 1 (margin top) + 1 (margin bottom) = 5
        self::assertEquals(Constraint::exact(5), $footer->sizing()->rows);
    }

    /**
     * Kills L86 NotIdentical: update detects model change and applies override.
     */
    #[Test]
    public function updateDetectsLeftSlotModelChange(): void
    {
        $spinner = new TickTally();
        $footer = Footer::new()->withLeft($spinner);
        $area = Rect::fromSize(80, 1);

        $result = $footer->update(new TickMsg(1.0), $area);

        self::assertNotSame($footer, $result->model);
        self::assertInstanceOf(Footer::class, $result->model);
    }

    /**
     * Kills L86 NotIdentical: no override when model is unchanged.
     */
    #[Test]
    public function updateDoesNotOverrideWhenLeftSlotModelUnchanged(): void
    {
        $staticSlot = $this->createInteractiveReturningSelf();
        $footer = Footer::new()->withLeft($staticSlot);
        $area = Rect::fromSize(80, 1);

        $result = $footer->update(new TestMessage('msg'), $area);

        self::assertSame($footer, $result->model);
    }

    /**
     * Both slot commands are collected into a BatchCmd.
     */
    #[Test]
    public function updateBatchesCommandsFromLeftAndCenterSlots(): void
    {
        $leftCommand = Cmd::tick(1.0);
        $centerCommand = Cmd::tick(2.0);

        $leftSlot = $this->createInteractiveReturningCommand($leftCommand);
        $centerSlot = $this->createInteractiveReturningCommand($centerCommand);

        $footer = Footer::new()->withLeft($leftSlot)->withCenter($centerSlot);
        $area = Rect::fromSize(80, 1);

        $result = $footer->update(new TestMessage('msg'), $area);

        self::assertInstanceOf(BatchCmd::class, $result->command);
        self::assertCount(2, $result->command->commands);
    }

    /**
     * Kills L89 AssignCoalesce: leftSlot command is used when only left returns command.
     */
    #[Test]
    public function updateUsesLeftSlotCommand(): void
    {
        $leftCommand = Cmd::tick(1.0);
        $leftSlot = $this->createInteractiveReturningCommand($leftCommand);

        $footer = Footer::new()->withLeft($leftSlot);
        $area = Rect::fromSize(80, 1);

        $result = $footer->update(new TestMessage('msg'), $area);

        self::assertSame($leftCommand, $result->command);
    }

    /**
     * Both slot commands are collected into a BatchCmd.
     */
    #[Test]
    public function updateBatchesCommandsFromLeftAndRightSlots(): void
    {
        $leftCommand = Cmd::tick(1.0);
        $rightCommand = Cmd::tick(2.0);

        $leftSlot = $this->createInteractiveReturningCommand($leftCommand);
        $rightSlot = $this->createInteractiveReturningCommand($rightCommand);

        $footer = Footer::new()->withLeft($leftSlot)->withRight($rightSlot);
        $area = Rect::fromSize(80, 1);

        $result = $footer->update(new TestMessage('msg'), $area);

        self::assertInstanceOf(BatchCmd::class, $result->command);
        self::assertCount(2, $result->command->commands);
    }

    /**
     * Kills L105 AssignCoalesce: rightSlot command is used when no prior command exists.
     */
    #[Test]
    public function updateUsesRightSlotCommandWhenNoPriorCommand(): void
    {
        $rightCommand = Cmd::tick(2.0);
        $rightSlot = $this->createInteractiveReturningCommand($rightCommand);

        $footer = Footer::new()->withRight($rightSlot);
        $area = Rect::fromSize(80, 1);

        $result = $footer->update(new TestMessage('msg'), $area);

        self::assertSame($rightCommand, $result->command);
    }

    /**
     * Kills L117 LessThan: area with width=1 must still render.
     */
    #[Test]
    public function renderWithWidthOneRendersBackground(): void
    {
        $footer = Footer::new();
        $frame = new Frame(1, 1);
        $footer->render($frame, Rect::fromSize(1, 1), $this->context);

        self::assertNotSame(0, $frame->backgrounds[0]);
    }

    /**
     * Kills L117 LessThan: area with height=1 must still render.
     */
    #[Test]
    public function renderWithHeightOneRendersBackground(): void
    {
        $footer = Footer::new();
        $frame = new Frame(5, 1);
        $footer->render($frame, Rect::fromSize(5, 1), $this->context);

        self::assertNotSame(0, $frame->backgrounds[0]);
    }

    /**
     * Kills L117 LogicalOr: zero height alone triggers early return.
     */
    #[Test]
    public function renderWithZeroHeightDoesNotRender(): void
    {
        $footer = Footer::new();
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 0);
        $footer->render($frame, $area, $this->context);

        self::assertSame(0, $frame->backgrounds[0]);
    }

    /**
     * Kills L118 ReturnRemoval: early return prevents buffer modification.
     */
    #[Test]
    public function renderWithZeroDimensionsLeavesBufferUntouched(): void
    {
        $footer = Footer::new();
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 0, 0);
        $footer->render($frame, $area, $this->context);

        for ($x = 0; $x < 20; ++$x) {
            self::assertSame(0, $frame->foregrounds[$x]);
            self::assertSame(0, $frame->backgrounds[$x]);
        }
    }

    /**
     * Kills L121 Coalesce: without margin set, render uses Spacing::none() fallback.
     */
    #[Test]
    public function renderWithoutMarginUsesNoMarginFallback(): void
    {
        $footer = Footer::new();
        $frame = new Frame(10, 1);
        $footer->render($frame, Rect::fromSize(10, 1), $this->context);

        self::assertNotSame(0, $frame->backgrounds[0]);
        self::assertNotSame(0, $frame->backgrounds[9]);
    }

    /**
     * Kills L122 LessThan: margin leaving exactly width=1 must still render.
     */
    #[Test]
    public function renderWithMarginLeavingWidthOneRendersBackground(): void
    {
        $footer = Footer::new()
            ->withMargin(Spacing::horizontal(1));
        $frame = new Frame(3, 1);
        $footer->render($frame, Rect::fromSize(3, 1), $this->context);

        self::assertNotSame(0, $frame->backgrounds[1]);
    }

    /**
     * Kills L122 LogicalOr: margin leaving zero width triggers early return.
     */
    #[Test]
    public function renderWithMarginLeavingZeroWidthDoesNotRender(): void
    {
        $footer = Footer::new()
            ->withMargin(Spacing::horizontal(1));
        $frame = new Frame(2, 1);
        $footer->render($frame, Rect::fromSize(2, 1), $this->context);

        self::assertSame(0, $frame->backgrounds[0]);
        self::assertSame(0, $frame->backgrounds[1]);
    }

    /**
     * Kills L127 Coalesce x2: bar background uses theme selectedStyle.background.
     */
    #[Test]
    public function barBackgroundUsesThemeSelectedStyleBackground(): void
    {
        $theme = Theme::dark();
        $expectedBackground = $theme->selectedStyle->background;
        self::assertNotNull($expectedBackground, 'Dark theme selectedStyle must have a background');

        $footer = Footer::new();
        $frame = new Frame(10, 1);
        $footer->render($frame, Rect::fromSize(10, 1), $this->context);

        $footerBg = $frame->backgrounds[0];
        self::assertNotSame(0, $footerBg);
    }

    /**
     * The bar paints the theme's footer style, so hint text stays readable.
     */
    #[Test]
    public function barUsesTheThemeFooterStyle(): void
    {
        $theme = Theme::dark();
        $context = new RenderContext($theme);

        $footer = Footer::new();
        $frame = new Frame(10, 1);
        $footer->render($frame, Rect::fromSize(10, 1), $context);

        self::assertSame($theme->footerStyle->backgroundValue(), $frame->backgrounds[0]);
        self::assertSame($theme->footerStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function barUsesACustomThemeFooterStyle(): void
    {
        $footerStyle = new Style(foreground: Color::rgb(10, 20, 30), background: Color::rgb(100, 200, 50));
        $context = new RenderContext(Theme::dark()->withFooterStyle($footerStyle));

        $footer = Footer::new();
        $frame = new Frame(10, 1);
        $footer->render($frame, Rect::fromSize(10, 1), $context);

        self::assertSame(Color::rgb(100, 200, 50)->value, $frame->backgrounds[0]);
        self::assertSame(Color::rgb(10, 20, 30)->value, $frame->foregrounds[0]);
    }

    #[Test]
    public function footerStyleDefaultPairsBodyTextWithTheSelectionBackground(): void
    {
        $theme = new Theme(
            base: new Style(foreground: Color::rgb(100, 200, 50)),
            accent: new Style(),
            dim: new Style(foreground: Color::rgb(128, 128, 128)),
            error: new Style(),
            success: new Style(),
            borderStyle: new Style(),
            focusedBorderStyle: new Style(),
            titleStyle: new Style(),
            headerStyle: new Style(),
            selectedStyle: new Style(background: Color::rgb(20, 20, 20)),
            cursorStyle: new Style(),
            fillStyle: new Style(),
            emptyStyle: new Style(),
            inputStyle: new Style(),
        );

        self::assertSame(Color::rgb(100, 200, 50)->value, $theme->footerStyle->foregroundValue(), 'Body text, not dim text');
        self::assertSame(Color::rgb(20, 20, 20)->value, $theme->footerStyle->backgroundValue());
    }

    #[Test]
    public function footerStyleDefaultFallsBackWhenTheThemeDefinesNoColors(): void
    {
        $theme = new Theme(
            base: new Style(),
            accent: new Style(),
            dim: new Style(),
            error: new Style(),
            success: new Style(),
            borderStyle: new Style(),
            focusedBorderStyle: new Style(),
            titleStyle: new Style(),
            headerStyle: new Style(),
            selectedStyle: new Style(),
            cursorStyle: new Style(),
            fillStyle: new Style(),
            emptyStyle: new Style(),
            inputStyle: new Style(),
        );

        self::assertSame(Color::ansi(7)->value, $theme->footerStyle->foregroundValue());
        self::assertSame(Color::ansi(0)->value, $theme->footerStyle->backgroundValue());
    }

    /**
     * Kills L127 Color::ansi(0) fallback when both selectedStyle.background and base.foreground are null.
     */
    #[Test]
    public function barBackgroundFallsBackToAnsiZeroWhenAllNull(): void
    {
        $theme = new Theme(
            base: new Style(),
            accent: new Style(),
            dim: new Style(),
            error: new Style(),
            success: new Style(),
            borderStyle: new Style(),
            focusedBorderStyle: new Style(),
            titleStyle: new Style(),
            headerStyle: new Style(),
            selectedStyle: new Style(),
            cursorStyle: new Style(),
            fillStyle: new Style(),
            emptyStyle: new Style(),
            inputStyle: new Style(),
        );
        $context = new RenderContext($theme);

        $footer = Footer::new();
        $frame = new Frame(10, 1);
        $footer->render($frame, Rect::fromSize(10, 1), $context);

        $expectedBg = Color::ansi(0)->value;
        self::assertSame($expectedBg, $frame->backgrounds[0]);
    }

    /**
     * Kills L128 Coalesce: custom style overrides default bar style.
     */
    #[Test]
    public function customStyleOverridesDefaultBarStyle(): void
    {
        $customStyle = new Style(foreground: Color::rgb(255, 0, 0), background: Color::rgb(0, 255, 0));
        $footer = Footer::new()->withStyle($customStyle);
        $frame = new Frame(10, 1);
        $footer->render($frame, Rect::fromSize(10, 1), $this->context);

        self::assertSame($customStyle->backgroundValue(), $frame->backgrounds[0]);
        self::assertSame($customStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    /**
     * Kills L134 LessThan: padding leaving exactly width=1 content area must still render slot.
     */
    #[Test]
    public function renderWithPaddingLeavingWidthOneStillRendersContent(): void
    {
        $footer = Footer::new()
            ->withLeft(Text::new('X'))
            ->withPadding(Spacing::horizontal(1));
        $frame = new Frame(3, 1);
        $footer->render($frame, Rect::fromSize(3, 1), $this->context);

        self::assertSame('X', $frame->characters[1]);
    }

    /**
     * Kills L134 LogicalOr: padding consuming all width prevents content rendering.
     */
    #[Test]
    public function renderWithPaddingConsumingAllWidthSkipsSlotRendering(): void
    {
        $footer = Footer::new()
            ->withLeft(Text::new('X'))
            ->withPadding(Spacing::horizontal(1));
        $frame = new Frame(2, 1);
        $footer->render($frame, Rect::fromSize(2, 1), $this->context);

        self::assertSame(' ', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[1]);
    }

    /**
     * Kills L134 LessThan: padding leaving exactly height=1 content area renders.
     */
    #[Test]
    public function renderWithExactlyOneByOneAreaRendersBackground(): void
    {
        $footer = Footer::new();
        $frame = new Frame(1, 1);
        $footer->render($frame, Rect::fromSize(1, 1), $this->context);

        self::assertNotSame(0, $frame->backgrounds[0]);
    }

    /**
     * Kills L76 Plus x2: constraint includes margin.
     */
    #[Test]
    public function constraintIncludesMargin(): void
    {
        $footer = Footer::new()
            ->withMargin(Spacing::vertical(1));

        // 1 (content) + 1 (margin top) + 1 (margin bottom) = 3
        self::assertEquals(Constraint::exact(3), $footer->sizing()->rows);
    }

    /**
     * Kills L122 LessThan: margin leaving height=0 prevents rendering.
     */
    #[Test]
    public function renderWithMarginLeavingZeroHeightDoesNotRender(): void
    {
        $footer = Footer::new()
            ->withMargin(Spacing::vertical(1));
        $frame = new Frame(10, 1);
        $footer->render($frame, Rect::fromSize(10, 1), $this->context);

        // Margin vertical(1) => top=1, bottom=1, shrinking height=1 to max(0, 1-1-1)=0
        // Actually shrink(1,0,1,0) on Rect(0,0,10,1) => Rect(0,1,10,max(0,1-1-1))=Rect(0,1,10,0)
        // So barArea.height=0, early return
        self::assertSame(0, $frame->backgrounds[0]);
    }

    #[Test]
    public function updateUsesCenterSlotCommandWhenNoLeftCommand(): void
    {
        $centerCommand = Cmd::tick(2.0);
        $centerSlot = $this->createInteractiveReturningCommand($centerCommand);

        $footer = Footer::new()->withCenter($centerSlot);
        $area = Rect::fromSize(80, 1);

        $result = $footer->update(new TestMessage('msg'), $area);

        self::assertSame($centerCommand, $result->command);
    }

    #[Test]
    public function updateDetectsCenterSlotModelChange(): void
    {
        $spinner = new TickTally();
        $footer = Footer::new()->withCenter($spinner);
        $area = Rect::fromSize(80, 1);

        $result = $footer->update(new TickMsg(1.0), $area);

        self::assertNotSame($footer, $result->model);
    }

    #[Test]
    public function updateDetectsRightSlotModelChange(): void
    {
        $spinner = new TickTally();
        $footer = Footer::new()->withRight($spinner);
        $area = Rect::fromSize(80, 1);

        $result = $footer->update(new TickMsg(1.0), $area);

        self::assertNotSame($footer, $result->model);
    }

    #[Test]
    public function fillRectCoversEntireBarArea(): void
    {
        $footer = Footer::new();
        $frame = new Frame(10, 1);
        $footer->render($frame, Rect::fromSize(10, 1), $this->context);

        for ($column = 0; $column < 10; ++$column) {
            self::assertNotSame(0, $frame->backgrounds[$column], "Column $column should have bar background");
        }
    }

    #[Test]
    public function fillRectWithMarginOnlyCoversBarArea(): void
    {
        $footer = Footer::new()
            ->withMargin(Spacing::horizontal(1));
        $frame = new Frame(10, 1);
        $footer->render($frame, Rect::fromSize(10, 1), $this->context);

        self::assertSame(0, $frame->backgrounds[0]);
        self::assertNotSame(0, $frame->backgrounds[1]);
        self::assertNotSame(0, $frame->backgrounds[8]);
        self::assertSame(0, $frame->backgrounds[9]);
    }

    #[Test]
    public function renderAtNonZeroOriginPositionsCorrectly(): void
    {
        $footer = Footer::new()->withLeft(Text::new('Hi'));
        $frame = new Frame(30, 10);
        $area = new Rect(5, 7, 20, 1);
        $footer->render($frame, $area, $this->context);

        $index = 7 * 30 + 5;
        self::assertSame('H', $frame->characters[$index]);
        self::assertSame('i', $frame->characters[$index + 1]);
    }

    /**
     * L117/L118: LogicalOr + ReturnRemoval — width=0 but height=1 must still early return.
     * If mutated to &&, only both-zero triggers return; width=0 alone would proceed.
     */
    #[Test]
    public function renderWithZeroWidthButPositiveHeightReturnsEarly(): void
    {
        $footer = Footer::new()->withLeft(Text::new('X'));
        $frame = new Frame(5, 5);
        $area = new Rect(0, 0, 0, 1);
        $footer->render($frame, $area, $this->context);

        // Buffer should remain untouched
        self::assertSame(0, $frame->backgrounds[0]);
    }

    /**
     * L117/L118: height=0 but width>0 must also early return.
     */
    #[Test]
    public function renderWithZeroHeightButPositiveWidthReturnsEarly(): void
    {
        $footer = Footer::new()->withLeft(Text::new('X'));
        $frame = new Frame(10, 5);
        $area = new Rect(0, 0, 10, 0);
        $footer->render($frame, $area, $this->context);

        self::assertSame(0, $frame->backgrounds[0]);
    }

    /**
     * L122/L123: barArea with zero width after margin must early return.
     * Tests the second guard pair.
     */
    #[Test]
    public function renderWithMarginLeavingZeroWidthButPositiveHeightReturnsEarly(): void
    {
        $footer = Footer::new()
            ->withLeft(Text::new('X'))
            ->withMargin(Spacing::horizontal(5));
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $footer->render($frame, $area, $this->context);

        // Margin takes 5 from each side, leaving 0 width
        self::assertSame(0, $frame->backgrounds[0]);
    }

    /**
     * L134/L135: contentArea with zero width after padding must early return.
     * But the fillRect for the bar should still have been applied.
     */
    #[Test]
    public function renderWithPaddingLeavingZeroWidthStillFillsBar(): void
    {
        $footer = Footer::new()
            ->withLeft(Text::new('X'))
            ->withPadding(Spacing::horizontal(5));
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $footer->render($frame, $area, $this->context);

        // Bar background should be filled despite content having zero width
        self::assertNotSame(0, $frame->backgrounds[0]);
        // But the text 'X' should NOT have been rendered
        self::assertSame(' ', $frame->characters[0]);
    }

    /**
     * L127: Coalesce — verify barBackground uses selectedStyle.background first.
     * If coalesce order is swapped, base.foreground would be used instead.
     */
    #[Test]
    public function barBackgroundUsesSelectedStyleBackgroundOverBaseForeground(): void
    {
        $selectedBg = Color::rgb(10, 20, 30);
        $baseFg = Color::rgb(40, 50, 60);
        $theme = new Theme(
            base: new Style(foreground: $baseFg),
            accent: new Style(),
            dim: new Style(foreground: Color::rgb(128, 128, 128)),
            error: new Style(),
            success: new Style(),
            borderStyle: new Style(),
            focusedBorderStyle: new Style(),
            titleStyle: new Style(),
            headerStyle: new Style(),
            selectedStyle: new Style(background: $selectedBg),
            cursorStyle: new Style(),
            fillStyle: new Style(),
            emptyStyle: new Style(),
            inputStyle: new Style(),
        );
        $context = new RenderContext($theme);

        $footer = Footer::new();
        $frame = new Frame(10, 1);
        $footer->render($frame, Rect::fromSize(10, 1), $context);

        // Should use selectedStyle.background (10,20,30), not base.foreground (40,50,60)
        self::assertSame($selectedBg->value, $frame->backgrounds[0]);
        self::assertNotSame($baseFg->value, $frame->backgrounds[0]);
    }

    #[Test]
    public function exposesEachSlotInTheRectItDrawsIn(): void
    {
        $left = Text::new('Hi');
        $right = Text::new('Bye');

        $children = [...Footer::new()->withLeft($left)->withRight($right)->placedChildren(new Rect(0, 0, 20, 1))];

        self::assertSame([$left, $right], array_column($children, 0));
        self::assertSame([0, 17], array_map(static fn (array $child): int => $child[1]->x, $children));
    }

    #[Test]
    public function updateRoutesToASlotInTheRectItDrawsIn(): void
    {
        $receivedArea = null;
        $spy = new FooterAreaCapturingInteractive($receivedArea);

        $footer = Footer::new()
            ->withLeft($spy)
            ->withPadding(Spacing::horizontal(1))
            ->withMargin(Spacing::horizontal(1));
        $footer->update(new TickMsg(0.0), new Rect(0, 0, 80, 1));

        self::assertNotNull($receivedArea);
        self::assertSame(1, $receivedArea->height);
        self::assertSame(2, $receivedArea->x, 'Inside the margin and the padding');
        self::assertSame(1, $receivedArea->width, 'The width the slot declares, not the whole row');
    }

    #[Test]
    public function updateCollectsCommandsFromAllSlots(): void
    {
        $leftCommand = Cmd::message(new TestMessage('left-cmd'));
        $centerCommand = Cmd::message(new TestMessage('center-cmd'));
        $rightCommand = Cmd::message(new TestMessage('right-cmd'));

        $footer = Footer::new()
            ->withLeft(new FooterCommandWidget($leftCommand))
            ->withCenter(new FooterCommandWidget($centerCommand))
            ->withRight(new FooterCommandWidget($rightCommand));

        $result = $footer->update(new TickMsg(0.0), Rect::fromSize(80, 3));

        self::assertInstanceOf(BatchCmd::class, $result->command);
        self::assertCount(3, $result->command->commands);
    }

    #[Test]
    public function rightHintsRendersAgainstTheRightEdge(): void
    {
        $frame = new Frame(40, 1);

        Footer::forRightHints(['q' => 'quit'])
            ->render($frame, new Rect(0, 0, 40, 1), $this->context);

        $line = '';
        for ($column = 0; $column < 40; ++$column) {
            $line .= $frame->characters[$column];
        }

        self::assertStringEndsWith('quit', $line, 'The hint finishes at the last column');
        self::assertSame(' ', $frame->characters[0], 'And leaves the left edge empty');
    }

    #[Test]
    public function leftHintsRendersFromColumnZero(): void
    {
        $frame = new Frame(40, 1);

        Footer::forLeftHints(['q' => 'quit'])
            ->render($frame, new Rect(0, 0, 40, 1), $this->context);

        // Left-aligned hint: " q " should start at col 0
        self::assertSame(' ', $frame->characters[0]);
        self::assertSame('q', $frame->characters[1]);
        self::assertSame(' ', $frame->characters[2]);
    }

    #[Test]
    public function textInASlotSitsOnTheBarRatherThanOnThePage(): void
    {
        $theme = Theme::dark()->withCanvas(Color::hex('101010'));
        $frame = new Frame(20, 1);

        Footer::new()->withRight(Text::new('v1'))->render($frame, Rect::fromSize(20, 1), new RenderContext($theme));

        self::assertSame($theme->footerStyle->backgroundValue(), $frame->backgrounds[18]);
    }

    #[Test]
    public function aGrowingLeftSlotTakesTheWholeRowOnceTheRightOneHasGivenWay(): void
    {
        $footer = Footer::new()
            ->withLeft(StatusBar::forHints(['a' => 'one', 'b' => 'two', 'c' => 'three', 'd' => 'four']))
            ->withRight(Text::new('Support'));

        self::assertSame(' a  one   b  two   c  three  …', $this->drawnAt($footer, 32));
    }

    #[Test]
    public function theRightSlotGivesWayToItsShorterFormsBeforeAHintIsLeftOut(): void
    {
        $footer = Footer::new()
            ->withLeft(StatusBar::forHints(['a' => 'one', 'b' => 'two', 'c' => 'three', 'd' => 'four']))
            ->withRight(Text::new('Support · 0.1.0'), Text::new('Support'));

        self::assertSame(' a  one   b  two   c  three   d  four  Support · 0.1.0', $this->drawnAt($footer, 54));
        self::assertSame(' a  one   b  two   c  three   d  four         Support', $this->drawnAt($footer, 53));
        self::assertSame(' a  one   b  two   c  three   d  four', $this->drawnAt($footer, 45));
    }

    #[Test]
    public function theRightSlotMakesRoomForTheHintsOfWhateverHoldsTheKeyboard(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->provideHints(['c' => 'three', 'd' => 'four']);
        $footer = Footer::new()
            ->withLeft(FocusHints::new()->withLeadingHints(['a' => 'one', 'b' => 'two']))
            ->withRight(Text::new('Support · 0.1.0'), Text::new('Support'));
        $frame = new Frame(53, 1);

        $footer->render($frame, Rect::fromSize(53, 1), $pass->context);

        self::assertSame(' a  one   b  two   c  three   d  four         Support', $this->extractText($frame, 0, 53));
    }

    #[Test]
    public function aHostAsksWhereTheRightSlotIsDrawnForTheHintsShown(): void
    {
        $footer = Footer::new()
            ->withLeft(FocusHints::new()->withLeadingHints(['a' => 'one', 'b' => 'two']))
            ->withRight(Text::new('Support · 0.1.0'), Text::new('Support'));
        $area = Rect::fromSize(53, 1);

        self::assertEquals(new Rect(38, 0, 15, 1), $footer->rightSlotArea($area));
        self::assertEquals(new Rect(46, 0, 7, 1), $footer->rightSlotArea($area, ['c' => 'three', 'd' => 'four']));
        self::assertNull($footer->rightSlotArea($area, ['c' => 'three', 'd' => 'four', 'e' => 'five']));
    }

    protected function setUp(): void
    {
        $this->context = new RenderContext(Theme::dark());
    }

    private function drawnAt(Footer $footer, int $width): string
    {
        $frame = new Frame($width, 1);
        $footer->render($frame, Rect::fromSize($width, 1), $this->context);

        return rtrim($this->extractText($frame, 0, $width));
    }

    private function extractText(Frame $frame, int $startX, int $length): string
    {
        $result = '';
        for ($x = $startX; $x < $startX + $length; ++$x) {
            $result .= $frame->character($x);
        }

        return $result;
    }

    private function createInteractiveReturningCommand(Cmd $command): Interactive
    {
        return new class($command) implements Interactive {
            private Cmd $command;
            private bool $updated = false;

            public function __construct(Cmd $command)
            {
                $this->command = $command;
            }

            public function sizing(): Sizing
            {
                return Sizing::of(Constraint::exact(1), Constraint::exact(1));
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                $new = clone $this;
                $new->updated = true;

                return new UpdateResult($new, $this->command);
            }

            public function render(Frame $frame, Rect $area, RenderContext $context): void
            {
            }
        };
    }

    private function createInteractiveReturningSelf(): Interactive
    {
        return new class implements Interactive {
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
            }
        };
    }
}

final readonly class FooterCommandWidget implements Interactive
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

final class FooterAreaCapturingInteractive implements Interactive
{
    public function __construct(private ?Rect &$receivedArea)
    {
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::exact(1), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $this->receivedArea = $area;

        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
    }
}
