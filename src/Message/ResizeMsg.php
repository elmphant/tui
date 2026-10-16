<?php

declare(strict_types=1);

namespace Elmphant\Tui\Message;

use Elmphant\Tui\Core\Message;
use InvalidArgumentException;

final readonly class ResizeMsg implements Message
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
        if ($width < 0 || $height < 0) {
            throw new InvalidArgumentException("A terminal cannot be {$width}x{$height}");
        }
    }
}
