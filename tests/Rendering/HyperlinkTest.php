<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HyperlinkTest extends TestCase
{
    #[Test]
    public function aLinkOpensBeforeItsTextAndClosesRightAfterIt(): void
    {
        $frame = new Frame(20, 1);
        $frame->putLine(0, 0, new Line([Span::raw('see '), Span::link('docs', 'https://example.com/docs'), Span::raw(' now')]));

        self::assertStringContainsString("see \e]8;;https://example.com/docs\e\\docs\e]8;;\e\\ now", self::drawn($frame));
    }

    #[Test]
    public function aLinkCutOffAtTheEdgeIsStillClosed(): void
    {
        $frame = new Frame(8, 1);
        $frame->putLine(0, 0, new Line([Span::link('documentation', 'https://example.com/docs')]));

        self::assertStringContainsString("\e]8;;https://example.com/docs\e\\document\e]8;;\e\\", self::drawn($frame));
    }

    #[Test]
    public function aLinkIsClosedBeforeTheCursorMovesAway(): void
    {
        $frame = new Frame(12, 2);
        $frame->putLine(0, 0, new Line([Span::link('docs', 'https://example.com/docs')]));
        $frame->putLine(8, 1, new Line([Span::link('docs', 'https://example.com/docs')]));
        $screen = new Screen($terminal = new VirtualTerminal(12, 2));
        $screen->flush(new Frame(12, 2));
        $terminal->clearOutput();

        $screen->flush($frame);

        self::assertSame(2, substr_count($terminal->output(), "\e]8;;\e\\"), 'each run of the link is closed before the cursor jumps to the next');
    }

    #[Test]
    public function aUrlCannotEndTheSequenceEarly(): void
    {
        $frame = new Frame(10, 1);
        $frame->putLine(0, 0, new Line([Span::link('docs', "https://example.com/\e\\\x07docs")]));

        self::assertStringContainsString("\e]8;;https://example.com/docs\e\\docs", self::drawn($frame));
    }

    #[Test]
    public function aUrlHandedToTheStyleDirectlyCannotEndTheSequenceEarly(): void
    {
        $frame = new Frame(10, 1);
        $frame->putStyled(0, 0, 'docs', new Style(link: "https://example.com/\e]0;title\x07\e[2Jdocs"));

        self::assertStringContainsString("\e]8;;https://example.com/docs\e\\docs", self::drawn($frame));
    }

    #[Test]
    public function aTestCanAskWhichUrlACellLinksTo(): void
    {
        $frame = new Frame(10, 1);
        $frame->putLine(0, 0, new Line([Span::link('docs', 'https://example.com/docs')->bold()]));

        $style = ScreenBuffer::fromFrame($frame)->styleAt(0, 0);

        self::assertSame('https://example.com/docs', $style->link);
        self::assertSame(Attr::BOLD, $style->attributes);
    }

    private static function drawn(Frame $frame): string
    {
        $terminal = new VirtualTerminal($frame->width, $frame->height);
        (new Screen($terminal))->flush($frame);

        return $terminal->output();
    }
}
