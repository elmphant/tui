<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;

/**
 * @internal
 */
interface RunningProcess
{
    /**
     * @return list<Message>
     */
    public function pump(): array;

    public function map(Message $message): Message;

    public function isRunning(): bool;

    /**
     * Negative once the timeout has passed; null when there is no timeout.
     */
    public function secondsLeft(): ?float;

    /**
     * Sends the stop signal without waiting, so several processes can share one grace period.
     */
    public function askToStop(): void;

    public function stop(): void;

    public function id(): ?EffectId;
}
