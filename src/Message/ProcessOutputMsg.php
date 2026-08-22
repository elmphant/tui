<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\EffectId;

final readonly class ProcessOutputMsg implements Message
{
    public function __construct(
        public string $output,
        public ProcessStream $stream = ProcessStream::Stdout,
        public ?EffectId $effectId = null,
    ) {
    }
}
