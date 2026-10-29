<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\AfterCmd;
use Elmphant\Tui\Core\Cmd\CancelCmd;
use Elmphant\Tui\Core\Cmd\CancelScopeCmd;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\Cmd\HttpCmd;
use Elmphant\Tui\Core\Cmd\ListenCmd;
use Elmphant\Tui\Core\Cmd\ProcessCmd;
use Elmphant\Tui\Core\Cmd\TickCmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\NamesItsEffect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Runtime\Trace\Tracer;
use LogicException;
use Revolt\EventLoop;
use SplQueue;
use Throwable;
use UnexpectedValueException;
use UnitEnum;
use WeakMap;

/**
 * The effects a program has started and has not finished with.
 *
 * An effect is a command that leaves something behind: a ticker, a timer, a
 * child process, an HTTP request, a stream being watched. Each carries an
 * EffectId, which is what makes it nameable later — to be cancelled, or
 * replaced by another under the same name. Commands that finish inside their
 * own dispatch carry no id and are none of this class's business.
 *
 * Nothing here knows about the terminal, the screen, or where the keyboard is.
 * What an effect produces goes into the queue it was given, and the program
 * reads it from there.
 *
 * @internal
 */
final class EffectRunner
{
    /**
     * @var list<TickerRecord>
     */
    private array $tickers = [];

    /**
     * @var list<TimerRecord>
     */
    private array $timers = [];

    /**
     * @var list<RunningProcess>
     */
    private array $processes = [];

    /**
     * @var list<StreamSubscription>
     */
    private array $subscriptions = [];

    /**
     * @var list<HandledEffect>
     */
    private array $handled = [];

    /**
     * @var WeakMap<RunningProcess, string>
     */
    private WeakMap $processScopes;

    /**
     * @var WeakMap<Message, array{?EffectId, ?string}> the effect each queued result came from, and its scope
     */
    private WeakMap $producers;

    private readonly ProcessStarter $processStarter;

    /**
     * @param SplQueue<Message>                      $results  where an effect reports what it produced
     * @param Closure(?Cmd, ?Closure, ?string): void $dispatch a timer's callback may answer with a command,
     *                                                         and that command may be any command at all
     * @param Closure(): void                        $wake     a stream that became readable has to reach the loop
     * @param list<CommandHandler>                   $handlers what runs a command the runtime has no effect for
     */
    public function __construct(
        private readonly SplQueue $results,
        private readonly Clock $clock,
        private readonly Closure $dispatch,
        private readonly Closure $wake,
        ?ProcessStarter $processStarter = null,
        private readonly HttpRequestStarter $requestStarter = new HttpRequestStarter(),
        private readonly array $handlers = [],
        private readonly ?Tracer $tracer = null,
    ) {
        $this->processStarter = $processStarter ?? new ProcessStarter($clock);
        $this->processScopes = new WeakMap();
        $this->producers = new WeakMap();
    }

    /**
     * @param Closure(Message):Message|null $wrap
     */
    public function run(Cmd $command, ?Closure $wrap, ?string $scope = null): void
    {
        $id = self::scoped($command instanceof NamesItsEffect ? $command->effectId() : null, $scope);
        $this->makeRoomFor($id, $wrap);

        match (true) {
            $command instanceof TickCmd => $this->registerTicker($command->interval, $id, $wrap, $scope),
            $command instanceof AfterCmd => $this->registerTimer($command->delay, $command->then, $id, $wrap, $scope),
            $command instanceof CancelCmd => $this->cancel($scope === null ? $command->id : $command->id->within($scope), $wrap, dropsWaitingResults: false),
            $command instanceof CancelScopeCmd => $this->cancelWithin($scope ?? throw new LogicException('A scope can only be cancelled from inside it'), $wrap),
            $command instanceof HttpCmd => $this->keep(
                fn (): RunningProcess => $this->requestStarter->start($command->withId($id), $wrap),
                ErrorSource::Http,
                $id,
                $wrap,
                $scope,
            ),
            $command instanceof ExecCmd => $this->keep(
                fn (): RunningProcess => $this->processStarter->exec($command->withId($id), $wrap),
                ErrorSource::Process,
                $id,
                $wrap,
                $scope,
            ),
            $command instanceof ProcessCmd => $this->keep(
                fn (): RunningProcess => $this->processStarter->streaming($command->withId($id), $wrap),
                ErrorSource::Process,
                $id,
                $wrap,
                $scope,
            ),
            $command instanceof ListenCmd => $this->subscribeToStream($command, $id, $wrap, $scope),
            default => $this->delegate($command, $id, $wrap, $scope),
        };
    }

