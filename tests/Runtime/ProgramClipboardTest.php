<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderPass;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramClipboardTest extends TestCase
{
    #[Test]
    public function aCopyCommandReachesTheTerminalAsAnEscapeSequence(): void
    {
        $model = new class implements Model {
            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof KeyMsg && $message->key === 'y') {
                    return new UpdateResult($this, Cmd::copy('one two'));
                }

                if ($message instanceof KeyMsg && $message->key === 'q') {
                    return new UpdateResult($this, Cmd::quit());
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderPass $pass): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('y');
        $terminal->simulateInput('q');

        Program::new($model)->withTerminal($terminal)->start();

        self::assertStringContainsString(Ansi::clipboard('one two'), $terminal->output());
    }
}
