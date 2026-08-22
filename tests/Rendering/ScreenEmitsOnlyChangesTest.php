<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The whole point of the front buffer, asserted on the bytes rather than on the
 * grid: a frame that says the same thing must cost nothing to say again.
 */
final class ScreenEmitsOnlyChangesTest extends TestCase
{
    #[Test]
    public function drawingTheSameFrameAgainWritesNothingAtAll(): void
    {
        $terminal = new VirtualTerminal(20, 3);
        $screen = new Screen($terminal);

        $frame = new Frame(20, 3);
        $frame->putString(0, 0, 'Hello');
        $screen->flush($frame);

        $terminal->clearOutput();

        $same = new Frame(20, 3);
        $same->putString(0, 0, 'Hello');
        $screen->flush($same);

        self::assertSame('', $terminal->output());
    }
}
