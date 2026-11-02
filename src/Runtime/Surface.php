<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;

/**
 * @internal
 */
interface Surface
{
    public function area(Rect $terminal, Model $model): Rect;

    /**
     * @param Rect $area where the model drew; anything below it in the frame is a popup
     */
    public function flush(Frame $frame, Rect $area): void;

    public function forget(): void;

    /**
     * @param list<string> $lines
     */
    public function printAbove(array $lines, int $width): void;

    /**
     * @param Closure(): void $drawLastFrame
     */
    public function finish(Closure $drawLastFrame): void;

    /**
     * Where the cursor waits while the program is suspended.
     */
    public function pause(): string;

    /**
     * Where the cursor goes when the program hands the terminal back for good.
     */
    public function leave(): string;
}