    /**
     * Whatever is due at this moment: a ticker fires again, a timer fires once.
     */
    public function fire(float $now): void
    {
        $this->fireTickers($now);
        $this->fireTimers($now);
    }

    /**
     * What the effects still running have to say since last asked.
     */
    public function pump(): void
    {
        $this->pumpProcesses();
        $this->pumpSubscriptions();
    }

    /**
     * How long the loop may sleep before something is due, or null when nothing is.
     */
    public function nextDelay(): ?float
    {
        $now = $this->clock->now();
        $earliest = null;

        foreach ([...$this->tickers, ...$this->timers] as $scheduled) {
            $delay = max(0.0, $scheduled->fireAt - $now);
            $earliest = $earliest === null ? $delay : min($earliest, $delay);
        }

        return $earliest;
    }

    /**
     * A process still running keeps the loop awake, however quiet the input is.
     */
    public function hasRunningProcesses(): bool
    {
        return $this->processes !== [];
    }

    /**
     * A callback due in the pass being fired must not run once the program has
     * been told to stop.
     */
    public function forgetTimers(): void
    {
        $this->timers = [];
    }

    public function shutdown(): void
    {
        foreach ($this->subscriptions as $subscription) {
            $this->stopWatching($subscription);
        }
        $this->subscriptions = [];

        foreach ($this->processes as $process) {
            try {
                $process->stop();
            } catch (Throwable) {
            }
        }
        $this->processes = [];

        foreach ($this->handled as $effect) {
            try {
                $effect->stop();
            } catch (Throwable) {
            }
        }
        $this->handled = [];
    }

    private static function scoped(?EffectId $id, ?string $scope): ?EffectId
    {
        return $scope === null ? $id : $id?->within($scope);
    }

    /**
     * A command of the application's own: the first handler that claims it runs
     * it, and whatever it answers with is held under the command's name so a
     * cancellation, a replacement or a shutdown reaches it like any other effect.
     *
     * @param Closure(Message):Message|null $wrap
     */
    private function delegate(Cmd $command, ?EffectId $id, ?Closure $wrap, ?string $scope): void
    {
        $handler = $this->handlerFor($command);
        $effect = new HandledEffect($id, $scope);

        try {
            $effect->stopper = $handler->start($command, $this->emitterFor($effect, $wrap), $this->finisherFor($effect));
        } catch (Throwable $error) {
            $this->report(new ErrorMsg($error, ErrorSource::Command, $effect->id), $wrap);

            return;
        } finally {
            $effect->starting = false;
        }

        if ($effect->stopper !== null && !$effect->over) {
            $this->handled[] = $effect;
        }
        if ($effect->emittedWhileStarting) {
            ($this->wake)();
        }
    }

