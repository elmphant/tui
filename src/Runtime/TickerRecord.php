<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;

/**
 * @internal
 */
final readonly class TickerRecord
{
    /**
     * @param Closure(Message):Message|null $wrap
     */
    public function __construct(
        public float $fireAt,
        public float $interval,
        public ?EffectId $id = null,
        public ?Closure $wrap = null,
        public ?string $scope = null,
    ) {
    }

    /**
     * Schedule from the previous deadline to avoid cumulative drift; skip missed intervals after a stall.
     */
    public function rescheduled(float $now): self
    {
        $missed = floor(($now - $this->fireAt) / $this->interval);

        return new self($this->fireAt + ($missed + 1) * $this->interval, $this->interval, $this->id, $this->wrap, $this->scope);
    }
}
