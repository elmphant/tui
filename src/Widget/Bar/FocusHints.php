<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Bar;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Widget;

/**
 * Read active key hints at render time so they cannot become stale: the app's actions, then what holds the keyboard.
 */
final readonly class FocusHints implements Widget
{
    use Cloneable;

    /**
     * @param array<string, string> $leading
     */
    private function __construct(private array $leading = [])
    {
    }

    public static function new(): self
    {
        return new self();
    }

    /**
     * @param array<string, string> $hints
     */
    public function withLeadingHints(array $hints): self
    {
        return $this->cloneWith(leading: $hints);
    }

    public function sizing(): Sizing
    {
        return StatusBar::forHints($this->leading)->sizing();
    }

    public function widthIn(RenderContext $context): int
    {
        return $this->bar($context)->sizing()->columns->intrinsic();
    }

    /**
     * @param array<string, string> $focusedHints
     */
    public function widthWith(array $focusedHints): int
    {
        return StatusBar::forHints([...$this->leading, ...$focusedHints])->sizing()->columns->intrinsic();
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->bar($context)->render($frame, $area, $context);
    }

    private function bar(RenderContext $context): StatusBar
    {
        $chord = $context->pendingChord();

        return $chord === null
            ? StatusBar::forHints([...$this->leading, ...$context->focusedHints()])->withPinned($context->keyHelpHint())
            : StatusBar::forHints([$chord->typed . '…' => '', ...$chord->continuations, 'Esc' => 'cancel']);
    }
}
