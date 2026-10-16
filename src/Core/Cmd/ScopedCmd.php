<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\Cmd;

/**
 * @internal
 */
final readonly class ScopedCmd extends Cmd
{
    public function __construct(
        public Cmd $inner,
        public string $scope,
    ) {
    }
}
