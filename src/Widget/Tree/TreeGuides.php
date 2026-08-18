<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Tree;

/**
 * The characters a tree draws its branches with, chosen the way a border style
 * is: whether a branch is the last of its siblings is the only thing that
 * changes them.
 */
enum TreeGuides
{
    case Square;
    case Rounded;

    public function branch(bool $isLastChild): string
    {
        if (!$isLastChild) {
            return '├─';
        }

        return match ($this) {
            self::Square => '└─',
            self::Rounded => '╰─',
        };
    }

    /**
     * What a deeper level draws in the columns this branch occupies.
     */
    public function trunk(bool $isLastChild): string
    {
        return $isLastChild ? '  ' : '│ ';
    }
}
