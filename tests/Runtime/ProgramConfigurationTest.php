<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramConfigurationTest extends TestCase
{
    #[Test]
    public function configuringHandsBackAnotherProgramAndLeavesTheFirstAsItWas(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        $plain = Program::new(new TerminalModesAtStartModel($terminal))->withTerminal($terminal);

        $configured = $plain->withAltScreen()->withMouseTracking();
        $final = $plain->start();

        self::assertNotSame($plain, $configured);
        self::assertFalse($final->altScreen);
        self::assertFalse($final->mouseTracking);
    }

    #[Test]
    public function theAltScreenAndMouseTrackingAreOnOnceAskedFor(): void
    {
        $terminal = new VirtualTerminal(40, 10);

        $final = Program::new(new TerminalModesAtStartModel($terminal))
            ->withTerminal($terminal)
            ->withAltScreen()
            ->withMouseTracking()
            ->start();

        self::assertTrue($final->altScreen);
        self::assertTrue($final->mouseTracking);
    }

    #[Test]
    public function theAltScreenAndMouseTrackingCanBeTurnedBackOff(): void
    {
        $terminal = new VirtualTerminal(40, 10);

        $final = Program::new(new TerminalModesAtStartModel($terminal))
            ->withTerminal($terminal)
            ->withAltScreen()
            ->withMouseTracking()
            ->withAltScreen(false)
            ->withMouseTracking(false)
            ->start();

        self::assertFalse($final->altScreen);
        self::assertFalse($final->mouseTracking);
    }
}

final class TerminalModesAtStartModel implements Model
{
    public bool $altScreen = false;
    public bool $mouseTracking = false;

    public function __construct(private readonly VirtualTerminal $terminal)
    {
    }

    public function init(): ?Cmd
    {
        $this->altScreen = $this->terminal->isAltScreenEnabled();
        $this->mouseTracking = $this->terminal->isMouseTrackingEnabled();

        return Cmd::quit();
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
