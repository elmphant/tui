<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Popup;

use Elmphant\Tui\Core\Message;

/**
 * A modal was dismissed. Which one is the component address's to say — a title
 * is what a modal is called, not which of them this was, and two modals may
 * share it.
 */
final readonly class ModalDismissed implements Message
{
}
