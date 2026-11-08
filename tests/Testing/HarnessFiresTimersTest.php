<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessFiresTimersTest extends TestCase
{
    #[Test]
    public function aTimerFiresOnceTheClockReachesIt(): void
    {
        $driven = ModelHarness::for(new TimedApp(Cmd::after(2.0, static fn (): Message => new Heard('expired'))), 20, 2)->init();

        $driven->advance(1.9);
        self::assertSame([], $driven->model()->heard);

        $driven->advance(0.1);
        self::assertSame(['expired'], $driven->model()->heard);
    }

    #[Test]
    public function aCancelledTimerStaysQuiet(): void
    {
        $nudge = EffectId::named('nudge');
        $start = Cmd::batch(Cmd::after(1.0, static fn (): Message => new Heard('nudged'), $nudge), Cmd::cancel($nudge));
        $driven = ModelHarness::for(new TimedApp($start), 20, 2)->init();

        $driven->advance(2.0);

        self::assertSame([], $driven->model()->heard);
    }

    #[Test]
    public function timersDueInOneStepFireInTheOrderTheyComeDue(): void
    {
        $start = Cmd::batch(
            Cmd::after(2.0, static fn (): Message => new Heard('late')),
            Cmd::after(1.0, static fn (): Message => new Heard('early')),
        );
        $driven = ModelHarness::for(new TimedApp($start), 20, 2)->init();

        $driven->advance(3.0);

        self::assertSame(['early', 'late'], $driven->model()->heard);
    }

    #[Test]
    public function aMappedTimerArrivesWrapped(): void
    {
        $start = Cmd::after(1.0, static fn (): Message => new Heard('inner'))
            ->map(static fn (Message $message): Message => new Heard('wrapped ' . ($message instanceof Heard ? $message->what : '?')));
        $driven = ModelHarness::for(new TimedApp($start), 20, 2)->init();

        $driven->advance(1.0);

        self::assertSame(['wrapped inner'], $driven->model()->heard);
    }

    #[Test]
    public function aTimerThatAnswersWithACommandIssuesIt(): void
    {
        $start = Cmd::after(1.0, static fn (): Cmd => Cmd::after(1.0, static fn (): Message => new Heard('second')));
        $driven = ModelHarness::for(new TimedApp($start), 20, 2)->init();

        $driven->advance(1.0);
        self::assertSame([], $driven->model()->heard);

        $driven->advance(1.0);
        self::assertSame(['second'], $driven->model()->heard);
    }
}

final readonly class Heard implements Message
{
    public function __construct(public string $what)
    {
    }
}

final readonly class TimedApp extends App
{
    /**
     * @param list<string> $heard
     */
    public function __construct(private ?Cmd $start = null, public array $heard = [])
    {
    }

    public function view(): Widget
    {
        return Text::new('waiting');
    }

    protected function setup(): ?Cmd
    {
        return $this->start;
    }

    protected function onMessage(Message $message, InputContext $input): self
    {
        return $message instanceof Heard ? $this->cloneWith(heard: [...$this->heard, $message->what]) : $this;
    }
}
