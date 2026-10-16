<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Elmphant\Tui\Support\NamedIdentifier;
use Stringable;

final readonly class EffectId implements Stringable
{
    use NamedIdentifier;

    private const LABEL = 'Effect ID';
}
