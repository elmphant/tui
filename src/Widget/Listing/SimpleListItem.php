<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Listing;

final readonly class SimpleListItem implements ListItem
{
    public function __construct(
        private string $title,
        private string $description = '',
        private ?string $key = null,
    ) {
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function filterValue(): string
    {
        return $this->title;
    }

    public function key(): string
    {
        return $this->key ?? $this->title;
    }

    public function height(int $width): int
    {
        return ListItemLayout::height($this, $width);
    }
}
