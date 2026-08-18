<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Tree;

/**
 * Where a node sits, as the keys leading to it.
 *
 * The cursor and the expanded set are addressed this way rather than by row
 * number, so expanding a node above the cursor cannot move it.
 */
final readonly class TreePath
{
    /**
     * @var list<string>
     */
    public array $keys;

    public function __construct(string ...$keys)
    {
        $this->keys = array_values($keys);
    }

    public static function root(): self
    {
        return new self();
    }

    public function child(string $key): self
    {
        return new self(...[...$this->keys, $key]);
    }

    public function parent(): self
    {
        return new self(...array_slice($this->keys, 0, -1));
    }

    public function isRoot(): bool
    {
        return $this->keys === [];
    }
}
