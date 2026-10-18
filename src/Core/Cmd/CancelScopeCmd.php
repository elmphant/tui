<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core\Cmd;

use Elmphant\Tui\Core\Cmd;

/**
 * @internal cancels every named effect started in the scope it is dispatched in
 */
final readonly class CancelScopeCmd extends Cmd
{
}
