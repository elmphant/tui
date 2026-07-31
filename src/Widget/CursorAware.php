<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * Marker for widgets that have an internal "active row" / cursor.
 *
 * Scrollable container widgets (Panel, TabBar, ...) use this to keep the
 * cursor visible: after delegating input to a focused CursorAware child,
 * they query `cursorRow()` to compute the cursor's absolute Y and, if it
 * has moved outside the visible window, adjust their own scrollOffset.
 *
 * `cursorRow()` returns the 0-based row of the cursor within the widget's
 * own logical content (not within any buffer area). For a Radio with a
 * 2-row label and a cursor on option 3, that's 2 + 3 = 5.
 */
interface CursorAware
{
    public function cursorRow(): int;
}
