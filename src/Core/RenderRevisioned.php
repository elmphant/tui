<?php

declare(strict_types=1);

namespace Elmphant\Tui\Core;

/**
 * Opt-in marker for models whose render output depends on a coarse revision
 * number rather than full instance identity.
 *
 * The Program runtime treats `$result->model !== $this->model` as a trigger
 * to re-render. For models where many update cycles produce a new instance
 * but no visible change (analytics counters, debounce timers, internal
 * caches), implement this interface and return a value that increments only
 * when the rendered output would actually differ. The runtime then skips
 * the re-render when `renderRevision()` is unchanged across the update.
 *
 * Implementations must be deterministic: two consecutive calls on the same
 * conceptual state must return the same value.
 */
interface RenderRevisioned
{
    public function renderRevision(): int;
}
