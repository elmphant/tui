<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Tree;

final readonly class SimpleTreeNode implements TreeNode
{
    /**
     * @param list<TreeNode>|null $children null where they are not loaded yet
     */
    public function __construct(
        private string $key,
        private string $label,
        private string $detail = '',
        private ?array $children = [],
        private bool $expandable = false,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function detail(): string
    {
        return $this->detail;
    }

    public function isExpandable(): bool
    {
        return $this->expandable || $this->children === null || $this->children !== [];
    }

    public function children(): ?array
    {
        return $this->children;
    }
}
