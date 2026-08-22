<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\EffectId;
use Throwable;

final readonly class ErrorMsg implements Message
{
    public function __construct(
        public Throwable $error,
        public ErrorSource $source,
        public ?EffectId $effectId = null,
    ) {
    }
}
