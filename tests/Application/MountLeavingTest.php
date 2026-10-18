<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Closure;
use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Initializes;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\NamesItsEffect;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Runtime\CommandHandler;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Testing\VirtualTerminal;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MountLeavingTest extends TestCase
{
    #[Test]
    public function aMountThatLeavesTheAppStopsTheEffectsItStarted(): void
    {
        $journal = new Journal();

        Program::new(new HostOfAFeed($journal))
            ->withTerminal(new VirtualTerminal(40, 6))
            ->withCommandHandler(new FeedHandler($journal))
            ->start();

        self::assertSame(['start feed', 'stop feed', 'quit'], $journal->entries);
    }
}

final class Journal
{
    /**
     * @var list<string>
     */
    public array $entries = [];
}

final readonly class FeedCmd extends Cmd implements NamesItsEffect
{
    public function effectId(): EffectId
    {
        return EffectId::named('feed');
    }
}

final readonly class FeedHandler implements CommandHandler
{
    public function __construct(private Journal $journal)
    {
    }

    public function handles(Cmd $command): bool
    {
        return $command instanceof FeedCmd;
    }

    public function start(Cmd $command, Closure $emit, Closure $finished): Closure
    {
        $this->journal->entries[] = 'start feed';

        return fn () => $this->journal->entries[] = 'stop feed';
    }
}

final readonly class FeedModel implements Reducer, Initializes
{
    public function init(): Cmd
    {
        return new FeedCmd();
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return UpdateResult::ignored($this);
    }
}

final readonly class LeaveNow implements Message
{
}

final readonly class QuitNow implements Message
{
}

final readonly class HostOfAFeed extends App
{
    use Cloneable;

    public function __construct(private Journal $journal, public ?FeedModel $feed = new FeedModel())
    {
    }

    public function view(): Widget
    {
        return $this->feed === null
            ? Text::new('gone')
            : Layout::vertical(Mount::named('feed', $this->feed, Text::new('feed'), static fn (self $host, FeedModel $feed): self => $host->cloneWith(feed: $feed)));
    }

    protected function setup(): Cmd
    {
        return Cmd::batch(
            Cmd::after(0.02, static fn (): Message => new LeaveNow()),
            Cmd::after(0.06, static fn (): Message => new QuitNow()),
        );
    }

    protected function onMessage(Message $message, Rect $screen): UpdateResult
    {
        if ($message instanceof LeaveNow) {
            return new UpdateResult($this->cloneWith(feed: null));
        }
        if ($message instanceof QuitNow) {
            $this->journal->entries[] = 'quit';

            return new UpdateResult($this, Cmd::quit());
        }

        return UpdateResult::ignored($this);
    }
}
