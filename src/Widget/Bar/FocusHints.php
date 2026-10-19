<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Bar;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Widget;

/**
 * The keys whatever holds the keyboard answers, asked for at render time so no
 * one has to keep a copy of where the keyboard is.
 */
final readonly class FocusHints implements Widget
{
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
        return new self(leading: $hints);
    }

    public function sizing(): Sizing
    {
        return StatusBar::forHints($this->leading)->sizing();
    }

    /**
     * The width the hints take while drawing: `sizing()` cannot know who holds the keyboard.
     */
    public function widthIn(RenderContext $context): int
    {
        return $this->bar($context)->sizing()->columns->intrinsic();
    }

    /**
     * The width with these hints of whatever holds the keyboard, for a host asking outside drawing.
     *
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
            ? StatusBar::forHints([...$this->leading, ...$context->focusedHints()])
            : StatusBar::forHints([$chord->typed . '…' => '', ...$chord->continuations, 'Esc' => 'cancel']);
    }
}
