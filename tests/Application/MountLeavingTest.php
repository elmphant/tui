<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Closure;
use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Initializes;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Application\Mount;
use Elmphant\Tui\Application\MountedMsg;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\CancelScopeCmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\NamesItsEffect;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\CommandHandler;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Testing\InMemoryTracer;
use Elmphant\Tui\Testing\ModelHarness;
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

    #[Test]
    public function aMountThatLeavesTheAppStopsTheEffectsItStartedWithoutAName(): void
    {
        $journal = new Journal();
        $tracer = new InMemoryTracer();
        $marker = sys_get_temp_dir() . '/elmphant-mount-leaving-' . bin2hex(random_bytes(4));

        Program::new(new HostOfABusyMount($journal, new BusyModel($journal, $marker)))
            ->withTerminal(new VirtualTerminal(40, 6))
            ->withCommandHandler(new UnnamedFeedHandler($journal))
            ->withTracer($tracer)
            ->start();

        $updates = array_column($tracer->fieldsOf(TraceEvent::Update), 'message');
        $afterLeaving = \array_slice($updates, (int)array_search(LeaveNow::class, $updates, true));
        self::assertContains(LeaveNow::class, $updates);
        self::assertContains(MountedMsg::class, $updates);
        self::assertNotContains(MountedMsg::class, $afterLeaving);
        self::assertSame(['start feed', 'stop feed', 'quit'], $journal->entries);
        self::assertFileDoesNotExist($marker);
    }

    #[Test]
    public function anEffectStartedInsideTwoScopesStopsWhenTheOuterOneIsCancelled(): void
    {
        $journal = new Journal();

        Program::new(new NestedScopeModel($journal))
            ->withTerminal(new VirtualTerminal(40, 6))
            ->withCommandHandler(new FeedHandler($journal))
            ->start();

        self::assertSame(['start feed', 'stop feed', 'quit'], $journal->entries);
    }

    #[Test]
    public function onlyTheMountThatLeftHasItsEffectsStopped(): void
    {
        $kept = ModelHarness::for(new HostOfAFeed(new Journal()), 40, 6)->init()->dispatch(new Bump());
        $left = ModelHarness::for(new HostOfAFeed(new Journal()), 40, 6)->init()->dispatch(new LeaveNow());

        self::assertSame([], $kept->issued(CancelScopeCmd::class), 'the app changed and the feed is still there');
        self::assertCount(1, $left->issued(CancelScopeCmd::class));
    }
}

final readonly class Bump implements Message
{
}

final readonly class NestedScopeModel implements Model
{
    public function __construct(private Journal $journal)
    {
    }

    public function init(): Cmd
    {
        return Cmd::batch(
            (new FeedCmd())->scopedTo('inner')->scopedTo('outer'),
            Cmd::after(0.02, static fn (): Message => new LeaveNow()),
            Cmd::after(0.06, static fn (): Message => new QuitNow()),
        );
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof LeaveNow) {
            return new UpdateResult($this, (new CancelScopeCmd())->scopedTo('outer'));
        }
        if ($message instanceof QuitNow) {
            $this->journal->entries[] = 'quit';

            return new UpdateResult($this, Cmd::quit());
        }

        return new UpdateResult($this);
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
    }
}

final readonly class UnnamedFeedCmd extends Cmd
{
}

final readonly class UnnamedFeedHandler implements CommandHandler
{
    public function __construct(private Journal $journal)
    {
    }

    public function handles(Cmd $command): bool
    {
        return $command instanceof UnnamedFeedCmd;
    }

    public function start(Cmd $command, Closure $emit, Closure $finished): Closure
    {
        $this->journal->entries[] = 'start feed';

        return fn () => $this->journal->entries[] = 'stop feed';
    }
}

final readonly class BusyModel implements Reducer, Initializes
{
    public function __construct(private Journal $journal, private string $marker)
    {
    }

    public function init(): Cmd
    {
        $journal = $this->journal;

        return Cmd::batch(
            Cmd::tick(0.01),
            Cmd::after(0.08, static function () use ($journal): null {
                $journal->entries[] = 'after fired';

                return null;
            }),
            Cmd::exec(['sh', '-c', 'sleep 0.4; touch ' . escapeshellarg($this->marker)]),
            new UnnamedFeedCmd(),
        );
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }
}

final readonly class HostOfABusyMount extends App
{
    use Cloneable;

    public function __construct(private Journal $journal, public ?BusyModel $busy)
    {
    }

    public function view(): Widget
    {
        return $this->busy === null
            ? Text::new('gone')
            : Layout::column(Mount::named('busy', $this->busy, Text::new('busy'), static fn (self $host, BusyModel $busy): self => $host->cloneWith(busy: $busy)));
    }

    protected function setup(): Cmd
    {
        return Cmd::batch(
            Cmd::after(0.03, static fn (): Message => new LeaveNow()),
            Cmd::after(0.6, static fn (): Message => new QuitNow()),
        );
    }

    protected function onMessage(Message $message, InputContext $input): UpdateResult
    {
        if ($message instanceof LeaveNow) {
            return new UpdateResult($this->cloneWith(busy: null));
        }
        if ($message instanceof QuitNow) {
            $this->journal->entries[] = 'quit';

            return new UpdateResult($this, Cmd::quit());
        }

        return new UpdateResult($this);
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
        return new UpdateResult($this);
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

    public function __construct(private Journal $journal, public ?FeedModel $feed = new FeedModel(), public int $bumps = 0)
    {
    }

    public function view(): Widget
    {
        return $this->feed === null
            ? Text::new('gone')
            : Layout::column(Mount::named('feed', $this->feed, Text::new('feed'), static fn (self $host, FeedModel $feed): self => $host->cloneWith(feed: $feed)));
    }

    protected function setup(): Cmd
    {
        return Cmd::batch(
            Cmd::after(0.02, static fn (): Message => new LeaveNow()),
            Cmd::after(0.06, static fn (): Message => new QuitNow()),
        );
    }

    protected function onMessage(Message $message, InputContext $input): UpdateResult
    {
        if ($message instanceof LeaveNow) {
            return new UpdateResult($this->cloneWith(feed: null));
        }
        if ($message instanceof Bump) {
            return new UpdateResult($this->cloneWith(bumps: $this->bumps + 1));
        }
        if ($message instanceof QuitNow) {
            $this->journal->entries[] = 'quit';

            return new UpdateResult($this, Cmd::quit());
        }

        return new UpdateResult($this);
    }
}
