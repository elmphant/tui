<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\AfterCmd;
use Elmphant\Tui\Core\Cmd\CancelCmd;
use Elmphant\Tui\Core\Cmd\ExecCmd;
use Elmphant\Tui\Core\Cmd\HttpCmd;
use Elmphant\Tui\Core\Cmd\ListenCmd;
use Elmphant\Tui\Core\Cmd\ProcessCmd;
use Elmphant\Tui\Core\Cmd\TickCmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\NamesItsEffect;
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Message\ErrorSource;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\TickMsg;
use LogicException;
use Revolt\EventLoop;
use SplQueue;
use Throwable;
use UnexpectedValueException;

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

    private bool $startingAHandler = false;

    private bool $emittedWhileStarting = false;

    /**
     * @param SplQueue<Message>             $results  where an effect reports what it produced
     * @param Closure(?Cmd, ?Closure): void $dispatch a timer's callback may answer with a command,
     *                                                and that command may be any command at all
     * @param Closure(): void               $wake     a stream that became readable has to reach the loop
     * @param list<CommandHandler>          $handlers what runs a command the runtime has no effect for
     */
    public function __construct(
        private readonly SplQueue $results,
        private readonly Clock $clock,
        private readonly Closure $dispatch,
        private readonly Closure $wake,
        private readonly ProcessStarter $processStarter = new ProcessStarter(),
        private readonly HttpRequestStarter $requestStarter = new HttpRequestStarter(),
        private readonly array $handlers = [],
    ) {
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    public function run(Cmd $command, ?Closure $wrap): void
    {
        $this->makeRoomFor($command, $wrap);

        match (true) {
            $command instanceof TickCmd => $this->registerTicker($command->interval, $command->id, $wrap),
            $command instanceof AfterCmd => $this->registerTimer($command->delay, $command->then, $command->id, $wrap),
            $command instanceof CancelCmd => $this->cancel($command->id, $wrap),
            $command instanceof HttpCmd => $this->keep(
                fn (): RunningProcess => $this->requestStarter->start($command, $wrap),
                ErrorSource::Http,
                $command->id,
                $wrap,
            ),
            $command instanceof ExecCmd => $this->keep(
                fn (): RunningProcess => $this->processStarter->exec($command, $wrap),
                ErrorSource::Process,
                $command->id,
                $wrap,
            ),
            $command instanceof ProcessCmd => $this->keep(
                fn (): RunningProcess => $this->processStarter->streaming($command, $wrap),
                ErrorSource::Process,
                $command->id,
                $wrap,
            ),
            $command instanceof ListenCmd => $this->subscribeToStream($command, $wrap),
            default => $this->delegate($command, $wrap),
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

    /**
     * A command of the application's own: the first handler that claims it runs
     * it, and whatever it answers with is held under the command's name so a
     * cancellation, a replacement or a shutdown reaches it like any other effect.
     *
     * @param Closure(Message): Message|null $wrap
     */
    private function delegate(Cmd $command, ?Closure $wrap): void
    {
        $id = $command instanceof NamesItsEffect ? $command->effectId() : null;

        foreach ($this->handlers as $handler) {
            if (!$handler->handles($command)) {
                continue;
            }

            // What the handler emits before start() returns waits for its stop closure
            // to be registered, or a cancel answering that message would find nothing.
            $this->startingAHandler = true;
            $this->emittedWhileStarting = false;
            $effect = new HandledEffect($id);
            try {
                $effect->stopper = $handler->start($command, function (Message $message) use ($wrap, $effect): void {
                    if ($effect->over) {
                        return;
                    }
                    $this->report($message, $wrap);
                    if ($this->startingAHandler) {
                        $this->emittedWhileStarting = true;

                        return;
                    }
                    ($this->wake)();
                }, function () use ($effect): void {
                    $effect->over = true;
                    $this->handled = array_values(array_filter(
                        $this->handled,
                        static fn (HandledEffect $held): bool => $held !== $effect,
                    ));
                });
            } catch (Throwable $error) {
                $this->report(new ErrorMsg($error, ErrorSource::Command, $id), $wrap);

                return;
            } finally {
                $this->startingAHandler = false;
            }

            if ($effect->stopper !== null && !$effect->over) {
                $this->handled[] = $effect;
            }
            if ($this->emittedWhileStarting) {
                ($this->wake)();
            }

            return;
        }

        throw new LogicException(sprintf('Unknown command type "%s" — the runtime has no dispatcher for it', $command::class));
    }

    /**
     * Hold on to what a starter began, or say why it could not begin. Starting is
     * the starter's to know; what a failure is worth saying is not.
     *
     * @param Closure(): RunningProcess      $start
     * @param Closure(Message): Message|null $wrap
     */
    private function keep(Closure $start, ErrorSource $source, ?EffectId $id, ?Closure $wrap): void
    {
        try {
            $this->processes[] = $start();
        } catch (Throwable $error) {
            $this->report(new ErrorMsg($error, $source, $id), $wrap);
        }
    }

    /**
     * An id is one slot: whatever still holds it goes before the replacement
     * starts. Two effects answering to the same name would send messages no
     * host can tell apart, and a ticker re-registered on every config
     * change would silently multiply.
     *
     * @param Closure(Message): Message|null $wrap
     */
    private function makeRoomFor(Cmd $command, ?Closure $wrap): void
    {
        $id = $command instanceof NamesItsEffect ? $command->effectId() : null;
        if ($id !== null) {
            $this->cancel($id, $wrap);
        }
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    private function cancel(EffectId $id, ?Closure $wrap = null): void
    {
        $this->tickers = array_values(array_filter(
            $this->tickers,
            static fn (TickerRecord $ticker): bool => $ticker->id === null || !$ticker->id->equals($id),
        ));
        $this->timers = array_values(array_filter(
            $this->timers,
            static fn (TimerRecord $timer): bool => $timer->id === null || !$timer->id->equals($id),
        ));

        $keptSubscriptions = [];
        foreach ($this->subscriptions as $subscription) {
            if ($subscription->id !== null && $subscription->id->equals($id)) {
                $this->stopWatching($subscription);
                continue;
            }
            $keptSubscriptions[] = $subscription;
        }
        $this->subscriptions = $keptSubscriptions;

        $remaining = [];
        foreach ($this->processes as $process) {
            $effectId = $process->id();
            if ($effectId !== null && $effectId->equals($id)) {
                try {
                    $process->stop();
                } catch (Throwable $error) {
                    $this->report(new ErrorMsg($error, ErrorSource::Cancel, $id), $wrap);
                }
                continue;
            }
            $remaining[] = $process;
        }
        $this->processes = $remaining;

        $keptHandled = [];
        foreach ($this->handled as $effect) {
            if ($effect->id !== null && $effect->id->equals($id)) {
                $this->stopHandled($effect, $wrap);
                continue;
            }
            $keptHandled[] = $effect;
        }
        $this->handled = $keptHandled;
    }

    /**
     * @param Closure(Message): Message|null $wrap
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
     * @param Closure(Message): Message|null $wrap
     */
    private function registerTicker(float $interval, ?EffectId $id = null, ?Closure $wrap = null): void
    {
        if ($id === null && $this->hasAnonymousTicker($interval, $wrap)) {
            return;
        }
        $now = $this->clock->now();
        $this->tickers[] = new TickerRecord($now + $interval, $interval, $id, $wrap);
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    private function hasAnonymousTicker(float $interval, ?Closure $wrap): bool
    {
        foreach ($this->tickers as $ticker) {
            if ($ticker->id === null && $ticker->interval === $interval && $ticker->wrap === $wrap) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Closure(): (Message|Cmd|null)  $then
     * @param Closure(Message): Message|null $wrap
     */
    private function registerTimer(float $delay, Closure $then, ?EffectId $id = null, ?Closure $wrap = null): void
    {
        $this->timers[] = new TimerRecord($this->clock->now() + $delay, $then, $id, $wrap);
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    private function subscribeToStream(ListenCmd $command, ?Closure $wrap = null): void
    {
        $subscription = new StreamSubscription($command->stream, $command->onReadable, $command->id, $wrap);
        $subscription->watcherId = EventLoop::onReadable($command->stream, function () use ($subscription): void {
            $subscription->readable = true;
            ($this->wake)();
        });
        $this->subscriptions[] = $subscription;
    }

    private function pumpProcesses(): void
    {
        if ($this->processes === []) {
            return;
        }

        $remaining = [];
        foreach ($this->processes as $process) {
            try {
                foreach ($process->pump() as $message) {
                    $this->results->enqueue($message);
                }
            } catch (Throwable $error) {
                $this->results->enqueue($process->map(new ErrorMsg($error, ErrorSource::Process, $process->id())));
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
        if ($this->subscriptions === []) {
            return;
        }

        $remaining = [];
        foreach ($this->subscriptions as $subscription) {
            if (!$subscription->readable) {
                $remaining[] = $subscription;
                continue;
            }
            $subscription->readable = false;

            try {
                foreach ($subscription->read() as $message) {
                    $this->report($message, $subscription->wrap);
                }
            } catch (Throwable $error) {
                $this->report(new ErrorMsg($error, ErrorSource::Listen, $subscription->id), $subscription->wrap);
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

            $this->report(new TickMsg($now), $ticker->wrap);
            $kept[] = $ticker->rescheduled($now);
        }

        $this->tickers = $kept;
    }

    private function fireTimers(float $now): void
    {
        if ($this->timers === []) {
            return;
        }

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
                $this->report(new ErrorMsg($error, ErrorSource::Timer, $timer->id), $timer->wrap);
                continue;
            }
            if ($result instanceof Cmd) {
                ($this->dispatch)($result, $timer->wrap);
            } elseif ($result instanceof Message) {
                $this->report($result, $timer->wrap);
            } elseif ($result !== null) {
                $this->report(new ErrorMsg(
                    new UnexpectedValueException('Cmd::after() callback must return Message, Cmd, or null'),
                    ErrorSource::Timer,
                    $timer->id,
                ), $timer->wrap);
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
     * @param Closure(Message): Message|null $wrap
     */
    private function report(Message $message, ?Closure $wrap): void
    {
        $this->results->enqueue($wrap === null ? $message : $wrap($message));
    }
}
