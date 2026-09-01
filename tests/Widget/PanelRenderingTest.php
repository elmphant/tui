<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Prose\Separator;
use Elmphant\Tui\Widget\Prose\Spacer;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PanelRenderingTest extends TestCase
{
    #[Test]
    public function followsAChildBelowTheFoldIntoView(): void
    {
        $panel = $this->numberedPanel(20)->withFollowChild(15);
        $area = new Rect(0, 0, 20, 6);
        $frame = new Frame(20, 6);

        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertStringContainsString('row-15', $this->text($frame, 20, 6), 'The followed child must be on screen');
    }

    #[Test]
    public function followingKeepsTheWholeFollowedRangeVisible(): void
    {
        $panel = $this->numberedPanel(20)->withFollowChild(15, 3);
        $area = new Rect(0, 0, 20, 6);
        $frame = new Frame(20, 6);

        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        $text = $this->text($frame, 20, 6);
        self::assertStringContainsString('row-15', $text);
        self::assertStringContainsString('row-17', $text, 'The rows that belong with it come too');
    }

    #[Test]
    public function scrollingByHandStopsFollowing(): void
    {
        $panel = $this->numberedPanel(20)->withFollowChild(15)->withScrollOffset(0);
        $area = new Rect(0, 0, 20, 6);
        $frame = new Frame(20, 6);

        $panel->render($frame, $area, new RenderContext(Theme::dark()));

        self::assertStringContainsString('row-0', $this->text($frame, 20, 6));
    }

    #[Test]
    public function followingAChildAlreadyInViewScrollsNothing(): void
    {
        $followed = new Frame(20, 6);
        $plain = new Frame(20, 6);
        $area = new Rect(0, 0, 20, 6);
        $context = new RenderContext(Theme::dark());

        $this->numberedPanel(20)->withFollowChild(1)->render($followed, $area, $context);
        $this->numberedPanel(20)->render($plain, $area, $context);

        self::assertSame($this->text($plain, 20, 6), $this->text($followed, 20, 6));
    }

    #[Test]
    public function childPositionsPreservedAfterFocusToggle(): void
    {
        $panel = Panel::new('Test')
            ->add(Text::new('Header'))
            ->add(Spacer::new())
            ->add(Select::new(['Alpha', 'Beta', 'Gamma']))
            ->add(Spacer::new())
            ->add(Separator::new())
            ->add(Spacer::new())
            ->add(Text::new('Footer'));

        $area = new Rect(0, 0, 30, 15);

        $focusedPanel = $panel->withFocus(true);
        $focusedBuffer = new Frame(30, 15);
        $context = new RenderContext(Theme::dark());
        $focusedPanel->render($focusedBuffer, $area, $context);

        $unfocusedPanel = $panel->withFocus(false);
        $unfocusedBuffer = new Frame(30, 15);
        $unfocusedPanel->render($unfocusedBuffer, $area, $context);

        // Content area: inner(1) = Rect(1, 1, 28, 13)
        // Row 1: "Header"
        // Row 2: Spacer (empty)
        // Row 3: Select option 0 ("Alpha")
        // Row 4: Select option 1 ("Beta")
        // Row 5: Select option 2 ("Gamma")
        // Row 6: Spacer
        // Row 7: Separator (─ characters)
        // Row 8: Spacer
        // Row 9: "Footer"

        self::assertSame('H', $focusedBuffer->characters[1 * 30 + 1], 'Focused: Header at row 1');
        self::assertSame('H', $unfocusedBuffer->characters[1 * 30 + 1], 'Unfocused: Header at row 1');

        // Select options should be at rows 3-5 in both states
        // Option text "Alpha" starts at column 1 (content area x, no prefix)
        self::assertSame('A', $focusedBuffer->characters[3 * 30 + 1], 'Focused: Alpha at row 3');
        self::assertSame('A', $unfocusedBuffer->characters[3 * 30 + 1], 'Unfocused: Alpha at row 3');

        // Separator at row 7 should be ─ characters
        self::assertSame('─', $focusedBuffer->characters[7 * 30 + 1], 'Focused: Separator at row 7');
        self::assertSame('─', $unfocusedBuffer->characters[7 * 30 + 1], 'Unfocused: Separator at row 7');

        // Footer at row 9
        self::assertSame('F', $focusedBuffer->characters[9 * 30 + 1], 'Focused: Footer at row 9');
        self::assertSame('F', $unfocusedBuffer->characters[9 * 30 + 1], 'Unfocused: Footer at row 9');
    }

    #[Test]
    public function twoPanelFocusSwitchPreservesLayout(): void
    {
        $left = Panel::new('Settings')
            ->add(Text::new('Theme:'))
            ->add(Select::new(['Dark', 'Light']))
            ->add(Separator::new())
            ->add(Text::new('Size:'))
            ->add(Select::new(['Small', 'Medium', 'Large']))
            ->withFocus(true);

        $right = Panel::new('Status')
            ->add(Text::new('Info line 1'))
            ->add(Text::new('Info line 2'))
            ->withFocus(false);

        $leftRect = new Rect(0, 0, 30, 15);
        $rightRect = new Rect(30, 0, 30, 15);

        $context = new RenderContext(Theme::dark());

        // Render initial state
        $bufferBefore = new Frame(60, 15);
        $left->render($bufferBefore, $leftRect, $context);
        $right->render($bufferBefore, $rightRect, $context);

        $newLeft = $left->withFocus(false);
        $newRight = $right->withFocus(true);

        // Render after Tab
        $bufferAfter = new Frame(60, 15);
        $newLeft->render($bufferAfter, $leftRect, $context);
        $newRight->render($bufferAfter, $rightRect, $context);

        // Left panel content should still be at correct positions
        // Content area: Rect(1, 1, 28, 13)
        // Row 1: "Theme:"
        self::assertSame('T', $bufferAfter->characters[1 * 60 + 1], 'Left: Theme at row 1 after Tab');
        // Row 2-3: Select (Dark, Light) — option text starts at column 1 (no prefix)
        self::assertSame('D', $bufferAfter->characters[2 * 60 + 1], 'Left: Dark at row 2 after Tab');
        self::assertSame('L', $bufferAfter->characters[3 * 60 + 1], 'Left: Light at row 3 after Tab');
        // Row 4: Separator
        self::assertSame('─', $bufferAfter->characters[4 * 60 + 1], 'Left: Separator at row 4 after Tab');
        // Row 5: "Size:"
        self::assertSame('S', $bufferAfter->characters[5 * 60 + 1], 'Left: Size at row 5 after Tab');
        // Row 6-8: Select (Small, Medium, Large) — option text at col 1 (no prefix)
        self::assertSame('S', $bufferAfter->characters[6 * 60 + 1], 'Left: Small at row 6 after Tab');
        self::assertSame('M', $bufferAfter->characters[7 * 60 + 1], 'Left: Medium at row 7 after Tab');
        self::assertSame('L', $bufferAfter->characters[8 * 60 + 1], 'Left: Large at row 8 after Tab');

        // Right panel content should be at correct positions
        // Content area: Rect(31, 1, 28, 13)
        self::assertSame('I', $bufferAfter->characters[1 * 60 + 31], 'Right: Info line 1 at row 1 after Tab');
        self::assertSame('I', $bufferAfter->characters[2 * 60 + 31], 'Right: Info line 2 at row 2 after Tab');
    }

    #[Test]
    public function repeatedFocusTogglingPreservesChildren(): void
    {
        $panel = Panel::new('Test')
            ->add(Text::new('Line A'))
            ->add(Select::new(['X', 'Y', 'Z']))
            ->add(Text::new('Line B'));

        $area = new Rect(0, 0, 30, 12);

        // Toggle focus several times
        $current = $panel->withFocus(true);
        for ($iteration = 0; $iteration < 5; ++$iteration) {
            $current = $current->withFocus(false);
            $current = $current->withFocus(true);
        }

        $frame = new Frame(30, 12);
        $current->render($frame, $area, new RenderContext(Theme::dark()));

        // Content should still be intact
        self::assertSame('L', $frame->characters[1 * 30 + 1], 'Line A at row 1');
        // Select starts at row 2 with cursor on first option (no prefix)
        self::assertSame('X', $frame->characters[2 * 30 + 1], 'X option at row 2');
        self::assertSame('Y', $frame->characters[3 * 30 + 1], 'Y option at row 3');
        self::assertSame('Z', $frame->characters[4 * 30 + 1], 'Z option at row 4');
        self::assertSame('L', $frame->characters[5 * 30 + 1], 'Line B at row 5');
    }

    #[Test]
    public function contentDoesNotBleedOutsideBorder(): void
    {
        $panel = Panel::new('Test');
        for ($index = 0; $index < 20; ++$index) {
            $panel = $panel->add(Text::new("Line {$index}"));
        }
        $panel = $panel->withFocus(true);

        $context = new RenderContext(Theme::dark());
        $area = new Rect(0, 0, 30, 10);
        $frame = new Frame(30, 10);
        $panel->render($frame, $area, $context);

        self::assertSame('╰', $frame->characters[9 * 30 + 0], 'Bottom-left corner');
        self::assertSame('─', $frame->characters[9 * 30 + 1], 'Bottom border line');
        self::assertSame('╯', $frame->characters[9 * 30 + 29], 'Bottom-right corner');

        self::assertSame('╭', $frame->characters[0], 'Top-left corner');
        self::assertSame('╮', $frame->characters[29], 'Top-right corner');
    }

    #[Test]
    public function scrolledContentDoesNotOverwriteBottomBorder(): void
    {
        $panel = Panel::new('Test')
            ->add(Text::new('Line 1'))
            ->add(Text::new('Line 2'))
            ->add(Select::new(['A', 'B', 'C', 'D', 'E']))
            ->add(Text::new('After'))
            ->withFocus(true);

        // Panel height 10 -> content area height 8 (rows 1-8), border at row 9
        $context = new RenderContext(Theme::dark());
        $area = new Rect(0, 0, 30, 10);

        $scrolledPanel = $this->scrollPanel($panel, $area, 1);

        $frame = new Frame(30, 10);
        $scrolledPanel->render($frame, $area, $context);

        self::assertSame('╰', $frame->characters[9 * 30 + 0], 'Bottom-left corner after scroll');
        self::assertSame('╯', $frame->characters[9 * 30 + 29], 'Bottom-right corner after scroll');
    }

    #[Test]
    public function scrolledMultiRowWidgetDoesNotOverwriteTopBorder(): void
    {
        // Content area height 5 (panel height 7), Select(5) starts at row 1
        // After scrolling 2, Select childY = 1-2 = -1, renders at rows -1 through 3
        // Row 0 (top border) must NOT be overwritten by Select content
        $panel = Panel::new('Test')
            ->add(Text::new('Header'))
            ->add(Select::new(['A', 'B', 'C', 'D', 'E']))
            ->withFocus(true);

        $context = new RenderContext(Theme::dark());
        $area = new Rect(0, 0, 30, 7);
        $scrolledPanel = $this->scrollPanel($panel, $area, 2);

        $frame = new Frame(30, 7);
        $scrolledPanel->render($frame, $area, $context);

        self::assertSame('╭', $frame->characters[0], 'Top-left corner after deep scroll');
        self::assertSame('╮', $frame->characters[29], 'Top-right corner after deep scroll');
        // Top border horizontal line (after title "Test" which occupies positions 1-6)
        self::assertSame('─', $frame->characters[7], 'Top border line after deep scroll');
    }

    #[Test]
    public function focusSwitchDoesNotCorruptBorderRows(): void
    {
        $panel = Panel::new('Settings')
            ->add(Text::new('Header'))
            ->add(Select::new(['A', 'B', 'C', 'D', 'E']))
            ->add(Separator::new())
            ->add(Select::new(['X', 'Y', 'Z']))
            ->withFocus(true);

        $area = new Rect(0, 0, 30, 15);

        // Render focused
        $buffer1 = new Frame(30, 15);
        $panel->render($buffer1, $area, RenderContext::focused(Theme::dark()));

        // Toggle to unfocused
        $unfocused = $panel->withFocus(false);
        $buffer2 = new Frame(30, 15);
        $unfocused->render($buffer2, $area, RenderContext::unfocused(Theme::dark()));

        // Focus keeps the rounded shape and marks itself in bold
        self::assertSame('╭', $buffer1->characters[0], 'focused: top-left corner');
        self::assertSame('╮', $buffer1->characters[29], 'focused: top-right corner');
        self::assertSame('╰', $buffer1->characters[14 * 30], 'focused: bottom-left corner');
        self::assertSame('╯', $buffer1->characters[14 * 30 + 29], 'focused: bottom-right corner');
        self::assertSame(Attr::BOLD, $buffer1->attributes[0] & Attr::BOLD, 'focused: the border is bold');
        self::assertSame(0, $buffer2->attributes[0] & Attr::BOLD, 'unfocused: it is not');
        for ($row = 1; $row < 14; ++$row) {
            self::assertSame('│', $buffer1->characters[$row * 30], "focused: left border at row {$row}");
            self::assertSame('│', $buffer1->characters[$row * 30 + 29], "focused: right border at row {$row}");
        }

        // Unfocused uses rounded border
        self::assertSame('╭', $buffer2->characters[0], 'unfocused: top-left corner');
        self::assertSame('╮', $buffer2->characters[29], 'unfocused: top-right corner');
        self::assertSame('╰', $buffer2->characters[14 * 30], 'unfocused: bottom-left corner');
        self::assertSame('╯', $buffer2->characters[14 * 30 + 29], 'unfocused: bottom-right corner');
        for ($row = 1; $row < 14; ++$row) {
            self::assertSame('│', $buffer2->characters[$row * 30], "unfocused: left border at row {$row}");
            self::assertSame('│', $buffer2->characters[$row * 30 + 29], "unfocused: right border at row {$row}");
        }
    }

    #[Test]
    public function panelScrollsWhenFocusedSelectAtCursorBoundary(): void
    {
        $panel = Panel::new('Test')
            ->add(Text::new('Top'))
            ->add(Select::new(['A', 'B', 'C']))
            ->add(Text::new('Bottom'))
            ->withFocus(true);

        $area = new Rect(0, 0, 30, 5);

        // Select cursor starts at 0. Pressing Down moves cursor within Select.
        $result = $panel->update(new KeyMsg(Key::Down), $area);
        $panel1 = $result->model;
        $result = $panel1->update(new KeyMsg(Key::Down), $area);
        $panel2 = $result->model;

        // Now cursor is at last option (index 2). Another Down should NOT move
        // cursor (boundary), so Select returns same instance, and Panel should
        // fall through to scroll handling.
        $result = $panel2->update(new KeyMsg(Key::Down), $area);
        $panel3 = $result->model;

        // Panel should have scrolled (new instance with different scrollOffset)
        self::assertNotSame($panel2, $panel3, 'Panel must scroll when Select at boundary');

        // Verify scroll happened by rendering: "Top" text should have moved up
        $frame = new Frame(30, 5);
        $panel3->render($frame, $area, new RenderContext(Theme::dark()));

        // After scroll, row 1 should no longer be "Top" — it should be the Select
        self::assertNotSame('T', $frame->characters[1 * 30 + 1], 'Top text should have scrolled away');
    }

    private function numberedPanel(int $count): Panel
    {
        $panel = Panel::new('Rows');
        for ($row = 0; $row < $count; ++$row) {
            $panel = $panel->add(Text::new('row-' . $row));
        }

        return $panel;
    }

    private function text(Frame $frame, int $width, int $height): string
    {
        $lines = [];
        for ($row = 0; $row < $height; ++$row) {
            $lines[] = implode('', array_slice($frame->characters, $row * $width, $width));
        }

        return implode("\n", $lines);
    }

    private function scrollPanel(Panel $panel, Rect $area, int $scrollCount): Panel
    {
        $current = $panel;
        for ($step = 0; $step < $scrollCount; ++$step) {
            $result = $current->update(new KeyMsg(Key::Down), $area);
            $current = $result->model;
        }

        return $current;
    }
}
