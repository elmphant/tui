<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\Message;

/**
 * @internal
 */
final readonly class TickerRecord
{
    /**
     * @param Closure(Message): Message|null $wrap
     */
    public function __construct(
        public float $fireAt,
        public float $interval,
        public ?EffectId $id = null,
        public ?Closure $wrap = null,
    ) {
    }

    public function rescheduled(float $now): self
    {
        return new self($now + $this->interval, $this->interval, $this->id, $this->wrap);
    }
}
