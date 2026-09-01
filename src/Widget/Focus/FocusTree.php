<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Focus;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\MouseMsg;

final readonly class FocusTree
{
    use Cloneable;

    /**
     * $active is null while nothing holds the focus yet, which is where a tree
     * starts before a reader has reached it.
     */
    public function __construct(
        private int $count = 0,
        private ?int $active = 0,
    ) {
    }

    public function active(): int
    {
        return $this->active ?? 0;
    }

    public function wouldWrap(bool $reverse): bool
    {
        if ($this->count <= 1) {
            return true;
        }

        return $reverse ? $this->active === 0 : $this->active === $this->count - 1;
    }

    public function next(): self
    {
        if ($this->count === 0) {
            return $this;
        }

        return $this->cloneWith(active: $this->active === null ? 0 : ($this->active + 1) % $this->count);
    }

    public function previous(): self
    {
        if ($this->count === 0) {
            return $this;
        }

        return $this->cloneWith(active: $this->active === null
            ? $this->count - 1
            : ($this->active - 1 + $this->count) % $this->count);
    }

    public function focusAt(int $index): self
    {
        if ($index < 0 || $index >= $this->count) {
            return $this;
        }

        return $this->cloneWith(active: $index);
    }

    public function focusAtEnd(): self
    {
        if ($this->count === 0) {
            return $this;
        }

        return $this->cloneWith(active: $this->count - 1);
    }

    public function focusByClick(MouseMsg $message, Rect ...$areas): self
    {
        foreach ($areas as $index => $area) {
            if ($index < $this->count && $message->inRect($area)) {
                return $this->cloneWith(active: $index);
            }
        }

        return $this;
    }
}
