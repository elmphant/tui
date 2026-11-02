<?php

declare(strict_types=1);

namespace Elmphant\Tui\Runtime;

use Closure;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Screen;
use Elmphant\Tui\Runtime\Trace\TraceEvent;
use Elmphant\Tui\Runtime\Trace\Tracer;
use Elmphant\Tui\Terminal\Ansi;
use Elmphant\Tui\Terminal\Terminal;

/**
 * @internal
 */
final readonly class FullScreenSurface implements Surface
{
    public function __construct(
        private Terminal $terminal,
        private Screen $screen,
        private bool $altScreen,
        private ?Tracer $tracer = null,
    ) {
    }

    public function area(Rect $terminal, Model $model): Rect
    {
        return $terminal;
    }

    public function flush(Frame $frame, Rect $area): void
    {
        $this->screen->flush($frame);
    }

    public function forget(): void
    {
        $this->screen->forget();
    }

    public function printAbove(array $lines, int $width): void
    {
        $this->tracer?->record(TraceEvent::Log, ['message' => 'Cmd::println() prints only in an inline program', 'lines' => $lines]);
    }

    public function finish(Closure $drawLastFrame): void
    {
    }

    public function pause(): string
    {
        return '';
    }

    public function leave(): string
    {
        if ($this->altScreen) {
            return '';
        }

        [, $height] = $this->terminal->size();

        return Ansi::cursorToRowStart(max(1, $height) - 1) . "\n";
    }
}
