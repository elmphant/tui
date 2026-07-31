<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Widget\Interactive;

/**
 * @internal
 *
 * @template-covariant TChild of Interactive
 */
final readonly class DispatchResult
{
    /**
     * @param TChild $child
     */
    public function __construct(
        public Interactive $child,
        public ?Cmd $command,
        public bool $changed,
    ) {
    }
}
