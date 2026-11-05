<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use RuntimeException;

/**
 * What Cmd::fork() work threw in its own process. An exception does not cross processes safely, so it arrives as text.
 */
final class ForkedWorkFailed extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $thrownClass,
        public readonly string $thrownTrace,
    ) {
        parent::__construct($message);
    }
}
