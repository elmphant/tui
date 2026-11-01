<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\SizesItsRegion;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Tests\Application\Support\FieldAboveALineApp;
use Elmphant\Tui\Tests\Application\Support\QuitOnEnterFieldApp;
use Elmphant\Tui\Tests\Application\Support\TwoLineApp;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramInlineTest extends TestCase
{
    #[Test]
    public function anInlineRunDrawsOnlyTheRowsItsContentWantsWithRelativeMoves(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput("\x03"));

        Program::new(new TwoLineApp())->withTerminal($terminal)->withInline()->start();

        self::assertMatchesRegularExpression('/first\e\[0m\e\[K\r\n\e\[[\d;]*msecond\e\[0m\e\[K/', $terminal->output());
        self::assertDoesNotMatchRegularExpression('/\x1b\[[\d;]*H/', $terminal->output());
    }

    #[Test]
    public function anInlineRunRefusesTheMouseBecauseItsRowsAreUnknown(): void
    {
        $this->expectException(LogicException::class);

        Program::new(new TwoLineApp())->withTerminal(new VirtualTerminal(20, 5))->withInline()->withMouseTracking()->start();
    }

    #[Test]
    public function anInlineRunRefusesTheAlternateScreen(): void
    {
        $this->expectException(LogicException::class);

        Program::new(new TwoLineApp())->withTerminal(new VirtualTerminal(20, 5))->withInline()->withAltScreen()->start();
    }

    #[Test]
    public function theModelIsHandedTheRegionNotTheWholeScreen(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput('x'));

        $model = Program::new(new RegionRecordingModel())->withTerminal($terminal)->withInline()->start();

        self::assertEquals(Rect::fromSize(20, 3), $model->area);
    }

    #[Test]
    public function theFrameLeftOnScreenShowsTheKeysThatArrivedWithTheQuit(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput("ada\r"));

        Program::new(new QuitOnEnterFieldApp())->withTerminal($terminal)->withInline()->start();

        $frames = explode(Ansi::SYNC_START, $terminal->output());
        self::assertStringContainsString('ada', end($frames));
    }

    #[Test]
    public function aPrintedLineStaysAboveTheRegionAfterTheProgramEnds(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput('x'));

        Program::new(new RegionRecordingModel(answer: Cmd::batch(Cmd::println('✓ answered'), Cmd::quit())))
            ->withTerminal($terminal)->withInline()->start();

        self::assertStringContainsString("\x1b[J\r✓ answered\r\n", $terminal->output());
    }

    #[Test]
    public function aLinePrintedWithTheQuitIsTheLastThingDrawn(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput('x'));

        Program::new(new RegionRecordingModel(answer: Cmd::batch(Cmd::println('✓ answered'), Cmd::quit())))
            ->withTerminal($terminal)->withInline()->start();

        $afterThePrint = substr($terminal->output(), (int)strpos($terminal->output(), '✓ answered'));
        self::assertStringNotContainsString(Ansi::SYNC_START, $afterThePrint);
    }

    #[Test]
    public function exitParksTheCursorBelowTheRegionFromTheFieldOnItsFirstRow(): void
    {
        $terminal = new VirtualTerminal(20, 5);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput("\x03"));

        Program::new(new FieldAboveALineApp())->withTerminal($terminal)->withInline()->start();

        self::assertStringEndsWith("\x1b[1B\r\n", $terminal->output());
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
