<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\TestClock;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use RuntimeException;

final class ProgramViewFailureTest extends TestCase
{
    #[Test]
    public function aViewThatThrowsReachesTheErrorHandlerAsARenderFailure(): void
    {
        $terminal = new VirtualTerminal(40, 10);
        $clock = new TestClock(1000.0);
        $sources = [];
        $program = Program::new(new ViewThatBreaksOnXApp())
            ->withTerminal($terminal)
            ->withClock($clock)
            ->onError(static function (ErrorMsg $error) use (&$sources): Cmd {
                $sources[] = $error->source;

                return Cmd::quit();
            });

        EventLoop::defer(static function () use ($terminal, $clock, $program): void {
            $clock->advance(1);
            $program->tick();
            $terminal->simulateInput('x');
            $clock->advance(1);
            $program->tick();
            $clock->advance(1);
            $program->tick();
        });
        $program->start();

        self::assertSame([ErrorSource::Render], $sources);
    }
}

final readonly class ViewThatBreaksOnXApp extends App
{
    public function __construct(public bool $broken = false)
    {
    }

    public function view(): Widget
    {
        if ($this->broken) {
            throw new RuntimeException('view boom');
        }

        return Text::new('fine');
    }

    protected function onKey(KeyMsg $message, InputContext $input): UpdateResult
    {
        return $message->key === 'x'
            ? new UpdateResult(new self(true))
            : new UpdateResult($this);
    }
}
