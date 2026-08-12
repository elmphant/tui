<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan\Fixtures;

final readonly class PositionalRebuilder
{
    public function __construct(
        public string $label = '',
        public int $width = 0,
        public bool $wrap = false,
    ) {
    }

    public function withLabel(string $label): self
    {
        return new self($label, $this->width, $this->wrap);
    }
}
