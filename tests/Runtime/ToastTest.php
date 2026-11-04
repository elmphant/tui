<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Keyboard;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\ToastCmd;
use Elmphant\Tui\Core\Cmd\ToastPosition;
use Elmphant\Tui\Core\Cmd\ToastSeverity;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\Toasts;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ToastTest extends TestCase
{
    #[Test]
    public function aToastInTheHarnessLeavesOnceTheTestLetsItsSecondsPass(): void
    {
        $driven = ModelHarness::for(new ToastingApp(['s' => Cmd::toast('Saved')->withSeconds(3)]), 40, 6)->init()->press('s');

        $stillThere = $driven->advance(2.9)->screen()->find('Saved');
        $gone = $driven->advance(0.2)->screen()->find('Saved');

        self::assertNotNull($stillThere);
        self::assertNull($gone);
    }

    #[Test]
    public function theHarnessClockNeverRunsBackwards(): void
    {
        $driven = ModelHarness::for(new ToastingApp(['s' => Cmd::toast('Saved')->withSeconds(3)]), 40, 6)->init()->press('s');

        self::assertNull($driven->advance(-10)->advance(3.1)->screen()->find('Saved'));
    }

    #[Test]
    public function aToastIsABoxInTheTopRightCornerWithItsTitleInTheBorder(): void
    {
        $driven = ModelHarness::for(new ToastingApp(['s' => Cmd::toast('Saved 3 pages')->withTitle('Done')->withWidth(20)]), 40, 6)
            ->init()
            ->press('s');
        $screen = $driven->screen();

        self::assertSame(
            [
                'app                                     ',
                '                   ╭ Done ────────────╮ ',
                '                   │ Saved 3 pages    │ ',
                '                   ╰──────────────────╯ ',
                '                                        ',
            ],
            array_map($screen->lineAt(...), range(0, 4)),
        );
    }

    #[Test]
    public function aPlainToastIsFortyColumnsWide(): void
    {
        $screen = self::screenAfter(Cmd::toast('Hi'), 80, 4);

        self::assertSame(str_repeat(' ', 39) . '╭' . str_repeat('─', 38) . '╮ ', $screen->lineAt(1));
    }

    #[Test]
    public function aToastIsAtLeastFiveColumnsWide(): void
    {
        $screen = self::screenAfter(Cmd::toast('x')->withWidth(1)->withPosition(ToastPosition::TopLeft), 20, 4);

        self::assertSame([' ╭───╮', ' │ x │'], [mb_substr($screen->lineAt(1), 0, 6), mb_substr($screen->lineAt(2), 0, 6)]);
    }

    #[Test]
    public function longTextWrapsInsideTheBorderAndThePadding(): void
    {
        $screen = self::screenAfter(Cmd::toast('one two three four')->withWidth(12)->withPosition(ToastPosition::TopLeft), 20, 7);

        self::assertSame(
            [' ╭──────────╮', ' │ one two  │', ' │ three    │', ' │ four     │', ' ╰──────────╯'],
            array_map(static fn (int $row): string => mb_substr($screen->lineAt($row), 0, 13), range(1, 5)),
        );
    }

    #[Test]
    public function aToastIsNoWiderThanTheScreenLessItsMargins(): void
    {
        $screen = self::screenAfter(Cmd::toast('x'), 10, 4);

        self::assertSame(' ╭──────╮ ', $screen->lineAt(1));
    }

    #[Test]
    public function aToastIsLeftOutOnlyWhenNothingFitsInsideItsBorder(): void
    {
        self::assertStringContainsString('x', self::screenAfter(Cmd::toast('x'), 7, 4)->plainText());
        self::assertStringNotContainsString('╭', self::screenAfter(Cmd::toast('x'), 6, 4)->plainText());
    }

    #[Test]
    public function aToastThatFitsExactlyAboveTheBottomEdgeIsDrawnAndOneTallerIsNot(): void
    {
        self::assertStringContainsString('x', self::screenAfter(Cmd::toast('x'), 20, 4)->plainText());
        self::assertStringNotContainsString('╭', self::screenAfter(Cmd::toast('x'), 20, 3)->plainText());
    }

    #[Test]
    public function aToastWithNoRoomLeftInItsCornerIsLeftOutAndTheNewerOnesStay(): void
    {
        $toast = static fn (string $text): ToastCmd => Cmd::toast($text)->withWidth(12);
        $screen = ModelHarness::for(new ToastingApp(['a' => $toast('first'), 'b' => $toast('second')]), 30, 6)
            ->init()
            ->press('a')
            ->press('b')
            ->screen();

        self::assertStringContainsString('second', $screen->plainText());
        self::assertStringNotContainsString('first', $screen->plainText());
    }

    #[Test]
    public function aToastWithNoRoomInOneCornerLeavesTheOtherCornersAlone(): void
    {
        $screen = ModelHarness::for(new ToastingApp([
            'a' => Cmd::toast('fits')->withWidth(10)->withPosition(ToastPosition::TopLeft),
            'b' => Cmd::toast('far too long to fit')->withWidth(6),
        ]), 30, 5)
            ->init()
            ->press('a')
            ->press('b')
            ->screen();

        self::assertStringContainsString('fits', $screen->plainText());
    }

    #[Test]
    public function aWordLongerThanTheToastIsBrokenInsideTheBorder(): void
    {
        $screen = self::screenAfter(Cmd::toast('abcdefghijkl')->withWidth(12)->withPosition(ToastPosition::TopLeft), 20, 6);

        self::assertSame([' │ abcdefgh │', ' │ ijkl     │'], [mb_substr($screen->lineAt(2), 0, 13), mb_substr($screen->lineAt(3), 0, 13)]);
    }

    /**
     * @return iterable<string, array{ToastPosition, int, int}>
     */
    public static function corners(): iterable
    {
        yield 'top left' => [ToastPosition::TopLeft, 1, 1];
        yield 'top center' => [ToastPosition::TopCenter, 10, 1];
        yield 'top right' => [ToastPosition::TopRight, 19, 1];
        yield 'bottom left' => [ToastPosition::BottomLeft, 1, 4];
        yield 'bottom center' => [ToastPosition::BottomCenter, 10, 4];
        yield 'bottom right' => [ToastPosition::BottomRight, 19, 4];
    }

    #[Test]
    #[DataProvider('corners')]
    public function eachPositionPutsTheToastInItsPlace(ToastPosition $position, int $column, int $row): void
    {
        $screen = self::screenAfter(Cmd::toast('x')->withWidth(10)->withPosition($position), 30, 8);

        self::assertSame('╭', mb_substr($screen->lineAt($row), $column, 1));
        self::assertSame(1, substr_count($screen->plainText(), '╭'));
    }

    /**
     * @return iterable<string, array{ToastSeverity, StyleToken}>
     */
    public static function severities(): iterable
    {
        yield 'info' => [ToastSeverity::Info, StyleToken::Info];
        yield 'success' => [ToastSeverity::Success, StyleToken::Success];
        yield 'warning' => [ToastSeverity::Warning, StyleToken::Warning];
        yield 'error' => [ToastSeverity::Error, StyleToken::Error];
    }

    #[Test]
    #[DataProvider('severities')]
    public function eachSeverityDrawsTheBorderInItsThemeColour(ToastSeverity $severity, StyleToken $token): void
    {
        $screen = self::screenAfter(Cmd::toast('x')->withSeverity($severity)->withWidth(10), 30, 6);

        self::assertEquals($token->resolve(Theme::dark())->foreground, $screen->styleAt(19, 1)->foreground);
    }

    #[Test]
    public function aToastHidesWhatTheAppDrewUnderIt(): void
    {
        $backdrop = implode("\n", array_fill(0, 5, str_repeat('#', 30)));
        $screen = ModelHarness::for(new ToastingApp(['s' => Cmd::toast('x')->withWidth(10)], backdrop: $backdrop), 30, 5)
            ->init()
            ->press('s')
            ->screen();

        self::assertSame(str_repeat('#', 19) . '│ x      │#', $screen->lineAt(2));
    }

    #[Test]
    public function aToastShownAgainUnderItsKeyStaysForItsFullTimeFromThen(): void
    {
        $toasts = new Toasts();
        $toasts->show(Cmd::toast('Saving')->withKey('save')->withSeconds(1), 0.0);
        $toasts->show(Cmd::toast('Saved')->withKey('save')->withSeconds(1), 0.9);

        self::assertSame([false, 1.9], [$toasts->expire(1.5), $toasts->nextDeadline()]);
    }

    #[Test]
    public function aToastStaysSixSecondsUnlessItSaysOtherwise(): void
    {
        $toasts = new Toasts();
        $toasts->show(Cmd::toast('Saved'), 10.0);

        self::assertSame(16.0, $toasts->nextDeadline());
    }

    #[Test]
    public function aClickAfterTheOneThatClosedAToastReachesTheApp(): void
    {
        $driven = ModelHarness::for(new ToastingApp(['s' => Cmd::toast('Saved')->withWidth(20)]), 40, 6)
            ->init()
            ->press('s')
            ->clickAt(25, 2)
            ->clickAt(5, 2);

        self::assertSame([MouseAction::Press, MouseAction::Release], $driven->model()->clicks);
    }

    #[Test]
    public function theWheelOverAToastReachesTheAppAndLeavesTheToast(): void
    {
        $driven = ModelHarness::for(new ToastingApp(['s' => Cmd::toast('Saved')->withWidth(20)]), 40, 6)
            ->init()
            ->press('s')
            ->dispatch(new MouseMsg(25, 2, MouseAction::WheelDown, MouseButton::None));

        self::assertStringContainsString('Saved', $driven->screen()->plainText());
        self::assertSame([MouseAction::WheelDown], $driven->model()->clicks);
    }

    #[Test]
    public function clickingTheOlderOfTwoToastsLeavesTheNewer(): void
    {
        $toast = static fn (string $text): ToastCmd => Cmd::toast($text)->withWidth(12);
        $driven = ModelHarness::for(new ToastingApp(['a' => $toast('first'), 'b' => $toast('second')]), 30, 8)
            ->init()
            ->press('a')
            ->press('b')
            ->clickAt(22, 5);

        $screen = $driven->screen()->plainText();
        self::assertSame([false, true], [str_contains($screen, 'first'), str_contains($screen, 'second')]);
    }

    #[Test]
    public function toastsAtTheBottomStackUpwardWithTheNewestInTheCorner(): void
    {
        $toast = static fn (string $text): ToastCmd => Cmd::toast($text)->withWidth(12)->withPosition(ToastPosition::BottomLeft);
        $driven = ModelHarness::for(new ToastingApp(['a' => $toast('first'), 'b' => $toast('second')]), 30, 8)
            ->init()
            ->press('a')
            ->press('b');
        $screen = $driven->screen();

        self::assertSame(
            [
                ' │ first    │                 ',
                ' ╰──────────╯                 ',
                ' ╭──────────╮                 ',
                ' │ second   │                 ',
                ' ╰──────────╯                 ',
                '                              ',
            ],
            array_map($screen->lineAt(...), range(2, 7)),
        );
    }

    #[Test]
    public function anErrorToastDrawsItsBorderAndTitleInTheThemesErrorColour(): void
    {
        $driven = ModelHarness::for(new ToastingApp(['e' => Cmd::toast('Disk full')->withTitle('Failed')->withSeverity(ToastSeverity::Error)->withWidth(20)]), 40, 6)
            ->init()
            ->press('e');
        $screen = $driven->screen();
        $error = StyleToken::Error->resolve(Theme::dark())->foreground;

        self::assertEquals([$error, $error, $error], [
            $screen->styleAt(19, 1)->foreground,
            $screen->styleAt(21, 1)->foreground,
            $screen->styleAt(19, 2)->foreground,
        ]);
        self::assertNotEquals($error, $screen->styleAt(21, 2)->foreground, 'the text keeps the body colour');
    }

    #[Test]
    public function aToastWithTheSameKeyTakesThePlaceOfTheOneOnScreen(): void
    {
        $driven = ModelHarness::for(new ToastingApp([
            'a' => Cmd::toast('Saving')->withKey('save')->withWidth(12),
            'b' => Cmd::toast('Loaded')->withWidth(12),
            'c' => Cmd::toast('Saved')->withKey('save')->withWidth(12),
        ]), 30, 8)
            ->init()
            ->press('a')
            ->press('b')
            ->press('c');
        $screen = $driven->screen();

        self::assertSame(
            ['│ Loaded   │', '│ Saved    │'],
            [mb_substr($screen->lineAt(2), 17, 12), mb_substr($screen->lineAt(5), 17, 12)],
        );
        self::assertStringNotContainsString('Saving', $screen->plainText());
    }

    #[Test]
    public function aClickOnAToastClosesItAndNeverReachesTheApp(): void
    {
        $driven = ModelHarness::for(new ToastingApp(['s' => Cmd::toast('Saved')->withWidth(20)]), 40, 6)
            ->init()
            ->press('s')
            ->clickAt(25, 2);

        self::assertStringNotContainsString('Saved', $driven->screen()->plainText());
        self::assertSame([], $driven->model()->clicks);
    }

    #[Test]
    public function aClickBesideAToastReachesTheAppAndLeavesTheToast(): void
    {
        $driven = ModelHarness::for(new ToastingApp(['s' => Cmd::toast('Saved')->withWidth(20)]), 40, 6)
            ->init()
            ->press('s')
            ->clickAt(5, 2);

        self::assertStringContainsString('Saved', $driven->screen()->plainText());
        self::assertSame([MouseAction::Press, MouseAction::Release], $driven->model()->clicks);
    }

    #[Test]
    public function aRunningProgramShowsAToastAndWakesToTakeItAwayWhenItsTimeIsUp(): void
    {
        $terminal = new VirtualTerminal(40, 6);
        $program = Program::new(new ToastingApp(['s' => Cmd::toast('Saved')->withSeconds(0.2)]))->withTerminal($terminal);
        $screens = [];

        EventLoop::defer(static function () use ($terminal, $program, &$screens): void {
            $terminal->simulateInput('s');
            $program->tick();
            $screens[] = (new ScreenBuffer($terminal->output(), 40, 6))->plainText();
            EventLoop::delay(0.5, static function () use ($terminal, $program, &$screens): void {
                $screens[] = (new ScreenBuffer($terminal->output(), 40, 6))->plainText();
                $terminal->closeInput();
                $program->tick();
            });
        });
        $program->start();

        self::assertStringContainsString('Saved', $screens[0]);
        self::assertStringNotContainsString('Saved', $screens[1]);
    }

    #[Test]
    public function aRunningProgramClosesAToastThatIsClicked(): void
    {
        $terminal = new VirtualTerminal(40, 6);
        $clock = new TestClock(1000.0);
        $program = Program::new(new ToastingApp(['s' => Cmd::toast('Saved')->withWidth(20)]))->withTerminal($terminal)->withClock($clock);
        $screen = '';

        EventLoop::defer(static function () use ($terminal, $clock, $program, &$screen): void {
            $terminal->simulateInput('s');
            $clock->advance(0.1);
            $program->tick();
            $terminal->simulateInput("\x1b[<0;26;3M\x1b[<0;26;3m");
            $clock->advance(0.1);
            $program->tick();
            $screen = (new ScreenBuffer($terminal->output(), 40, 6))->plainText();
            $terminal->closeInput();
            $program->tick();
        });

        self::assertSame([], $program->start()->clicks);
        self::assertStringNotContainsString('Saved', $screen);
    }

    private static function screenAfter(ToastCmd $toast, int $width, int $height): ScreenBuffer
    {
        return ModelHarness::for(new ToastingApp(['s' => $toast]), $width, $height)->init()->press('s')->screen();
    }
}

final readonly class ToastingApp extends App
{
    /**
     * @param array<string, Cmd> $toasts
     * @param list<MouseAction>  $clicks
     */
    public function __construct(private array $toasts, public array $clicks = [], private string $backdrop = 'app')
    {
    }

    public function view(): Widget
    {
        return Paragraph::fromText($this->backdrop);
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return new UpdateResult($this, is_string($message->key) ? $this->toasts[$message->key] ?? null : null);
    }

    protected function onMouse(MouseMsg $message, Rect $screen, Keyboard $keyboard): UpdateResult
    {
        return new UpdateResult($this->cloneWith(clicks: [...$this->clicks, $message->action]));
    }
}
