<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\EffectId;

final readonly class ProcessFinishedMsg implements Message
{
    public function __construct(
        public int $exitCode,
        public bool $successful,
        public ?EffectId $effectId = null,
    ) {
    }
}
