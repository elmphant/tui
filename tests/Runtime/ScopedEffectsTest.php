<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ScopedEffectsTest extends TestCase
{
    #[Test]
    public function twoPartsOfAnAppCanEachRunAnEffectUnderTheSameName(): void
    {
        $model = new TwoBeats(Cmd::batch(TwoBeats::beatOf('left'), TwoBeats::beatOf('right')));

        self::runBriefly($model);

        self::assertSame(['left', 'right'], $model->heard());
    }

    #[Test]
    public function aCancelReachesOnlyTheEffectOfItsOwnScope(): void
    {
        $model = new TwoBeats(Cmd::batch(
            TwoBeats::beatOf('left'),
            TwoBeats::beatOf('right'),
            Cmd::cancel(EffectId::named('beat'))->scopedTo('left'),
        ));

        self::runBriefly($model);

        self::assertSame(['right'], $model->heard());
    }

    #[Test]
    public function aScopeLeavesTheNameAResultIsAskedByAlone(): void
    {
        $scoped = EffectId::named('load')->within('dashboard');

        self::assertSame('load', $scoped->value);
        self::assertSame('dashboard/load', (string)$scoped);
        self::assertFalse($scoped->equals(EffectId::named('load')));
        self::assertTrue($scoped->equals(EffectId::named('load')->within('dashboard')));
        self::assertSame('shell/dashboard/load', (string)$scoped->within('shell'));
    }

    private static function runBriefly(Model $model): void
    {
        $terminal = new VirtualTerminal(20, 3);
        EventLoop::delay(0.15, static fn () => $terminal->simulateInput('q'));
        Program::new($model)->withTerminal($terminal)->start();
    }
}

final class Heard implements Message
{
    public function __construct(public readonly string $part)
    {
    }
}

final class TwoBeats implements Model
{
    /**
     * @var array<string, true>
     */
    private array $heard = [];

    public function __construct(private readonly Cmd $init)
    {
    }

    public static function beatOf(string $part): Cmd
    {
        return Cmd::tick(0.02, EffectId::named('beat'))
            ->map(static fn (Message $message): Message => $message instanceof TickMsg ? new Heard($part) : $message)
            ->scopedTo($part);
    }

    /**
     * @return list<string>
     */
    public function heard(): array
    {
        $parts = array_keys($this->heard);
        sort($parts);

        return $parts;
    }

    public function init(): ?Cmd
    {
        return $this->init;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof Heard) {
            $this->heard[$message->part] = true;
        }

        return new UpdateResult($this, $message instanceof KeyMsg && $message->key === 'q' ? Cmd::quit() : null);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}
