<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cmd;

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
