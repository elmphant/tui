<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Popup;

use Elmphant\Tui\Core\Message;

/**
 * Identify the dismissed modal by its component address; titles are not unique.
 */
final readonly class ModalDismissed implements Message
{
}