    private function handlerFor(Cmd $command): CommandHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->handles($command)) {
                return $handler;
            }
        }

        throw new LogicException(sprintf('Unknown command type "%s" — the runtime has no dispatcher for it', $command::class));
    }

    /**
     * What the handler emits before start() returns waits for its stop closure
     * to be registered, or a cancel answering that message would find nothing.
     *
     * @param Closure(Message):Message|null $wrap
     *
     * @return Closure(Message): void
     */
    private function emitterFor(HandledEffect $effect, ?Closure $wrap): Closure
    {
        return function (Message $message) use ($wrap, $effect): void {
            if ($effect->over) {
                return;
            }
            $this->report($message, $wrap, $effect->id, $effect->scope);
            if ($effect->starting) {
                $effect->emittedWhileStarting = true;

                return;
            }
            ($this->wake)();
        };
    }

    /**
     * @return Closure(): void
     */
    private function finisherFor(HandledEffect $effect): Closure
    {
        return function () use ($effect): void {
            $effect->over = true;
            $this->handled = array_values(array_filter(
                $this->handled,
                static fn (HandledEffect $held): bool => $held !== $effect,
            ));
        };
    }

    /**
     * Hold on to what a starter began, or say why it could not begin. Starting is
     * the starter's to know; what a failure is worth saying is not.
     *
     * @param Closure(): RunningProcess     $start
     * @param Closure(Message):Message|null $wrap
     */
    private function keep(Closure $start, ErrorSource $source, ?EffectId $id, ?Closure $wrap, ?string $scope): void
    {
        try {
            $process = $start();
        } catch (Throwable $error) {
            $this->report(new ErrorMsg($error, $source, $id), $wrap);

            return;
        }
        $this->processes[] = $process;
        if ($scope !== null) {
            $this->processScopes[$process] = $scope;
        }
    }

    /**
     * An id is one slot: whatever still holds it goes before the replacement
     * starts. Two effects answering to the same name would send messages no
     * host can tell apart, and a ticker re-registered on every config
     * change would silently multiply.
     *
     * @param Closure(Message):Message|null $wrap
     */
    private function makeRoomFor(?EffectId $id, ?Closure $wrap): void
    {
        if ($id !== null && $this->cancel($id, $wrap, dropsWaitingResults: true)) {
            $this->tracer?->record(TraceEvent::EffectReplaced, ['effect' => (string)$id]);
        }
    }

    /**
     * @param Closure(Message):Message|null $wrap
     */
    private function cancel(EffectId $id, ?Closure $wrap, bool $dropsWaitingResults): bool
    {
        return $this->cancelWhere(static fn (?EffectId $running): bool => $running !== null && $running->equals($id), $wrap, $dropsWaitingResults);
    }

    /**
     * @param Closure(Message):Message|null $wrap
     */
    private function cancelWithin(string $scope, ?Closure $wrap): void
    {
        $this->cancelWhere(
            static fn (?EffectId $running, ?string $startedIn): bool => $startedIn !== null && ($startedIn === $scope || str_starts_with($startedIn, $scope . '/')),
            $wrap,
            dropsWaitingResults: true,
        );
    }

    /**
     * A replacement drops what the old run said that update() has not seen yet, so a stale answer never lands
     * after the new one; a plain cancel lets what was already said arrive.
     *
     * @param Closure(?EffectId, ?string): bool $matches the effect's name and the scope it was started in
     * @param Closure(Message):Message|null     $wrap
     *
     * @return bool whether anything was still running
     */
    private function cancelWhere(Closure $matches, ?Closure $wrap, bool $dropsWaitingResults): bool
    {
        if ($dropsWaitingResults) {
            $this->dropQueuedResultsOf($matches);
        }
        $runningBefore = $this->runningCount();

        $this->tickers = array_values(array_filter(
            $this->tickers,
            static fn (TickerRecord $ticker): bool => !$matches($ticker->id, $ticker->scope),
        ));
        $this->timers = array_values(array_filter(
            $this->timers,
            static fn (TimerRecord $timer): bool => !$matches($timer->id, $timer->scope),
        ));

        $keptSubscriptions = [];
        foreach ($this->subscriptions as $subscription) {
            if ($matches($subscription->id, $subscription->scope)) {
                $this->stopWatching($subscription);
                continue;
            }
            $keptSubscriptions[] = $subscription;
        }
        $this->subscriptions = $keptSubscriptions;

        $remaining = [];
        foreach ($this->processes as $process) {
            $effectId = $process->id();
            if ($matches($effectId, $this->processScopes[$process] ?? null)) {
                try {
                    $process->stop();
                } catch (Throwable $error) {
                    $this->report(new ErrorMsg($error, ErrorSource::Cancel, $effectId), $wrap);
                }
                continue;
            }
            $remaining[] = $process;
        }
        $this->processes = $remaining;

        $keptHandled = [];
        foreach ($this->handled as $effect) {
            if ($matches($effect->id, $effect->scope)) {
                $this->stopHandled($effect, $wrap);
                continue;
            }
            $keptHandled[] = $effect;
        }
        $this->handled = $keptHandled;

        return $this->runningCount() < $runningBefore;
    }

    private function runningCount(): int
    {
        return count($this->tickers) + count($this->timers) + count($this->subscriptions) + count($this->processes) + count($this->handled);
    }

    /**
     * @param Closure(?EffectId, ?string): bool $matches
     */
    private function dropQueuedResultsOf(Closure $matches): void
    {
        if (count($this->producers) === 0) {
            return;
        }

        for ($left = $this->results->count(); $left > 0; --$left) {
            $message = $this->results->dequeue();
            $producer = $this->producers[$message] ?? null;
            if ($producer === null || !$matches(...$producer)) {
                $this->results->enqueue($message);
            }
        }
    }

    /**
     * @param Closure(Message):Message|null $wrap
     */
    private function stopHandled(HandledEffect $effect, ?Closure $wrap = null): void
    {
        try {
            $effect->stop();
        } catch (Throwable $error) {
            $this->report(new ErrorMsg($error, ErrorSource::Cancel, $effect->id), $wrap);
        }
    }

    /**
     * @param Closure(Message):Message|null $wrap
     */
    private function registerTicker(float $interval, ?EffectId $id, ?Closure $wrap, ?string $scope): void
    {
        if ($id === null && $this->hasAnonymousTicker($interval, $wrap, $scope)) {
            return;
        }
        $now = $this->clock->now();
        $this->tickers[] = new TickerRecord($now + $interval, $interval, $id, $wrap, $scope);
    }

    /**
     * @param Closure(Message):Message|null $wrap
     */
    private function hasAnonymousTicker(float $interval, ?Closure $wrap, ?string $scope): bool
    {
        foreach ($this->tickers as $ticker) {
            if ($ticker->id === null && $ticker->interval === $interval && $ticker->wrap === $wrap && $ticker->scope === $scope) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Closure():((Message|Cmd|null)) $then
     * @param Closure(Message):Message|null  $wrap
     */
    private function registerTimer(float $delay, Closure $then, ?EffectId $id, ?Closure $wrap, ?string $scope): void
    {
        $this->timers[] = new TimerRecord($this->clock->now() + $delay, $then, $id, $wrap, $scope);
    }

    /**
     * @param Closure(Message):Message|null $wrap
     */
    private function subscribeToStream(ListenCmd $command, ?EffectId $id, ?Closure $wrap, ?string $scope): void
    {
        $subscription = new StreamSubscription($command->stream, $command->onReadable, $id, $wrap, $scope);
        $subscription->watcherId = EventLoop::onReadable($command->stream, function () use ($subscription): void {
            $subscription->readable = true;
            ($this->wake)();
        });
        $this->subscriptions[] = $subscription;
    }

    private function pumpProcesses(): void
    {
        $remaining = [];
        foreach ($this->processes as $process) {
            $scope = $this->processScopes[$process] ?? null;
            try {
                foreach ($process->pump() as $message) {
                    $this->queue($message, $process->id(), $scope);
                }
            } catch (Throwable $error) {
                $this->queue($process->map(new ErrorMsg($error, ErrorSource::Process, $process->id())), $process->id(), $scope);
                try {
                    $process->stop();
                } catch (Throwable) {
                }
                continue;
            }
            if ($process->isRunning()) {
                $remaining[] = $process;
            }
        }
        $this->processes = $remaining;
    }

    private function pumpSubscriptions(): void
    {
        $remaining = [];
        foreach ($this->subscriptions as $subscription) {
            if (!$subscription->readable) {
                $remaining[] = $subscription;
                continue;
            }
            $subscription->readable = false;

            try {
                foreach ($subscription->read() as $message) {
                    $this->report($message, $subscription->wrap, $subscription->id, $subscription->scope);
                }
            } catch (Throwable $error) {
                $this->report(new ErrorMsg($error, ErrorSource::Listen, $subscription->id), $subscription->wrap, $subscription->id, $subscription->scope);
                $this->stopWatching($subscription);
                continue;
            }

            if (!$subscription->isOpen()) {
                $this->stopWatching($subscription);
                continue;
            }
            $remaining[] = $subscription;
        }
        $this->subscriptions = $remaining;
    }

    private function stopWatching(StreamSubscription $subscription): void
    {
        if ($subscription->watcherId === null) {
            return;
        }
        $watcherId = $subscription->watcherId;
        $subscription->watcherId = null;
        try {
            EventLoop::cancel($watcherId);
        } catch (Throwable) {
        }
    }

    private function fireTickers(float $now): void
    {
        $kept = [];

        foreach ($this->tickers as $ticker) {
            if ($ticker->fireAt > $now) {
                $kept[] = $ticker;
                continue;
            }

            $this->report(new TickMsg($now, $ticker->id), $ticker->wrap, $ticker->id, $ticker->scope);
            $kept[] = $ticker->rescheduled($now);
        }

        $this->tickers = $kept;
    }

    private function fireTimers(float $now): void
    {
        $due = [];
        foreach ($this->timers as $timer) {
            if ($now >= $timer->fireAt) {
                $due[] = $timer;
            }
        }

        foreach ($due as $timer) {
            // A callback earlier in this pass may have cancelled it.
            if (!in_array($timer, $this->timers, true)) {
                continue;
            }
            $this->forgetTimer($timer);

            try {
                $result = ($timer->callback)();
            } catch (Throwable $error) {
                $this->report(new ErrorMsg($error, ErrorSource::Timer, $timer->id), $timer->wrap, $timer->id, $timer->scope);
                continue;
            }
            if ($result instanceof Cmd) {
                ($this->dispatch)($result, $timer->wrap, $timer->scope);
            } elseif ($result instanceof Message) {
                $this->report($result, $timer->wrap, $timer->id, $timer->scope);
            } elseif ($result !== null) {
                $this->report(new ErrorMsg(
                    new UnexpectedValueException('Cmd::after() callback must return Message, Cmd, or null'),
                    ErrorSource::Timer,
                    $timer->id,
                ), $timer->wrap, $timer->id, $timer->scope);
            }
        }
    }

    private function forgetTimer(TimerRecord $timer): void
    {
        $this->timers = array_values(array_filter(
            $this->timers,
            static fn (TimerRecord $known): bool => $known !== $timer,
        ));
    }

    /**
     * @param Closure(Message):Message|null $wrap
     */
    private function report(Message $message, ?Closure $wrap, ?EffectId $producer = null, ?string $scope = null): void
    {
        $this->queue($wrap === null ? $message : $wrap($message), $producer, $scope);
    }

    private function queue(Message $message, ?EffectId $producer, ?string $scope): void
    {
        // An enum case is one shared instance: tagging it would tag every copy in the queue.
        if (($producer !== null || $scope !== null) && !$message instanceof UnitEnum) {
            $this->producers[$message] = [$producer, $scope];
        }
        $this->results->enqueue($message);
    }
}
