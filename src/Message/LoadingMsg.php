<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\Message;

final readonly class LoadingMsg implements Message
{
    public function __construct(
        public bool $loading,
        public string $label = '',
    ) {
    }

    public static function start(string $label = 'Loading…'): self
    {
        return new self(true, $label);
    }

    public static function stop(): self
    {
        return new self(false);
    }
}
