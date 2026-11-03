<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

/**
 * Return the cursor's zero-based row within logical content, including labels, so containers can keep it visible.
 */
interface CursorAware
{
    public function cursorLine(): int;
}
