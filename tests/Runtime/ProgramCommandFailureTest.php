<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramCommandFailureTest extends TestCase
{
    #[Test]
    public function aCommandTheRuntimeCannotDispatchReachesTheModelAsAnError(): void
    {
        $model = new class implements Model {
            /**
             * @var list<string>
             */
            public array $errorSources = [];

            public function init(): ?Cmd
            {
                return null;
            }

            public function update(Message $message, Rect $area): UpdateResult
            {
                if ($message instanceof ErrorMsg) {
                    $this->errorSources[] = $message->source;

                    return new UpdateResult($this, Cmd::quit());
                }

                if ($message instanceof KeyMsg && $message->key === 'x') {
                    return new UpdateResult($this, new UndispatchableCmd());
                }

                return new UpdateResult($this);
            }

            public function render(Frame $frame, RenderContext $context): void
            {
            }
        };

        $terminal = new VirtualTerminal(80, 24);
        $terminal->simulateInput('x');

        Program::new($model)->withTerminal($terminal)->start();

        self::assertSame(
            ['command'],
            $model->errorSources,
            'A command that cannot be dispatched must reach the app as an error instead of unwinding the loop',
        );
    }
}

final readonly class UndispatchableCmd extends Cmd
{
}
