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
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramMappedTickerTest extends TestCase
{
    #[Test]
    public function askingAgainForAMappedTickKeepsOneTicker(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $typist = EventLoop::delay(0.5, static fn () => $terminal->simulateInput('q'));

        Program::new(new MappedTickingModel())->withTerminal($terminal)->start();
        EventLoop::cancel($typist);

        // One 50 ms ticker beats about ten times in half a second; a ticker added per beat doubles them every beat.
        self::assertLessThan(20, MappedTickingModel::$beats);
    }

    #[Test]
    public function aTickMappedForTwoChildrenRunsOnceForEach(): void
    {
        $terminal = new VirtualTerminal(80, 24);
        $typist = EventLoop::delay(0.3, static fn () => $terminal->simulateInput('q'));

        Program::new(new TwoChildrenModel())->withTerminal($terminal)->start();
        EventLoop::cancel($typist);

        self::assertGreaterThan(0, TwoChildrenModel::$beats['left'] ?? 0);
        self::assertGreaterThan(0, TwoChildrenModel::$beats['right'] ?? 0);
    }
}

final class TwoChildrenModel implements Model
{
    /**
     * @var array<string, int>
     */
    public static array $beats = [];

    public function init(): ?Cmd
    {
        self::$beats = [];

        return Cmd::batch(self::beatFor('left'), self::beatFor('right'));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof ChildBeat) {
            self::$beats[$message->child] = (self::$beats[$message->child] ?? 0) + 1;
        }

        return new UpdateResult($this, $message instanceof KeyMsg ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }

    private static function beatFor(string $child): Cmd
    {
        return Cmd::tick(0.05)->map(static fn (Message $message): Message => new ChildBeat($child));
    }
}

final readonly class ChildBeat implements Message
{
    public function __construct(public string $child)
    {
    }
}

final class MappedTickingModel implements Model
{
    public static int $beats = 0;

    public function init(): ?Cmd
    {
        self::$beats = 0;

        return self::beat();
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof KeyMsg) {
            return new UpdateResult($this, Cmd::quit());
        }
        if (!$message instanceof Beat) {
            return new UpdateResult($this);
        }

        ++self::$beats;

        return new UpdateResult($this, self::beat());
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }

    private static function beat(): Cmd
    {
        return Cmd::tick(0.05)->map(static fn (Message $message): Message => new Beat($message));
    }
}

final readonly class Beat implements Message
{
    public function __construct(public Message $tick)
    {
    }
}
