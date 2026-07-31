<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

/**
 * @internal
 */
final readonly class CancelCmd extends Cmd
{
    public function __construct(
        public EffectId $id,
    ) {
    }
}
