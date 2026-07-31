<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Layout\Constraint;

final readonly class Column
{
    private function __construct(
        private string $title,
        private Constraint $constraint,
    ) {
    }

    public static function new(string $title, Constraint $constraint): self
    {
        return new self($title, $constraint);
    }

    public function title(): string
    {
        return $this->title;
    }

    public function constraint(): Constraint
    {
        return $this->constraint;
    }
}
