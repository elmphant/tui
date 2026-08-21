<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\AfterCmd;
use Elmphant\Tui\Core\CancelCmd;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\ExecCmd;
use Elmphant\Tui\Core\HttpCmd;
use Elmphant\Tui\Core\ListenCmd;
use Elmphant\Tui\Core\ProcessCmd;
use Elmphant\Tui\Core\TickCmd;
use Elmphant\Tui\Message\ErrorMsg;
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
     * @param SplQueue<Message>             $results  where an effect reports what it produced
     * @param Closure(?Cmd, ?Closure): void $dispatch a timer's callback may answer with a command,
     *                                                and that command may be any command at all
     * @param Closure(): void               $wake     a stream that became readable has to reach the loop
     */
    public function __construct(
        private SplQueue $results,
        private Clock $clock,
        private Closure $dispatch,
        private Closure $wake,
        private ProcessStarter $processStarter = new ProcessStarter(),
        private HttpRequestStarter $requestStarter = new HttpRequestStarter(),
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
                'http',
                $command->id,
                $wrap,
            ),
            $command instanceof ExecCmd => $this->keep(
                fn (): RunningProcess => $this->processStarter->exec($command, $wrap),
                'process',
                $command->id,
                $wrap,
            ),
            $command instanceof ProcessCmd => $this->keep(
                fn (): RunningProcess => $this->processStarter->streaming($command, $wrap),
                'process',
                $command->id,
                $wrap,
            ),
            $command instanceof ListenCmd => $this->subscribeToStream($command, $wrap),
            default => throw new LogicException(sprintf('Unknown command type "%s" — the runtime has no dispatcher for it', $command::class)),
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
    }

    /**
     * Hold on to what a starter began, or say why it could not begin. Starting is
     * the starter's to know; what a failure is worth saying is not.
     *
     * @param Closure(): RunningProcess      $start
     * @param Closure(Message): Message|null $wrap
     */
    private function keep(Closure $start, string $source, ?EffectId $id, ?Closure $wrap): void
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
     * application can tell apart, and a ticker re-registered on every config
     * change would silently multiply.
     *
     * @param Closure(Message): Message|null $wrap
     */
    private function makeRoomFor(Cmd $command, ?Closure $wrap): void
    {
        $id = match (true) {
            $command instanceof CancelCmd => null,
            $command instanceof TickCmd,
            $command instanceof AfterCmd,
            $command instanceof HttpCmd,
            $command instanceof ExecCmd,
            $command instanceof ListenCmd,
            $command instanceof ProcessCmd => $command->id,
            default => null,
        };

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
            $processId = $process->id();
            if ($processId !== null && $processId->equals($id)) {
                try {
                    $process->stop();
                } catch (Throwable $error) {
                    $this->report(new ErrorMsg($error, 'cancel', $id), $wrap);
                }
                continue;
            }
            $remaining[] = $process;
        }
        $this->processes = $remaining;
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    private function registerTicker(float $interval, ?EffectId $id = null, ?Closure $wrap = null): void
    {
        $now = $this->clock->now();
        $this->tickers[] = new TickerRecord($now + $interval, $interval, $id, $wrap);
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
                $this->results->enqueue($process->map(new ErrorMsg($error, 'effect', $process->id())));
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
                $this->report(new ErrorMsg($error, 'listen', $subscription->id), $subscription->wrap);
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

        $remaining = [];
        foreach ($this->timers as $timer) {
            if ($now >= $timer->fireAt) {
                try {
                    $result = ($timer->callback)();
                } catch (Throwable $error) {
                    $this->report(new ErrorMsg($error, 'timer', $timer->id), $timer->wrap);
                    continue;
                }
                if ($result instanceof Cmd) {
                    ($this->dispatch)($result, $timer->wrap);
                } elseif ($result instanceof Message) {
                    $this->report($result, $timer->wrap);
                } elseif ($result !== null) {
                    $this->report(new ErrorMsg(
                        new UnexpectedValueException('Cmd::after() callback must return Message, Cmd, or null'),
                        'timer',
                        $timer->id,
                    ), $timer->wrap);
                }
            } else {
                $remaining[] = $timer;
            }
        }
        $this->timers = $remaining;
    }

    /**
     * @param Closure(Message): Message|null $wrap
     */
    private function report(Message $message, ?Closure $wrap): void
    {
        $this->results->enqueue($wrap === null ? $message : $wrap($message));
    }
}
