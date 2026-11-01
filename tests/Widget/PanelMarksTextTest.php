<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\ClipboardCmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\Issued;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Testing\UserInput;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Filter\FilterableStaticTable;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PanelMarksTextTest extends TestCase
{
    #[Test]
    public function draggingOverRowsCopiesTheirTextOnRelease(): void
    {
        $copied = self::copiedBy(
            self::panelOfLines(20),
            UserInput::click(1, 1),
            UserInput::drag(3, 2),
            UserInput::release(3, 2),
        );

        self::assertSame(['line0', 'lin'], explode("\n", $copied ?? ''));
    }

    #[Test]
    public function aBoundPaneCopiesWhatIsDraggedOverInAnApp(): void
    {
        $driven = ModelHarness::for(new MarkingLogApp(), 30, 10)->initialize()->drag(1, 1, 9, 3);

        self::assertSame(["line0\nline1\nline2"], array_map(
            static fn (ClipboardCmd $copy): string => $copy->text,
            $driven->issued(ClipboardCmd::class),
        ));
        self::assertNotSame(0, $driven->renderScreen()->styleAt(3, 2)->attributes & Attr::REVERSE);
    }

    #[Test]
    public function aHostThatRebuildsThePaneCarriesTheMarkAcross(): void
    {
        $copied = null;
        $marked = null;

        foreach ([UserInput::click(1, 1), UserInput::drag(5, 2), UserInput::release(5, 2)] as $message) {
            $result = self::panelOfLines(20)->withMarked($marked)->update($message, self::area());
            $marked = $result->model->marked();
            $copied = self::clipboardText($result->command) ?? $copied;
        }

        self::assertSame("line0\nline1", $copied);
    }

    #[Test]
    public function theMarkedCellsAreDrawnReversed(): void
    {
        $panel = self::driven(self::panelOfLines(20), UserInput::click(2, 1), UserInput::drag(4, 1));

        $screen = self::screenOf($panel);

        self::assertSame([false, true, true, true, false], array_map(
            static fn (int $column): bool => ($screen->styleAt($column, 1)->attributes & Attr::REVERSE) !== 0,
            range(1, 5),
        ));
    }

    #[Test]
    public function aClickWithoutADragCopiesNothingAndMarksNothing(): void
    {
        $messages = [UserInput::click(2, 1), UserInput::release(2, 1)];

        self::assertNull(self::copiedBy(self::panelOfLines(20), ...$messages));
        self::assertSame(0, self::screenOf(self::driven(self::panelOfLines(20), ...$messages))->styleAt(2, 1)->attributes & Attr::REVERSE);
    }

    #[Test]
    public function rowsScrolledOutOfViewStillCopy(): void
    {
        $copied = self::copiedBy(
            self::panelOfLines(20),
            UserInput::click(1, 1),
            UserInput::wheel(3, 3, 10),
            UserInput::drag(7, 1),
            UserInput::release(7, 1),
        );

        self::assertSame(['line0', 'line1', 'line2', 'line3', 'line4', 'line5', 'line6', 'line7', 'line8', 'line9', 'line10'], explode("\n", $copied ?? ''));
    }

    #[Test]
    public function draggingPastTheBottomEdgeScrollsThePaneOneRow(): void
    {
        $copied = self::copiedBy(
            self::panelOfLines(20),
            UserInput::click(1, 1),
            UserInput::drag(9, 7),
            UserInput::release(9, 7),
        );

        self::assertSame('line6', array_slice(explode("\n", $copied ?? ''), -1)[0]);
    }

    #[Test]
    public function draggingPastTheTopEdgeScrollsThePaneBackOneRow(): void
    {
        $copied = self::copiedBy(
            self::panelOfLines(20),
            UserInput::wheel(3, 3, 5),
            UserInput::click(5, 6),
            UserInput::drag(1, 0),
            UserInput::release(1, 0),
        );

        self::assertSame('line4', explode("\n", $copied ?? '')[0]);
    }

    #[Test]
    public function aTableInThePaneLeavesTheProseAroundItMarkable(): void
    {
        $panel = Panel::new('log')->withChildren([Text::new('line0'), FilterableStaticTable::new(['Name'], [['a']])]);

        self::assertSame('line0', self::copiedBy($panel, UserInput::click(1, 1), UserInput::drag(9, 1), UserInput::release(9, 1)));
    }

    #[Test]
    public function aFieldInABoundPaneStillTakesTheClickAndTheTyping(): void
    {
        $form = Panel::new('form')->withChildren([Text::new('intro'), TextInput::new()]);
        $driven = ModelHarness::for(new MarkingLogApp($form), 30, 10)->initialize()->clickAt(3, 2)->type('ab');

        self::assertStringContainsString('ab', $driven->renderScreen()->lineAt(2));
        self::assertSame(0, $driven->renderScreen()->styleAt(3, 2)->attributes & Attr::REVERSE);
    }

    #[Test]
    public function aPressOutsideThePaneClearsTheMark(): void
    {
        $marked = self::driven(self::panelOfLines(20), UserInput::click(1, 1), UserInput::drag(5, 1), UserInput::release(5, 1));

        $elsewhere = self::driven($marked, UserInput::click(30, 3));

        self::assertSame(0, self::screenOf($elsewhere)->styleAt(2, 1)->attributes & Attr::REVERSE);
    }

    #[Test]
    public function aMarkIsDroppedOnceThePaneIsDrawnAtAnotherWidth(): void
    {
        $marked = self::driven(self::panelOfLines(20), UserInput::click(1, 1), UserInput::drag(5, 1), UserInput::release(5, 1));

        $frame = new Frame(30, 8);
        $marked->render($frame, new Rect(0, 0, 30, 8), RenderContext::unfocused(Theme::dark()));

        self::assertSame(0, ScreenBuffer::fromFrame($frame)->styleAt(2, 1)->attributes & Attr::REVERSE);
    }

    #[Test]
    public function aMarkKeepsItsTextWhileNewRowsArrive(): void
    {
        $following = self::panelOfLines(20)->withFollowingTheEnd();
        preg_match('/line\d+/', self::screenOf($following)->lineAt(1), $underThePress);
        $pressed = self::driven($following, UserInput::click(1, 1));
        $grown = $pressed->add(Text::new('line20'))->add(Text::new('line21'));

        $copied = self::copiedBy($grown, UserInput::drag(9, 1), UserInput::release(9, 1));

        self::assertSame($underThePress[0], $copied);
    }

    #[Test]
    public function aClickInAPaneFollowingTheEndKeepsItFollowing(): void
    {
        $clicked = self::driven(self::panelOfLines(20)->withFollowingTheEnd(), UserInput::click(1, 1), UserInput::release(1, 1));

        self::assertTrue($clicked->add(Text::new('line20'))->followsTheEnd());
    }

    #[Test]
    public function draggingUpCopiesTheSameRowsAsDraggingDown(): void
    {
        self::assertSame("line0\nline1", self::copiedBy(self::panelOfLines(20), UserInput::click(5, 2), UserInput::drag(1, 1), UserInput::release(1, 1)));
    }

    #[Test]
    public function draggingLeftAlongARowCopiesTheSameCellsAsDraggingRight(): void
    {
        $left = self::copiedBy(self::panelOfLines(20), UserInput::click(5, 1), UserInput::drag(2, 1), UserInput::release(2, 1));
        $right = self::copiedBy(self::panelOfLines(20), UserInput::click(2, 1), UserInput::drag(5, 1), UserInput::release(5, 1));

        self::assertSame(['ine0', 'ine0'], [$left, $right]);
    }

    #[Test]
    public function draggingPastTheLeftEdgeStartsAtTheFirstColumn(): void
    {
        self::assertSame("line0\nline1", self::copiedBy(self::panelOfLines(20), UserInput::click(5, 2), UserInput::drag(0, 1), UserInput::release(0, 1)));
    }

    #[Test]
    public function draggingPastTheRightEdgeEndsAtTheLastColumn(): void
    {
        $panel = Panel::new('log')->withChildren([Text::new('abcdefghijklmnopqr'), Text::new('stuvwxyz')]);

        self::assertSame("abcdefghijklmnopqr\nstuvwxyz", self::copiedBy($panel, UserInput::click(1, 1), UserInput::drag(40, 2), UserInput::release(40, 2)));
        self::assertSame('abcdefghijklmnopqr', self::copiedBy($panel, UserInput::click(1, 1), UserInput::drag(40, 1), UserInput::release(40, 1)));
    }

    #[Test]
    public function theRightButtonMarksNothing(): void
    {
        $panel = self::panelOfLines(20);

        self::assertSame($panel, $panel->update(new MouseMsg(1, 1, MouseAction::Press, MouseButton::Right), self::area())->model);
    }

    #[Test]
    public function aPressBelowTheTextStartsAtTheEndOfTheLastRow(): void
    {
        self::assertSame("line0\nline1", self::copiedBy(self::panelOfLines(2), UserInput::click(9, 6), UserInput::drag(1, 1), UserInput::release(1, 1)));
    }

    #[Test]
    public function aPressInThePaddingStartsAtTheNearestCell(): void
    {
        $panel = self::panelOfLines(20)->withPadding(Spacing::all(1));

        self::assertSame("line0\nline1", self::copiedBy($panel, UserInput::click(1, 1), UserInput::drag(9, 3), UserInput::release(9, 3)));
    }

    #[Test]
    public function aPressInTheRightPaddingStartsAtTheLastColumn(): void
    {
        $panel = Panel::new('log')->withChildren([Text::new('abcdefghijklmnop')])->withPadding(Spacing::all(1));

        self::assertSame('abcdefghijklmnop', self::copiedBy($panel, UserInput::click(18, 2), UserInput::drag(2, 2), UserInput::release(2, 2)));
    }

    #[Test]
    public function aPressBelowASingleRowStartsOnThatRow(): void
    {
        self::assertSame('line0', self::copiedBy(self::panelOfLines(1), UserInput::click(9, 5), UserInput::drag(1, 1), UserInput::release(1, 1)));
    }

    #[Test]
    public function draggingBelowTheTextCopiesNoEmptyLines(): void
    {
        self::assertSame("line0\nline1", self::copiedBy(self::panelOfLines(2), UserInput::click(1, 1), UserInput::drag(9, 6), UserInput::release(9, 6)));
    }

    #[Test]
    public function aMarkInAScrolledPaneIsDrawnOnTheRowsItCovers(): void
    {
        $panel = self::driven(self::panelOfLines(20), UserInput::wheel(3, 3, 5), UserInput::click(2, 2), UserInput::drag(4, 2));

        self::assertNotSame(0, self::screenOf($panel)->styleAt(3, 2)->attributes & Attr::REVERSE);
    }

    #[Test]
    public function aDragAfterTheReleaseLeavesTheMarkAlone(): void
    {
        $panel = self::driven(self::panelOfLines(20), UserInput::click(1, 1), UserInput::drag(5, 1), UserInput::release(5, 1), UserInput::drag(5, 3));

        self::assertSame(0, self::screenOf($panel)->styleAt(2, 3)->attributes & Attr::REVERSE);
    }

    #[Test]
    public function aClickInAPaneTheReaderScrolledUpLeavesItWhereItWas(): void
    {
        $scrolledUp = self::driven(self::panelOfLines(20)->withFollowingTheEnd(), UserInput::wheel(3, 3, -2));

        $clicked = self::driven($scrolledUp, UserInput::click(1, 1), UserInput::release(1, 1));

        self::assertFalse($clicked->add(Text::new('line20'))->followsTheEnd());
    }

    #[Test]
    public function wideCharactersCopyWhole(): void
    {
        $panel = Panel::new('log')->withChildren([Text::new('日本語 ok')]);

        self::assertSame('日本語 ok', self::copiedBy($panel, UserInput::click(1, 1), UserInput::drag(9, 1), UserInput::release(9, 1)));
    }

    private static function driven(Panel $panel, Message ...$messages): Panel
    {
        foreach ($messages as $message) {
            $panel = $panel->update($message, self::area())->model;
        }

        return $panel;
    }

    private static function screenOf(Panel $panel): ScreenBuffer
    {
        $frame = new Frame(self::area()->width, self::area()->height);
        $panel->render($frame, self::area(), RenderContext::unfocused(Theme::dark()));

        return ScreenBuffer::fromFrame($frame);
    }

    private static function copiedBy(Panel $panel, Message ...$messages): ?string
    {
        $copied = null;

        foreach ($messages as $message) {
            $result = $panel->update($message, self::area());
            $panel = $result->model;
            $copied = self::clipboardText($result->command) ?? $copied;
        }

        return $copied;
    }

    private static function clipboardText(?Cmd $command): ?string
    {
        $copies = Issued::in($command, ClipboardCmd::class);

        return $copies === [] ? null : $copies[0]->text;
    }

    private static function area(): Rect
    {
        return new Rect(0, 0, 20, 8);
    }

    private static function panelOfLines(int $count): Panel
    {
        return Panel::new('log')->withChildren(array_map(
            static fn (int $index): Text => Text::new("line{$index}"),
            range(0, $count - 1),
        ));
    }
}
