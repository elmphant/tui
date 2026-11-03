<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\FocusMove;
use Elmphant\Tui\Core\Message;

/**
 * Send only when focus moves, or containers may reset their child position between keystrokes.
 * `move` records the direction of arrival.
 */
final readonly class FocusMsg implements Message
{
    /**
     * @internal the runtime sends it
     */
    public function __construct(
        public FocusMove $move = FocusMove::To,
    ) {
    }
}
