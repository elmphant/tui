<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;

/**
 * @internal
 */
final readonly class TimerRecord
{
    /**
     * @param Closure(): mixed              $callback
     * @param Closure(Message):Message|null $wrap
     */
    public function __construct(
        public float $fireAt,
        public Closure $callback,
        public ?EffectId $id = null,
        public ?Closure $wrap = null,
    ) {
    }
}
