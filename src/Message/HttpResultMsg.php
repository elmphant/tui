<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Core\Message;

final readonly class HttpResultMsg implements Message
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers = [],
        public ?EffectId $effectId = null,
    ) {
    }
}
