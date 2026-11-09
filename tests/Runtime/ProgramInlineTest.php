<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ScreenMode;
use Elmphant\Tui\Runtime\SizesItsRegion;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Application\Support\FieldAboveALineApp;
use Elmphant\Tui\Tests\Application\Support\OpenDropdownApp;
use Elmphant\Tui\Tests\Application\Support\QuitOnEnterFieldApp;
use Elmphant\Tui\Tests\Application\Support\ToastOnStartApp;
use Elmphant\Tui\Tests\Application\Support\TwoLineApp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

final class ProgramInlineTest extends TestCase
{
    #[Test]
    public function aRegionHeightThatThrowsIsARenderFailureTheErrorHandlerHears(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        $clock = new TestClock(1000.0);
        $sources = [];
        $program = Program::new(new RegionHeightThatThrowsModel())
            ->withTerminal($terminal)
            ->withClock($clock)
            ->withScreenMode(ScreenMode::inline())
            ->onError(static function (ErrorMsg $error) use (&$sources): Cmd {
                $sources[] = $error->source;

                return Cmd::quit();
            });

        EventLoop::defer(static function () use ($clock, $program): void {
            $clock->advance(1);
            $program->tick();
            $clock->advance(1);
            $program->tick();
        });
        $program->start();

        self::assertSame([ErrorSource::Render], $sources);
    }

    #[Test]
    public function anInlineRunDrawsOnlyTheRowsItsContentWantsWithRelativeMoves(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput("\x03"));

        Program::new(new TwoLineApp())->withTerminal($terminal)->withScreenMode(ScreenMode::inline())->start();

        self::assertMatchesRegularExpression('/first\e\[0m\e\[K\r\n\e\[[\d;]*msecond\e\[0m\e\[K/', $terminal->output());
        self::assertDoesNotMatchRegularExpression('/\x1b\[[\d;]*H/', $terminal->output());
    }

    #[Test]
    public function theModelIsHandedTheRegionNotTheWholeScreen(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput('x'));

        $model = Program::new(new RegionRecordingModel())->withTerminal($terminal)->withScreenMode(ScreenMode::inline())->start();

        self::assertEquals(Rect::fromSize(20, 3), $model->area);
    }

    #[Test]
    public function theFrameLeftOnScreenShowsTheKeysThatArrivedWithTheQuit(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput("ada\r"));

        Program::new(new QuitOnEnterFieldApp())->withTerminal($terminal)->withScreenMode(ScreenMode::inline())->start();

        $frames = explode(Ansi::SYNC_START, $terminal->output());
        self::assertStringContainsString('ada', end($frames));
    }

    #[Test]
    public function aPrintedLineStaysAboveTheRegionAfterTheProgramEnds(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput('x'));

        Program::new(new RegionRecordingModel(answer: Cmd::batch(Cmd::println('✓ answered'), Cmd::quit())))
            ->withTerminal($terminal)->withScreenMode(ScreenMode::inline())->start();

        self::assertStringContainsString("\x1b[J\r✓ answered\r\n", $terminal->output());
    }

    #[Test]
    public function aLinePrintedWithTheQuitIsTheLastThingDrawn(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput('x'));

        Program::new(new RegionRecordingModel(answer: Cmd::batch(Cmd::println('✓ answered'), Cmd::quit())))
            ->withTerminal($terminal)->withScreenMode(ScreenMode::inline())->start();

        $afterThePrint = substr($terminal->output(), (int)strpos($terminal->output(), '✓ answered'));
        self::assertStringNotContainsString(Ansi::SYNC_START, $afterThePrint);
    }

    #[Test]
    public function anOpenDropdownGrowsTheRegionToShowItsOptionsBelowIt(): void
    {
        $terminal = new VirtualTerminal(20, 10);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput("\x03"));

        Program::new(new OpenDropdownApp())->withTerminal($terminal)->withScreenMode(ScreenMode::inline())->start();

        $frames = explode(Ansi::SYNC_START, $terminal->output());
        $last = end($frames);
        self::assertStringContainsString('alpha', $last);
        self::assertStringContainsString('gamma', $last);
    }

    #[Test]
    public function aToastIsDrawnInsideTheRegionRatherThanAtTheBottomOfTheTerminal(): void
    {
        $terminal = new VirtualTerminal(30, 12);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput("\x03"));

        Program::new(new ToastOnStartApp())->withTerminal($terminal)->withScreenMode(ScreenMode::inline())->start();

        $frames = explode(Ansi::SYNC_START, $terminal->output());
        $painted = strstr(end($frames), Ansi::SYNC_END, true);
        self::assertStringContainsString('saved', (string)$painted);
        self::assertSame(4, substr_count((string)$painted, "\r\n"), 'five rows, as the content wants');
    }

    #[Test]
    public function theKeyHelpGrowsARegionTooSmallToShowIt(): void
    {
        $terminal = new VirtualTerminal(40, 60);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput("\x0b"));
        EventLoop::delay(0.15, static fn () => $terminal->simulateInput("\x03"));

        Program::new(new FieldAboveALineApp())->withTerminal($terminal)->withScreenMode(ScreenMode::inline())->start();

        $frames = explode(Ansi::SYNC_START, $terminal->output());
        $rows = explode("\r\n", $frames[\count($frames) - 2]);
        self::assertStringContainsString('Keys · Esc to close', $rows[1], 'the help starts one row in, as it would centred in a region its own height');
    }

    #[Test]
    public function exitParksTheCursorBelowTheRegionFromTheFieldOnItsFirstRow(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput("\x03"));

        Program::new(new FieldAboveALineApp())->withTerminal($terminal)->withScreenMode(ScreenMode::inline())->start();

        self::assertStringEndsWith("\x1b[1B\r\n\x1b[?25h", $terminal->output());
    }
}

final readonly class RegionHeightThatThrowsModel implements Model, SizesItsRegion
{
    public function init(): ?Cmd
    {
        return null;
    }

    public function wantedHeight(int $width): int
    {
        throw new RuntimeException('no height');
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}

final readonly class RegionRecordingModel implements Model, SizesItsRegion
{
    public function __construct(public ?Rect $area = null, public ?Cmd $answer = null)
    {
    }

    public function init(): ?Cmd
    {
        return null;
    }

    public function wantedHeight(int $width): int
    {
        return 3;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return $message instanceof KeyMsg ? new UpdateResult(new self($area), $this->answer ?? Cmd::quit()) : new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
