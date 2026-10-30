<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

/**
 * Increment renderRevision() only when visible output changes; unchanged revisions skip redraws despite a new model instance.
 * Return the same revision for the same state.
 */
interface RenderRevisioned
{
    public function renderRevision(): int;
}
