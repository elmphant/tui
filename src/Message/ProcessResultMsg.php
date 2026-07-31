<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\EffectId;

final readonly class ProcessResultMsg implements Message
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
        public bool $successful,
        public ?EffectId $processId = null,
    ) {
    }
}
