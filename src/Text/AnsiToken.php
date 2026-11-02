<?php

declare(strict_types=1);

namespace Elmphant\Tui\Text;

/**
 * @internal
 */
final readonly class AnsiToken
{
    public function __construct(
        public AnsiTokenType $type,
        public string $content,
    ) {
    }
}
