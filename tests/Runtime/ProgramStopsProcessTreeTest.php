<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgramStopsProcessTreeTest extends TestCase
{
    #[Test]
    public function aCancelAlsoStopsWhatTheProcessStarted(): void
    {
        $marker = sprintf('4243.%d', random_int(100000, 999999));

        Program::new(new ShellStartingModel($marker))->withTerminal(new VirtualTerminal(80, 24))->start();

        self::assertSame([], self::sleepersMarked($marker));
    }

    #[Test]
    public function aTimeoutAlsoStopsWhatTheProcessStarted(): void
    {
        $streamed = sprintf('4244.%d', random_int(100000, 999999));
        $collected = sprintf('4245.%d', random_int(100000, 999999));

        Program::new(new TimingOutShellModel($streamed, $collected))->withTerminal(new VirtualTerminal(80, 24))->start();

        self::assertSame([[], []], [self::sleepersMarked($streamed), self::sleepersMarked($collected)]);
    }

    /**
     * @return list<string>
     */
    private static function sleepersMarked(string $marker): array
    {
        $deadline = microtime(true) + 1.0;
        do {
            $pids = [];
            exec('pgrep -f ' . escapeshellarg('^sleep ' . $marker), $pids);
            if ($pids === []) {
                return [];
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        exec('pkill -f ' . escapeshellarg('^sleep ' . $marker));

        return $pids;
    }
}

final readonly class TimingOutShellModel implements Model
{
    public function __construct(private string $streamed, private string $collected)
    {
    }

    public function init(): ?Cmd
    {
        return Cmd::batch(
            Cmd::process(['sh', '-c', 'sleep ' . $this->streamed . ' & wait'])->withTimeout(0.2),
            Cmd::exec(['sh', '-c', 'sleep ' . $this->collected . ' & wait'])->withTimeout(0.2),
            Cmd::after(0.6, static fn () => Cmd::quit()),
        );
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}

final readonly class ShellStartingModel implements Model
{
    public function __construct(private string $marker)
    {
    }

    public function init(): ?Cmd
    {
        return Cmd::batch(
            Cmd::process(['sh', '-c', 'sleep ' . $this->marker . ' & wait'])->withId(EffectId::named('shell')),
            Cmd::after(0.3, static fn () => Cmd::cancel(EffectId::named('shell'))),
            Cmd::after(0.6, static fn () => Cmd::quit()),
        );
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
