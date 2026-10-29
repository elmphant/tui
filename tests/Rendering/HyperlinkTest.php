<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HyperlinkTest extends TestCase
{
    #[Test]
    public function aLinkOpensBeforeItsTextAndClosesRightAfterIt(): void
    {
        $frame = new Frame(20, 1);
        $frame->putLine(0, 0, new Line([new Span('see '), Span::link('docs', 'https://example.com/docs'), new Span(' now')]));

        self::assertStringContainsString("see \e]8;;https://example.com/docs\e\\docs\e]8;;\e\\ now", self::drawn($frame));
    }

    private static function drawn(Frame $frame): string
    {
        $terminal = new VirtualTerminal($frame->width, $frame->height);
        (new Screen($terminal))->flush($frame);

        return $terminal->output();
    }
}
