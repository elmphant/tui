<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;

final readonly class ProcessResultMsg implements Message
{
    use ComesFromAnEffect;

    /**
     * @param ?int $exitCode null when the process reported none
     */
    public function __construct(
        public string $stdout,
        public string $stderr,
        public ?int $exitCode,
        public ?EffectId $effectId = null,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
