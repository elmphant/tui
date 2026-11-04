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
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ScreenMode;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramConfigurationTest extends TestCase
{
    #[Test]
    public function configuringHandsBackAnotherProgramAndLeavesTheFirstAsItWas(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        $plain = Program::new(new TerminalModesAtStartModel($terminal))->withTerminal($terminal);

        $configured = $plain->withScreenMode(ScreenMode::alternate(mouse: true));
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
            ->withScreenMode(ScreenMode::alternate(mouse: true))
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
            ->withScreenMode(ScreenMode::alternate(mouse: true))
            ->withScreenMode(ScreenMode::main())
            ->start();

        self::assertFalse($final->altScreen);
        self::assertFalse($final->mouseTracking);
    }

    #[Test]
    public function programsConfiguredFromOneBuilderEachDrawTheirOwnFirstFrame(): void
    {
        $base = Program::new(new GreetingModel())->withClock(new TestClock(100.0));
        $first = new VirtualTerminal(20, 3);
        $second = new VirtualTerminal(20, 3);

        EventLoop::delay(0.02, static fn () => $first->simulateInput('q'));
        $base->withTerminal($first)->start();
        EventLoop::delay(0.02, static fn () => $second->simulateInput('q'));
        $base->withTerminal($second)->start();

        self::assertStringContainsString('hello', $second->output());
    }

    #[Test]
    public function aThemeHandedInIsTheOneTheModelDrawsWith(): void
    {
        $theme = Theme::light();
        $model = new GreetingModel();
        $terminal = new VirtualTerminal(20, 3);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput('q'));

        Program::new($model)->withTerminal($terminal)->withTheme($theme)->start();

        self::assertSame($theme, $model->theme);
    }
}

final class GreetingModel implements Model
{
    public ?Theme $theme = null;

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this, $message instanceof KeyMsg && $message->key === 'q' ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        $this->theme = $pass->context->theme;
        $frame->putString(0, 0, 'hello');
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
