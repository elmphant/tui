<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Listing;

/**
 * Lets a record of the app be a ListItem by answering only title() and key().
 */
trait ListItemBehavior
{
    public function description(): string
    {
        return '';
    }

    public function filterValue(): string
    {
        return $this->title();
    }

    public function measureHeight(int $width): int
    {
        return ListItemLayout::height($this, $width);
    }
}
