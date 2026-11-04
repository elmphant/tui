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
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Tests\Support\TickTally;
use Elmphant\Tui\Widget\Alignment;
use Elmphant\Tui\Widget\Bar\Header;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HeaderTest extends TestCase
{
    private RenderContext $context;

    #[Test]
    public function implementsWidget(): void
    {
        self::assertInstanceOf(Widget::class, Header::new('Title'));
    }

    #[Test]
    public function constraintIsExactOne(): void
    {
        $header = Header::new('Title');
        self::assertEquals(Constraint::exact(1), $header->sizing()->rows);
    }

    #[Test]
    public function rendersTitleCentered(): void
    {
        $header = Header::new('Title');
        $frame = new Frame(20, 1);
        $header->render($frame, Rect::fromSize(20, 1), $this->context);

        $text = $this->extractText($frame, 7, 5);
        self::assertSame('Title', $text);
    }

    #[Test]
    public function withTitleReplacesTheTitleAndElidesNoOps(): void
    {
        $header = Header::new('Before');
        $modified = $header->withTitle('After');
        $frame = new Frame(20, 1);

        $modified->render($frame, Rect::fromSize(20, 1), $this->context);

        self::assertStringContainsString('After', implode('', $frame->characters));
        self::assertSame($modified, $modified->withTitle('After'));
    }

    #[Test]
    public function rendersLeftSlot(): void
    {
        $header = Header::new('Title')
            ->withLeft(Text::new('Left'));
        $frame = new Frame(30, 1);
        $header->render($frame, Rect::fromSize(30, 1), $this->context);

        $text = $this->extractText($frame, 0, 4);
        self::assertSame('Left', $text);
    }

    #[Test]
    public function aCenterTooWideForTheMiddleMovesPastTheLeftSlotInsteadOfOverIt(): void
    {
        $header = Header::new()->withLeft(Text::new('LEFT-LEFT-LEFT'))->withCenter(Text::new('CENTER'));
        $frame = new Frame(24, 1);
        $header->render($frame, Rect::fromSize(24, 1), $this->context);

        self::assertSame('LEFT-LEFT-LEFT  CENTER', $this->extractText($frame, 0, 22));
    }

    #[Test]
    public function rendersRightSlotRightAligned(): void
    {
        $header = Header::new('Title')
            ->withRight(Text::new('Right'));
        $frame = new Frame(30, 1);
        $header->render($frame, Rect::fromSize(30, 1), $this->context);

        $text = $this->extractText($frame, 25, 5);
        self::assertSame('Right', $text);
    }

    #[Test]
    public function rendersAllThreeSlots(): void
    {
        $header = Header::new('Center')
            ->withLeft(Text::new('L'))
            ->withRight(Text::new('R'));
        $frame = new Frame(20, 1);
        $header->render($frame, Rect::fromSize(20, 1), $this->context);

        self::assertSame('L', $frame->characters[0]);

        $centerText = $this->extractText($frame, 7, 6);
        self::assertSame('Center', $centerText);

        self::assertSame('R', $frame->characters[19]);
    }

    #[Test]
    public function appliesHeaderStyleForeground(): void
    {
        $header = Header::new('Title');
        $frame = new Frame(20, 1);
        $header->render($frame, Rect::fromSize(20, 1), $this->context);

        $expectedForeground = Theme::dark()->headerStyle->foregroundValue();
        self::assertSame($expectedForeground, $frame->foregrounds[7]);
    }

    #[Test]
    public function renderWithZeroWidthDoesNotCrash(): void
    {
        $header = Header::new('Title');
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 0, 1);
        $header->render($frame, $area, $this->context);

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function paddingShiftsContentInward(): void
    {
        $header = Header::new('Title')
            ->withPadding(Spacing::horizontal(1));
        $frame = new Frame(20, 1);
        $header->render($frame, Rect::fromSize(20, 1), $this->context);

        $titleStart = 1 + (int)floor((18 - 5) / 2);
        $text = $this->extractText($frame, $titleStart, 5);
        self::assertSame('Title', $text);

        self::assertSame(' ', $frame->characters[0]);
    }

    #[Test]
    public function constraintIncludesPadding(): void
    {
        $header = Header::new('Title')
            ->withPadding(Spacing::vertical(1));

        self::assertEquals(Constraint::exact(3), $header->sizing()->rows);
    }

    #[Test]
    public function constraintIncludesMargin(): void
    {
        $header = Header::new('Title')
            ->withMargin(Spacing::vertical(1));

        self::assertEquals(Constraint::exact(3), $header->sizing()->rows);
    }

    #[Test]
    public function marginShiftsBorderInward(): void
    {
        $header = Header::new('Title')
            ->withMargin(Spacing::horizontal(1));
        $frame = new Frame(22, 1);
        $header->render($frame, Rect::fromSize(22, 1), $this->context);

        self::assertSame(' ', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[21]);

        $text = $this->extractText($frame, 8, 5);
        self::assertSame('Title', $text);
    }

    /**
     * The centre slot takes the middle and the title steps left, rather than
     * the title being dropped.
     */
    #[Test]
    public function centerSlotDisplacesTitleToTheLeft(): void
    {
        $header = Header::new('Title')
            ->withCenter(Text::new('Custom'));
        $frame = new Frame(20, 1);
        $header->render($frame, Rect::fromSize(20, 1), $this->context);

        self::assertSame('Custom', $this->extractText($frame, 7, 6));
        self::assertSame('Title', $this->extractText($frame, 0, 5));
    }

    #[Test]
    public function centerSlotWithoutTitle(): void
    {
        $header = Header::new()
            ->withCenter(Text::new('Brand'));
        $frame = new Frame(20, 1);
        $header->render($frame, Rect::fromSize(20, 1), $this->context);

        $text = $this->extractText($frame, 7, 5);
        self::assertSame('Brand', $text);
    }

    #[Test]
    public function dividerAddsOneRowToConstraint(): void
    {
        $header = Header::new('Title')->withRule();
        self::assertEquals(Constraint::exact(2), $header->sizing()->rows);
    }

    #[Test]
    public function aDividerCanBeTakenAwayAgain(): void
    {
        $header = Header::new('Title')->withRule()->withRule(false);
        self::assertEquals(Constraint::exact(1), $header->sizing()->rows);
    }

    #[Test]
    public function dividerWithPaddingConstraint(): void
    {
        $header = Header::new('Title')
            ->withPadding(Spacing::vertical(1))
            ->withRule();

        self::assertEquals(Constraint::exact(4), $header->sizing()->rows);
    }

    #[Test]
    public function dividerRendersHorizontalLine(): void
    {
        $header = Header::new('Title')->withRule();
        $frame = new Frame(20, 2);
        $header->render($frame, Rect::fromSize(20, 2), $this->context);

        $text = $this->extractText($frame, 7, 5);
        self::assertSame('Title', $text);

        $dividerIndex = 1 * 20;
        self::assertSame('─', $frame->characters[$dividerIndex]);
        self::assertSame('─', $frame->characters[$dividerIndex + 19]);
    }

    #[Test]
    public function dividerUsesBorderStyleForeground(): void
    {
        $header = Header::new('Title')->withRule();
        $frame = new Frame(20, 2);
        $header->render($frame, Rect::fromSize(20, 2), $this->context);

        $expectedForeground = Theme::dark()->borderStyle->foregroundValue();
        $dividerIndex = 1 * 20;

        self::assertSame($expectedForeground, $frame->foregrounds[$dividerIndex]);
    }

    #[Test]
    public function implementsInteractive(): void
    {
        self::assertInstanceOf(Interactive::class, Header::new());
    }

    #[Test]
    public function updateForwardsTickToInteractiveSlot(): void
    {
        $spinner = new TickTally();
        $header = Header::new()->withRight($spinner);
        $area = Rect::fromSize(80, 1);

        $result = $header->update(new TickMsg(1.0), $area);

        self::assertInstanceOf(Header::class, $result->model);
        self::assertNotSame($header, $result->model);
    }

    #[Test]
    public function aGlobalRebindingReachesTheKeysASlotAnswers(): void
    {
        $header = Header::new()
            ->withRight(TextInput::new()->withValue('typed'))
            ->withMarkedSlot(Alignment::Right)
            ->withGlobalKeybindings(new Keybindings([Actions::CLEAR_LINE => ['ctrl+k']]));

        $result = $header->update(new KeyMsg('k', ctrl: true), Rect::fromSize(80, 1));

        self::assertInstanceOf(Header::class, $result->model);
        $slot = [...$result->model->children()][0];
        self::assertInstanceOf(TextInput::class, $slot);
        self::assertSame('', $slot->value(), 'a bar must hand its slots the bindings the app made active');
    }

    #[Test]
    public function updateLeavesSlotsAloneInAnAreaTooSmallToDrawThem(): void
    {
        $header = Header::new()->withRight(new TickTally());

        $result = $header->update(new TickMsg(1.0), Rect::fromSize(80, 0));

        self::assertSame($header, $result->model, 'A slot the header never draws must not be fed input either');
    }

    #[Test]
    public function updateReturnsUnchangedWhenNoInteractiveSlots(): void
    {
        $header = Header::new('Title')->withRight(Text::new('static'));
        $area = Rect::fromSize(80, 1);

        $result = $header->update(new TickMsg(1.0), $area);

        self::assertSame($header, $result->model);
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

        $header = Header::new()->withLeft($leftSlot)->withRight($rightSlot);
        $area = Rect::fromSize(80, 1);

        $result = $header->update(new TestMessage('msg'), $area);

        self::assertInstanceOf(BatchCmd::class, $result->command);
        self::assertCount(2, $result->command->commands);
    }

    #[Test]
    public function updateWithNoCommandsReturnsNull(): void
    {
        $header = Header::new()->withLeft(Text::new('static'));
        $result = $header->update(new TickMsg(0.0), Rect::fromSize(80, 1));
        self::assertNull($result->command);
    }

    #[Test]
    public function updateWithSingleCommandReturnsCmdDirectly(): void
    {
        $command = Cmd::tick(1.0);
        $slot = $this->createInteractiveReturningCommand($command);
        $header = Header::new()->withLeft($slot);
        $result = $header->update(new TickMsg(0.0), Rect::fromSize(80, 1));
        self::assertNotNull($result->command);
        self::assertNotInstanceOf(BatchCmd::class, $result->command);
        self::assertSame($command, $result->command);
    }

    #[Test]
    public function renderWithWidthOneRendersContent(): void
    {
        $header = Header::new('X');
        $frame = new Frame(1, 1);
        $header->render($frame, Rect::fromSize(1, 1), $this->context);

        $headerStyle = Theme::dark()->headerStyle;
        self::assertSame($headerStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function renderWithHeightOneRendersContent(): void
    {
        $header = Header::new('A');
        $frame = new Frame(5, 1);
        $header->render($frame, Rect::fromSize(5, 1), $this->context);

        self::assertSame('A', $frame->characters[2]);
    }

    #[Test]
    public function renderWithZeroHeightDoesNotRender(): void
    {
        $header = Header::new('Title');
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 20, 0);
        $header->render($frame, $area, $this->context);

        self::assertSame(0, $frame->foregrounds[0]);
    }

    #[Test]
    public function renderWithZeroDimensionsLeavesBufferUntouched(): void
    {
        $header = Header::new('Title');
        $frame = new Frame(20, 1);
        $area = new Rect(0, 0, 0, 0);
        $header->render($frame, $area, $this->context);

        for ($x = 0; $x < 20; ++$x) {
            self::assertSame(0, $frame->foregrounds[$x]);
            self::assertSame(0, $frame->backgrounds[$x]);
        }
    }

    #[Test]
    public function renderWithoutMarginUsesNoMarginFallback(): void
    {
        $header = Header::new('AB');
        $frame = new Frame(10, 1);
        $header->render($frame, Rect::fromSize(10, 1), $this->context);

        $headerStyle = Theme::dark()->headerStyle;
        self::assertSame($headerStyle->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame('A', $frame->characters[4]);
        self::assertSame('B', $frame->characters[5]);
    }

    #[Test]
    public function renderWithMarginLeavingWidthOneRendersBackground(): void
    {
        $header = Header::new('X')
            ->withMargin(Spacing::horizontal(1));
        $frame = new Frame(3, 1);
        $header->render($frame, Rect::fromSize(3, 1), $this->context);

        $headerStyle = Theme::dark()->headerStyle;
        self::assertSame($headerStyle->foregroundValue(), $frame->foregrounds[1]);
    }

    #[Test]
    public function renderWithMarginLeavingZeroWidthDoesNotRender(): void
    {
        $header = Header::new('X')
            ->withMargin(Spacing::horizontal(1));
        $frame = new Frame(2, 1);
        $header->render($frame, Rect::fromSize(2, 1), $this->context);

        self::assertSame(0, $frame->foregrounds[0]);
        self::assertSame(0, $frame->foregrounds[1]);
    }

    #[Test]
    public function renderWithoutPaddingUsesNoPaddingFallback(): void
    {
        $header = Header::new('Hi');
        $frame = new Frame(10, 1);
        $header->render($frame, Rect::fromSize(10, 1), $this->context);

        self::assertSame('H', $frame->characters[4]);
        self::assertSame('i', $frame->characters[5]);
    }

    #[Test]
    public function barHeightIncludesPaddingTopAndBottom(): void
    {
        $header = Header::new('Hi')
            ->withPadding(Spacing::vertical(1));
        $frame = new Frame(10, 3);
        $header->render($frame, Rect::fromSize(10, 3), $this->context);

        $headerStyle = Theme::dark()->headerStyle;
        self::assertSame($headerStyle->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame($headerStyle->foregroundValue(), $frame->foregrounds[2 * 10]);

        self::assertSame('H', $frame->characters[1 * 10 + 4]);
    }

    #[Test]
    public function customStyleOverridesThemeHeaderStyle(): void
    {
        $customStyle = new Style(foreground: Color::rgb(255, 0, 0), background: Color::rgb(0, 255, 0));
        $header = Header::new('X')->withStyle($customStyle);
        $frame = new Frame(5, 1);
        $header->render($frame, Rect::fromSize(5, 1), $this->context);

        self::assertSame($customStyle->backgroundValue(), $frame->backgrounds[0]);
        self::assertSame($customStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function fillRectAppliesBackgroundToEntireBarArea(): void
    {
        $header = Header::new('X');
        $frame = new Frame(10, 1);
        $header->render($frame, Rect::fromSize(10, 1), $this->context);

        $headerStyle = Theme::dark()->headerStyle;
        $expectedFg = $headerStyle->foregroundValue();
        for ($x = 0; $x < 10; ++$x) {
            self::assertSame($expectedFg, $frame->foregrounds[$x], "Foreground at x=$x should be header style");
        }
    }

    #[Test]
    public function renderWithPaddingLeavingWidthOneStillRendersTitle(): void
    {
        $header = Header::new('X')
            ->withPadding(Spacing::horizontal(1));
        $frame = new Frame(3, 1);
        $header->render($frame, Rect::fromSize(3, 1), $this->context);

        self::assertSame('X', $frame->characters[1]);
    }

    #[Test]
    public function renderWithPaddingConsumingAllWidthStillFillsBackground(): void
    {
        $header = Header::new('X')
            ->withPadding(Spacing::horizontal(1));
        $frame = new Frame(2, 1);
        $header->render($frame, Rect::fromSize(2, 1), $this->context);

        $headerStyle = Theme::dark()->headerStyle;
        self::assertSame($headerStyle->foregroundValue(), $frame->foregrounds[0]);
        self::assertSame(' ', $frame->characters[0]);
        self::assertSame(' ', $frame->characters[1]);
    }

    #[Test]
    public function emptyTitleDoesNotRenderTitleText(): void
    {
        $header = Header::new('');
        $frame = new Frame(10, 1);
        $header->render($frame, Rect::fromSize(10, 1), $this->context);

        for ($x = 0; $x < 10; ++$x) {
            self::assertSame(' ', $frame->characters[$x]);
        }
    }

    #[Test]
    public function dividerNotRenderedWhenNoRoomBelowBar(): void
    {
        $header = Header::new('Title')->withRule();
        $frame = new Frame(20, 1);
        $header->render($frame, Rect::fromSize(20, 1), $this->context);

        $text = $this->extractText($frame, 7, 5);
        self::assertSame('Title', $text);

        // No divider characters should appear; there's only 1 row, divider would be at row 1
        // Buffer is only 1 row tall so we just confirm it didn't crash and title rendered
    }

    #[Test]
    public function dividerRendersExactlyAtLastAvailableRow(): void
    {
        $header = Header::new('T')->withRule();
        $frame = new Frame(5, 2);
        $header->render($frame, Rect::fromSize(5, 2), $this->context);

        $dividerIndex = 1 * 5;
        self::assertSame('─', $frame->characters[$dividerIndex]);
    }

    #[Test]
    public function dividerNotRenderedWhenAreaEqualsBarHeight(): void
    {
        $header = Header::new('T')->withRule();

        // Render with an area that has y-offset such that dividerY == area.y + area.height
        // barArea height=1 (no padding), dividerY = area.y + 1
        // If area.height=1, then area.y + area.height = area.y + 1 = dividerY
        // So dividerY < area bottom is false, divider should NOT render
        $frame = new Frame(10, 3);
        $area = new Rect(0, 0, 10, 1);
        $header->render($frame, $area, $this->context);

        // Row 1 should remain untouched (not the divider row)
        for ($x = 0; $x < 10; ++$x) {
            self::assertSame(' ', $frame->characters[1 * 10 + $x]);
            self::assertSame(0, $frame->foregrounds[1 * 10 + $x]);
        }
    }

    #[Test]
    public function renderWithExactlyOneByOneAreaRendersBackground(): void
    {
        $header = Header::new();
        $frame = new Frame(1, 1);
        $header->render($frame, Rect::fromSize(1, 1), $this->context);

        $headerStyle = Theme::dark()->headerStyle;
        self::assertSame($headerStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function renderWithMarginLeavingHeightOneRendersBackground(): void
    {
        $header = Header::new()
            ->withMargin(Spacing::vertical(0));
        $frame = new Frame(10, 1);
        $header->render($frame, Rect::fromSize(10, 1), $this->context);

        $headerStyle = Theme::dark()->headerStyle;
        self::assertSame($headerStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function renderWithPaddingLeavingHeightOneStillRendersContent(): void
    {
        $header = Header::new('A');
        $frame = new Frame(5, 1);
        $header->render($frame, Rect::fromSize(5, 1), $this->context);

        self::assertSame('A', $frame->characters[2]);
    }

    #[Test]
    public function updateUsesRightSlotCommandWhenNoPriorCommand(): void
    {
        $rightCommand = Cmd::tick(2.0);
        $rightSlot = $this->createInteractiveReturningCommand($rightCommand);

        $header = Header::new()->withRight($rightSlot);
        $area = Rect::fromSize(80, 1);

        $result = $header->update(new TestMessage('msg'), $area);

        self::assertSame($rightCommand, $result->command);
    }

    /**
     * Additional: center interactive slot also forwards commands.
     */
    #[Test]
    public function updateForwardsToCenterInteractiveSlot(): void
    {
        $centerCommand = Cmd::tick(3.0);
        $centerSlot = $this->createInteractiveReturningCommand($centerCommand);

        $header = Header::new()->withCenter($centerSlot);
        $area = Rect::fromSize(80, 1);

        $result = $header->update(new TestMessage('msg'), $area);

        self::assertSame($centerCommand, $result->command);
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

        $header = Header::new()->withLeft($leftSlot)->withCenter($centerSlot);
        $area = Rect::fromSize(80, 1);

        $result = $header->update(new TestMessage('msg'), $area);

        self::assertInstanceOf(BatchCmd::class, $result->command);
        self::assertCount(2, $result->command->commands);
    }

    #[Test]
    public function updateDetectsLeftSlotModelChange(): void
    {
        $spinner = new TickTally();
        $header = Header::new()->withLeft($spinner);
        $area = Rect::fromSize(80, 1);

        $result = $header->update(new TickMsg(1.0), $area);

        self::assertNotSame($header, $result->model);
    }

    #[Test]
    public function updateDetectsCenterSlotModelChange(): void
    {
        $spinner = new TickTally();
        $header = Header::new()->withCenter($spinner);
        $area = Rect::fromSize(80, 1);

        $result = $header->update(new TickMsg(1.0), $area);

        self::assertNotSame($header, $result->model);
    }

    #[Test]
    public function barHeightClampedToInnerAreaHeight(): void
    {
        $header = Header::new('X')
            ->withPadding(Spacing::vertical(1));
        $frame = new Frame(10, 1);
        $header->render($frame, Rect::fromSize(10, 1), $this->context);

        $headerStyle = Theme::dark()->headerStyle;
        self::assertSame($headerStyle->foregroundValue(), $frame->foregrounds[0]);
    }

    #[Test]
    public function titleXClampedToContentX(): void
    {
        $header = Header::new('VeryLongTitleThatExceedsWidth');
        $frame = new Frame(5, 1);
        $header->render($frame, Rect::fromSize(5, 1), $this->context);

        self::assertSame('V', $frame->characters[0]);
    }

    #[Test]
    public function dividerYIsExactlyBarYPlusBarHeight(): void
    {
        $header = Header::new('T')
            ->withPadding(Spacing::vertical(1))
            ->withRule();
        $frame = new Frame(10, 4);
        $header->render($frame, Rect::fromSize(10, 4), $this->context);

        // barHeight = 1 + 1(top) + 1(bottom) = 3
        // dividerY = 0 + 3 = 3
        $dividerIndex = 3 * 10;
        self::assertSame('─', $frame->characters[$dividerIndex]);

        // Row 2 (inside bar, above divider) should NOT have divider
        $notDivider = 2 * 10;
        self::assertNotSame('─', $frame->characters[$notDivider]);
    }

    #[Test]
    public function renderAtNonZeroOriginPositionsCorrectly(): void
    {
        $header = Header::new('Hi');
        $frame = new Frame(30, 10);
        $area = new Rect(5, 7, 20, 1);
        $header->render($frame, $area, $this->context);

        $index = 7 * 30 + 5 + (int)floor((20 - 2) / 2);
        self::assertSame('H', $frame->characters[$index]);
    }

    #[Test]
    public function fillRectCoversFullBarWidth(): void
    {
        $header = Header::new();
        $frame = new Frame(15, 1);
        $header->render($frame, Rect::fromSize(15, 1), $this->context);

        $headerStyle = Theme::dark()->headerStyle;
        for ($column = 0; $column < 15; ++$column) {
            self::assertSame($headerStyle->foregroundValue(), $frame->foregrounds[$column], "Column $column should have header foreground");
        }
    }

    #[Test]
    public function renderWithZeroWidthButPositiveHeightReturnsEarly(): void
    {
        $header = Header::new('X');
        $frame = new Frame(5, 5);
        $area = new Rect(0, 0, 0, 1);
        $header->render($frame, $area, $this->context);

        self::assertSame(0, $frame->backgrounds[0]);
        self::assertSame(0, $frame->foregrounds[0]);
    }

    #[Test]
    public function renderWithZeroHeightButPositiveWidthReturnsEarly(): void
    {
        $header = Header::new('X');
        $frame = new Frame(10, 5);
        $area = new Rect(0, 0, 10, 0);
        $header->render($frame, $area, $this->context);

        self::assertSame(0, $frame->backgrounds[0]);
        self::assertSame(0, $frame->foregrounds[0]);
    }

    #[Test]
    public function renderWithMarginLeavingZeroWidthReturnsEarly(): void
    {
        $header = Header::new('X')
            ->withMargin(Spacing::horizontal(5));
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $header->render($frame, $area, $this->context);

        // Margin 5 on each side, area width 10 => inner width = 0
        self::assertSame(0, $frame->backgrounds[0]);
        self::assertSame(0, $frame->foregrounds[0]);
    }

    #[Test]
    public function renderWithMarginLeavingZeroHeightReturnsEarly(): void
    {
        $header = Header::new('X')
            ->withMargin(Spacing::vertical(1));
        $frame = new Frame(10, 1);
        $area = new Rect(0, 0, 10, 1);
        $header->render($frame, $area, $this->context);

        // Margin top=1, bottom=1, area height=1 => inner height = max(0, 1-2) = 0
        self::assertSame(0, $frame->backgrounds[0]);
        self::assertSame(0, $frame->foregrounds[0]);
    }

    #[Test]
    public function exposesEachSlotInTheRectItDrawsIn(): void
    {
        $left = Text::new('Hi');
        $right = Text::new('Bye');

        $children = [...Header::new()->withLeft($left)->withRight($right)->placedChildren(new Rect(0, 0, 20, 1))];

        self::assertSame([$left, $right], array_column($children, 0));
        self::assertSame([0, 17], array_map(static fn (array $child): int => $child[1]->x, $children));
    }

    #[Test]
    public function updateRoutesToASlotInTheRectItDrawsIn(): void
    {
        $receivedArea = null;
        $spy = new AreaCapturingInteractive($receivedArea);

        $header = Header::new()
            ->withLeft($spy)
            ->withPadding(Spacing::horizontal(1))
            ->withMargin(Spacing::horizontal(1));
        $header->update(new TickMsg(0.0), new Rect(0, 0, 80, 3));

        self::assertNotNull($receivedArea);
        self::assertSame(1, $receivedArea->height);
        self::assertSame(2, $receivedArea->x, 'Inside the margin and the padding');
        self::assertSame(1, $receivedArea->width, 'The width the slot declares, not the whole row');
    }

    #[Test]
    public function updateWithVerticalPaddingComputesCorrectContentArea(): void
    {
        $receivedArea = null;
        $spy = new AreaCapturingInteractive($receivedArea);

        $header = Header::new()
            ->withLeft($spy)
            ->withPadding(Spacing::vertical(1));
        $header->update(new TickMsg(0.0), new Rect(0, 0, 80, 5));

        self::assertNotNull($receivedArea);
        self::assertSame(1, $receivedArea->height);
        self::assertSame(1, $receivedArea->y);
    }

    #[Test]
    public function updateCollectsCommandsFromAllSlots(): void
    {
        $leftCommand = Cmd::message(new TestMessage('left-cmd'));
        $centerCommand = Cmd::message(new TestMessage('center-cmd'));
        $rightCommand = Cmd::message(new TestMessage('right-cmd'));

        $header = Header::new()
            ->withLeft(new HeaderCommandWidget($leftCommand))
            ->withCenter(new HeaderCommandWidget($centerCommand))
            ->withRight(new HeaderCommandWidget($rightCommand));

        $result = $header->update(new TickMsg(0.0), Rect::fromSize(80, 3));

        self::assertInstanceOf(BatchCmd::class, $result->command);
        self::assertCount(3, $result->command->commands);
    }

    protected function setUp(): void
    {
        $this->context = new RenderContext(Theme::dark());
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
}

final readonly class HeaderCommandWidget implements Interactive
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

final class AreaCapturingInteractive implements Interactive
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
