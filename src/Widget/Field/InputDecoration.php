<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Field;

/**
 * Keep underline and fill mutually exclusive to avoid a heavy block that conflicts with selection backgrounds.
 */
enum InputDecoration
{
    /**
     * A rule under the whole field width, like a web form field.
     */
    case Underline;

    /**
     * The theme's input background across the whole field width.
     */
    case Filled;

    /**
     * No bounds marker; the field is only as wide as its text.
     */
    case None;
}
