<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use RuntimeException;

final class EffectOutputLimitExceeded extends RuntimeException
{
    public function __construct(public readonly int $limitBytes, string $effect)
    {
        parent::__construct(sprintf('%s output exceeded the configured %d-byte limit', $effect, $limitBytes));
    }
}
