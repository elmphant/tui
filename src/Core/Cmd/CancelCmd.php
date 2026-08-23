<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;

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
