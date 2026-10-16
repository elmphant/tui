<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;

final readonly class ProcessResultMsg implements Message
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
        public bool $successful,
        public ?EffectId $effectId = null,
    ) {
    }
}
