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
use ReflectionFunction;
use Revolt\EventLoop;
use SplQueue;
use Throwable;
use UnexpectedValueException;
use UnitEnum;
use WeakMap;

/**
 * Track named, ongoing effects for cancellation and replacement; send results to the program's queue.
 *
 * @internal
 */
final class EffectRunner
{
    private const QUIET_PUMPS = 10;
    private const QUIET_POLL_SECONDS = 0.1;

    private int $quietPumps = 0;

    /**
     * @var list<TickerRecord>
     */
    private array $tickers = [];

    /**
     * @var array<int, TimerRecord> by object id
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

    public function fire(float $now): void
    {
        $this->fireTickers($now);
        $this->fireTimers($now);
    }

    public function pump(): void
    {
        $this->pumpProcesses(onlyOverdue: false);
        $this->pumpSubscriptions();
    }

    /**
     * Reads no output; it only ends the processes whose time ran out.
     */
    public function expireOverdue(): void
    {
        $this->pumpProcesses(onlyOverdue: true);
    }

    public function nextDelay(): ?float
    {
        $now = $this->clock->now();
        $earliest = null;

        foreach ([...$this->tickers, ...$this->timers] as $scheduled) {
            $delay = max(0.0, $scheduled->fireAt - $now);
            $earliest = $earliest === null ? $delay : min($earliest, $delay);
        }
        foreach ($this->processes as $process) {
            $earliest = min($earliest ?? self::QUIET_POLL_SECONDS, self::QUIET_POLL_SECONDS, max(0.0, $process->secondsLeft() ?? self::QUIET_POLL_SECONDS));
        }

        return $earliest;
    }

    public function hasRunningProcesses(): bool
    {
        return $this->processes !== [];
    }

    /**
     * A process that wrote nothing for a while is polled at nextDelay(), not on every tick.
     */
    public function hasBusyProcesses(): bool
    {
        return $this->processes !== [] && $this->quietPumps < self::QUIET_PUMPS;
    }

    /**
     * Stop firing due callbacks once the program is told to quit.
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
                $process->askToStop();
            } catch (Throwable) {
            }
        }
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
     * Use the first matching handler and retain its cleanup under the command's effect ID.
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
     * Buffer synchronous emissions until cleanup is registered, so a resulting cancellation can find the effect.
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
        $this->quietPumps = 0;
        if ($scope !== null) {
            $this->processScopes[$process] = $scope;
        }
    }

    /**
     * Cancel an existing effect with this ID before starting its replacement, preventing duplicate messages.
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
     * Replacement drops queued results from the old run; cancellation alone still delivers results already queued.
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
        $this->timers = array_filter(
            $this->timers,
            static fn (TimerRecord $timer): bool => !$matches($timer->id, $timer->scope),
        );

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
        $stopping = [];
        foreach ($this->processes as $process) {
            if (!$matches($process->id(), $this->processScopes[$process] ?? null)) {
                $remaining[] = $process;
                continue;
            }
            $stopping[] = $process;
            try {
                $process->askToStop();
            } catch (Throwable) {
            }
        }
        foreach ($stopping as $process) {
            try {
                $process->stop();
            } catch (Throwable $error) {
                $this->report(new ErrorMsg($error, ErrorSource::Cancel, $process->id()), $wrap);
            }
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
            if ($ticker->id === null && $ticker->interval === $interval && self::sameWrap($ticker->wrap, $wrap) && $ticker->scope === $scope) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare closure code and captured values because mapping and mounting create new closures on each dispatch.
     */
    private static function sameWrap(?Closure $one, ?Closure $other): bool
    {
        if ($one === $other) {
            return true;
        }
        if ($one === null || $other === null) {
            return false;
        }

        $first = new ReflectionFunction($one);
        $second = new ReflectionFunction($other);
        if ($first->getFileName() !== $second->getFileName()
            || $first->getStartLine() !== $second->getStartLine()
            || $first->getName() !== $second->getName()
            || $first->getClosureThis() !== $second->getClosureThis()
        ) {
            return false;
        }

        $firstCaptured = $first->getClosureUsedVariables();
        $secondCaptured = $second->getClosureUsedVariables();
        if (array_keys($firstCaptured) !== array_keys($secondCaptured)) {
            return false;
        }
        foreach ($firstCaptured as $name => $value) {
            $otherValue = $secondCaptured[$name];
            $same = $value instanceof Closure && $otherValue instanceof Closure ? self::sameWrap($value, $otherValue) : $value === $otherValue;
            if (!$same) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Closure():((Message|Cmd|null)) $then
     * @param Closure(Message):Message|null  $wrap
     */
    private function registerTimer(float $delay, Closure $then, ?EffectId $id, ?Closure $wrap, ?string $scope): void
    {
        $timer = new TimerRecord($this->clock->now() + $delay, $then, $id, $wrap, $scope);
        $this->timers[spl_object_id($timer)] = $timer;
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

    private function pumpProcesses(bool $onlyOverdue): void
    {
        $remaining = [];
        if (!$onlyOverdue) {
            ++$this->quietPumps;
        }
        foreach ($this->processes as $process) {
            if ($onlyOverdue && ($process->secondsLeft() ?? 1.0) >= 0.0) {
                $remaining[] = $process;
                continue;
            }
            $scope = $this->processScopes[$process] ?? null;
            try {
                foreach ($process->pump() as $message) {
                    $this->queue($message, $process->id(), $scope);
                    $this->quietPumps = 0;
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
            if (!isset($this->timers[spl_object_id($timer)])) {
                continue;
            }
            unset($this->timers[spl_object_id($timer)]);

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
