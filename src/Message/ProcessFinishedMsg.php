<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;

final readonly class ProcessFinishedMsg implements Message
{
    use ComesFromAnEffect;

    /**
     * @param ?float $startedAt  on the program's clock, the one TickMsg timestamps use
     * @param ?float $finishedAt on the same clock
     */
    public function __construct(
        public int $exitCode,
        public bool $successful,
        public ?EffectId $effectId = null,
        public ?float $startedAt = null,
        public ?float $finishedAt = null,
    ) {
    }
}
