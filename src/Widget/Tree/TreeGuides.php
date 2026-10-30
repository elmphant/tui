<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Tree;

/**
 * Choose branch glyphs by style and whether the node is its parent's last child.
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

    public function trunk(bool $isLastChild): string
    {
        return $isLastChild ? '  ' : '│ ';
    }
}
