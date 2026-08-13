<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Constraint;

final readonly class Column
{
    private function __construct(
        private string $title,
        private Constraint $width,
    ) {
    }

    public static function new(string $title, Constraint $width): self
    {
        return new self($title, $width);
    }

    public function title(): string
    {
        return $this->title;
    }

    public function width(): Constraint
    {
        return $this->width;
    }
}
