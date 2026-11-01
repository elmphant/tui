<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\E2e;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InlineTerminalTest extends TestCase
{
    private ?TmuxScreen $screen = null;

    #[Test]
    public function anInlineRunLeavesWhatTheShellPrintedAndItsLastFrameAboveWhatComesAfter(): void
    {
        $screen = $this->started();

        $screen->text('ada');
        $screen->waitFor('ada');
        $screen->keys('Enter');
        $screen->waitFor('E2E-EXITED');

        self::assertSame(
            "1\n2\n3\nInline heading\nWho are you?\nada\nEnter to finish\nName: ada\nE2E-EXITED 0",
            $screen->withHistory(),
        );
    }

    #[Test]
    public function narrowingTheTerminalAndWideningItAgainLeavesNoCopyOfTheRegion(): void
    {
        $screen = $this->started();
        $before = $screen->withHistory();

        $screen->resize(10, 10);
        $screen->waitFor("Enter to\nfinish");
        $screen->resize(40, 10);
        $screen->waitFor('Enter to finish');

        self::assertSame($before, $screen->withHistory());
    }

    #[Test]
    public function aTerminalShorterThanTheRegionAndTallerAgainLeavesNoCopyOfTheRegion(): void
    {
        $screen = $this->started();
        $before = $screen->withHistory();

        $screen->resize(40, 2);
        $screen->waitFor('your name');
        $screen->resize(40, 10);
        $screen->waitFor('Enter to finish');

        self::assertSame($before, $screen->withHistory());
    }

    #[Test]
    public function aSuspendedRunLeavesItsRegionToTheShellAndDrawsAgainBelowWhatTheShellPrinted(): void
    {
        $this->screen = TmuxScreen::running("env PS1='$ ' bash --norc --noprofile -i", 40, 20);
        $this->screen->waitFor('$');
        $this->screen->text(sprintf('%s tests/E2e/Fixtures/inline-app.php', escapeshellarg(PHP_BINARY)));
        $this->screen->keys('Enter');
        $this->screen->waitFor('Enter to finish');

        exec('pkill -TSTP -f inline-app.php');
        $this->screen->waitFor('Stopped');
        $this->screen->text('echo marker');
        $this->screen->keys('Enter');
        $this->screen->text('fg');
        $this->screen->keys('Enter');
        $this->screen->waitFor("fg\n");
        $this->screen->text('ada');
        $this->screen->keys('Enter');
        $this->screen->waitFor('Name: ada');

        self::assertMatchesRegularExpression(
            '/Inline heading\nWho are you\?\nyour name\nEnter to finish\n\n\[1\]\+ +Stopped[^$]*\$ echo marker\nmarker\n\$ fg\n[^$]*-app\.php\nWho are you\?\nada\nEnter to finish\nName: ada\n\$$/',
            $this->screen->withHistory(),
        );
    }

    protected function setUp(): void
    {
        if (!TmuxScreen::available()) {
            self::markTestSkipped('tmux is not installed');
        }
    }

    protected function tearDown(): void
    {
        $this->screen?->close();
    }

    private function started(): TmuxScreen
    {
        $this->screen = TmuxScreen::running(sprintf('seq 1 3; %s tests/E2e/Fixtures/inline-app.php', escapeshellarg(PHP_BINARY)), 40, 10);
        $this->screen->waitFor('Enter to finish');

        return $this->screen;
    }
}
