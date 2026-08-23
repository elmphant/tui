<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Field;

/**
 * How a text field shows its bounds.
 *
 * Underline and Filled are mutually exclusive: combining them reads as a heavy
 * block and collides with selection and bar backgrounds.
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
