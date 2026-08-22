<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * What an option is drawn with.
 *
 * Parentheses for one of many, brackets for one that stands alone, so the shape
 * says which control it is before the mark says what its state is. All four
 * take the same columns, so a form mixing them lines its labels up.
 */
final class OptionMarks
{
    public const SELECTED = '(●) ';
    public const UNSELECTED = '( ) ';
    public const CHECKED = '[x] ';
    public const UNCHECKED = '[ ] ';
}
