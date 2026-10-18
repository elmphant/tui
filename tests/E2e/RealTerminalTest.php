<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\E2e;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a real terminal draws, not what the framework thinks it drew: tmux parses
 * every byte the way a terminal does, so a diff the renderer gets wrong shows here.
 */
final class RealTerminalTest extends TestCase
{
    private ?TmuxScreen $screen = null;

    #[Test]
    public function aModalOpenedAndClosedLeavesTheScreenAsItWas(): void
    {
        $screen = $this->started();
        $before = $screen->screen();

        $screen->keys('F2');
        $screen->waitFor('Modal body');
        $screen->keys('Escape');

        self::assertSame($before, $screen->screen());
    }

    #[Test]
    public function aDropdownOpenedAndClosedLeavesTheScreenAsItWas(): void
    {
        $screen = $this->started();
        $screen->keys('Tab');
        $before = $screen->screen();

        $screen->keys('Enter');
        $screen->waitFor('epsilon');
        $screen->keys('Escape');

        self::assertSame($before, $screen->screen());
    }

    #[Test]
    public function wideTextTypedAndDeletedAgainLeavesTheScreenAsItWas(): void
    {
        $screen = $this->started();
        $before = $screen->screen();

        $screen->text('漢字😀é');
        $screen->waitFor('漢字😀é');
        $screen->keys('BSpace', 'BSpace', 'BSpace', 'BSpace');

        self::assertSame($before, $screen->screen());
    }

    #[Test]
    public function aWideEmojiDrawnOverWideTextIsDrawnWhole(): void
    {
        $screen = $this->started();

        $screen->keys('F5');

        self::assertStringContainsString(str_repeat("❤\u{FE0F}", 20), $screen->screen());
    }

    #[Test]
    public function aResizeDownAndBackRedrawsTheSameScreen(): void
    {
        $screen = $this->started();
        $before = $screen->screen();

        $screen->resize(20, 5);
        $screen->screen();
        $screen->resize(80, 24);

        self::assertSame($before, $screen->waitFor('Under the dropdown ascii'));
    }

    #[Test]
    public function aListPagedDownAndBackUpShowsWhatItShowedBefore(): void
    {
        $screen = $this->started();
        $screen->keys('Right', 'Tab');
        $before = $screen->waitFor('item 000');

        $screen->keys('PageDown', 'PageDown');
        self::assertStringNotContainsString('item 000', $screen->screen());
        $screen->keys('PageUp', 'PageUp');

        self::assertSame($before, $screen->screen());
    }

    #[Test]
    public function quittingHandsTheTerminalBackAsItWas(): void
    {
        $screen = $this->started();

        $screen->keys('C-c');
        $screen->waitFor('E2E-EXITED 0');

        self::assertSame(
            ['alternate_on' => '0', 'cursor_flag' => '1', 'mouse_any_flag' => '0'],
            [
                'alternate_on' => $screen->flag('alternate_on'),
                'cursor_flag' => $screen->flag('cursor_flag'),
                'mouse_any_flag' => $screen->flag('mouse_any_flag'),
            ],
        );
    }

    #[Test]
    public function quittingWhileAProcessRunsStopsIt(): void
    {
        $marker = sprintf('4242.%d', random_int(100000, 999999));
        $screen = $this->started($marker);

        $screen->keys('F6');
        self::assertNotSame([], self::processesSleeping($marker, expected: true));
        $screen->keys('C-c');
        $screen->waitFor('E2E-EXITED 0');

        self::assertSame([], self::processesSleeping($marker, expected: false));
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

    /**
     * @return list<string>
     */
    private static function processesSleeping(string $marker, bool $expected): array
    {
        $deadline = microtime(true) + 3.0;
        do {
            $pids = [];
            exec('pgrep -f ' . escapeshellarg('^sleep ' . $marker), $pids);
            if (($pids !== []) === $expected) {
                return $pids;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return $pids;
    }

    private function started(string $processMarker = '600'): TmuxScreen
    {
        $this->screen = TmuxScreen::running(sprintf('%s tests/E2e/Fixtures/e2e-app.php %s', escapeshellarg(PHP_BINARY), $processMarker));
        $this->screen->waitFor('E2E app');

        return $this->screen;
    }
}
