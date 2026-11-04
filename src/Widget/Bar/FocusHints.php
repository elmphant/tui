<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Bar;

use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Widget;

/**
 * Read active key hints at render time so they cannot become stale.
 */
final readonly class FocusHints implements Widget
{
    use Cloneable;

    /**
     * @param array<string, string>              $leading
     * @param array<string, string|list<string>> $keyDescriptions
     */
    private function __construct(
        private array $leading = [],
        private ?Keybindings $leadingKeys = null,
        private array $keyDescriptions = [],
    ) {
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

    /**
     * Hints for these keys lead the bar, worded like Keybindings::hints(). A key a focused field
     * would take as text is left out while it does, since it cannot fire then.
     *
     * @param array<string, string|list<string>> $descriptions description => the action(s) it covers
     */
    public function withLeadingKeys(Keybindings $keys, array $descriptions): self
    {
        return $this->cloneWith(leadingKeys: $keys, keyDescriptions: $descriptions);
    }

    public function sizing(): Sizing
    {
        return StatusBar::forHints($this->leadingIn(null))->sizing();
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
        return StatusBar::forHints([...$this->leadingIn(null), ...$focusedHints])->sizing()->columns->intrinsic();
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $this->bar($context)->render($frame, $area, $context);
    }

    private function bar(RenderContext $context): StatusBar
    {
        $chord = $context->pendingChord();

        return $chord === null
            ? StatusBar::forHints([...$this->leadingIn($context), ...$context->focusedHints()])
            : StatusBar::forHints([$chord->typed . '…' => '', ...$chord->continuations, 'Esc' => 'cancel']);
    }

    /**
     * @return array<string, string>
     */
    private function leadingIn(?RenderContext $context): array
    {
        if ($this->leadingKeys === null) {
            return $this->leading;
        }

        $keys = $context?->keyboardTakesText() === true ? $this->leadingKeys->withoutText() : $this->leadingKeys;

        return [...$this->leading, ...$keys->hints($this->keyDescriptions)];
    }
}
