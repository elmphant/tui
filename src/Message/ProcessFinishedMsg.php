<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;

final readonly class ProcessFinishedMsg implements Message
{
    use ComesFromAnEffect;

    /**
     * @param ?int           $exitCode null when the process reported none
     * @param ?ProcessTiming $timing   null when the process ran without a clock
     */
    public function __construct(
        public ?int $exitCode,
        public ?EffectId $effectId = null,
        public ?ProcessTiming $timing = null,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
