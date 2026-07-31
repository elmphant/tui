<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

use Stringable;

final readonly class EffectId implements Stringable
{
    use NamedIdentifier;

    private const LABEL = 'Effect ID';
}
