<?php

declare(strict_types=1);

namespace Elmphant\Tui\Input;

final readonly class KeyHint
{
    /**
     * @param ?KeyPattern $key null when only the label is known
     */
    public function __construct(
        public string $label,
        public string $description,
        public ?KeyPattern $key = null,
    ) {
    }
}
