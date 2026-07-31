<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;

/**
 * @internal input framing result consumed by Program
 */
final readonly class InputParseResult
{
    /**
     * @param list<KeyMsg|MouseMsg> $messages
     */
    public function __construct(
        public array $messages,
        public string $remainder = '',
    ) {
    }
}
