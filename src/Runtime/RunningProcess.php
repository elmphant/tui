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
     * Until its timeout runs out, negative once it has; null without one.
     */
    public function secondsLeft(): ?float;

    public function stop(): void;

    public function id(): ?EffectId;
}
