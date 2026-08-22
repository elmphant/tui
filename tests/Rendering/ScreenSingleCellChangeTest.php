<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScreenSingleCellChangeTest extends TestCase
{
    /**
     * Asserted on the bytes, because the grid cannot tell a minimal write from a
     * repaint that happens to end up looking the same.
     */
    #[Test]
    public function changingOneCharacterDoesNotRepaintTheWholeRow(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $screen = new Screen($terminal);

        $first = new Frame(80, 24);
        $first->putString(0, 0, str_repeat('x', 80));
        $screen->flush($first);

        $terminal->clearOutput();

        $second = new Frame(80, 24);
        $second->putString(0, 0, str_repeat('x', 79) . 'y');
        $screen->flush($second);

        $written = $terminal->output();

        self::assertStringContainsString('y', $written);
        self::assertLessThan(
            40,
            strlen($written),
            'One changed cell must not cost a row repaint: ' . json_encode($written),
        );
    }
}
