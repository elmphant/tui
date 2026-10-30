<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Choice;

/**
 * Use parentheses for single choice and brackets for independent toggles; equal widths keep labels aligned.
 */
final class OptionMarks
{
    public const SELECTED = '(●) ';
    public const UNSELECTED = '( ) ';
    public const CHECKED = '[x] ';
    public const UNCHECKED = '[ ] ';
}
