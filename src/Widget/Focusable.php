<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * Marks a widget the keyboard can land on. Whether it holds the keyboard is the
 * tree's to say, asked of the RenderContext while drawing.
 */
interface Focusable extends Interactive
{
}
