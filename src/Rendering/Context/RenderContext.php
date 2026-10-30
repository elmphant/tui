<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Closure;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Terminal\ColorProfile;

final class RenderContext
{
    public readonly Theme $theme;
    private readonly FocusPath $focus;

    public function __construct(
        Theme $theme,
        public readonly ColorProfile $colorProfile = ColorProfile::TrueColor,
        private readonly FrameClock $clock = new FrameClock(),
        ?FocusPath $focus = null,
        private readonly FloatQueue $floats = new FloatQueue(),
        private readonly LayoutInForce $layout = new LayoutInForce(),
    ) {
        $this->theme = $theme->shownIn($colorProfile);
        $this->focus = $focus ?? FocusPath::undecided();
    }

    /**
     * Use explicitly empty focus for tree tests; the default constructor's undecided focus is only valid for standalone widgets.
     */
    public static function unfocused(Theme $theme, ColorProfile $colorProfile = ColorProfile::TrueColor): self
    {
        return new self($theme, $colorProfile, focus: FocusPath::nowhere());
    }

    /**
     * For one widget on its own; inside a tree Component::render() opens the scope.
     */
    public static function focused(Theme $theme, ColorProfile $colorProfile = ColorProfile::TrueColor): self
    {
        return new self($theme, $colorProfile, focus: FocusPath::here());
    }

    /**
     * Use the frame's monotonic time in seconds for animation, rather than storing animation state in the model.
     */
    public function now(): float
    {
        return $this->clock->now();
    }

    public function requestRepaint(float $delay): void
    {
        $this->clock->requestRepaint($delay);
    }

    public function focusedComponent(): ?ComponentId
    {
        return $this->focus->focusedComponent();
    }

    /**
     * When the whole subtree has focus, show its first focusable child as focused.
     */
    public function keyboardIsLoose(): bool
    {
        return $this->focus->keyboardIsLoose();
    }

    /**
     * @return array<string, string>
     */
    public function focusedHints(): array
    {
        return $this->focus->hints();
    }

    /**
     * Whether the app treats keys as text here; null means no app supplied this information.
     */
    public function keyboardTakesText(): ?bool
    {
        return $this->focus->takesText();
    }

    public function pendingChord(): ?PendingChord
    {
        return $this->focus->chord();
    }

    /**
     * Read focus from the tree while rendering so the display agrees with input routing.
     */
    public function hasFocus(): bool
    {
        return $this->focus->hasFocus();
    }

    /**
     * Draw a part as the one the keyboard reaches. Production opens this through
     * Component; a test that renders one widget on its own says so directly.
     *
     * @param Closure(): void $render
     */
    public function withFocusHere(Closure $render): void
    {
        $this->focus->withFocusHere($render);
    }

    /**
     * Focus a child when rendering manually, for example a field inside a Panel.
     *
     * @param Closure(): void $render
     */
    public function withFocusOnChild(int $index, Closure $render): void
    {
        $this->focus->withFocusOnChild($index, $render);
    }

    /**
     * @param Closure(): void $render
     */
    public function withoutFocus(Closure $render): void
    {
        $this->focus->withoutFocus($render);
    }

    /**
     * @param Closure(): void $render
     */
    public function withinComponent(ComponentId $id, Closure $render): void
    {
        $this->focus->withinComponent($id, $render);
    }

    /**
     * @param Closure(): void $render
     */
    public function withinMount(ComponentId $id, Closure $render): void
    {
        $this->focus->withinMount($id, $render);
    }

    /**
     * Use the remaining focus path to draw at most one child as focused.
     *
     * @param Closure(): void $render
     */
    public function withinChild(int $index, Closure $render): void
    {
        $this->focus->withinChild($index, $render);
    }

    /**
     * Which child the keyboard is in, or null where it is on this container
     * itself or somewhere else entirely.
     */
    public function focusedChildIndex(): ?int
    {
        return $this->focus->focusedChildIndex();
    }

    public function float(Frame $frame, Floating $popup, Rect $anchor): void
    {
        $this->floats->float($frame, $popup, $anchor);
    }

    /**
     * Reuse resolved placements without laying out the container again, allowing off-screen children to stay unmeasured.
     *
     * @return list<array{object, Rect}>|null
     */
    public function plannedChildrenOf(object $container, Rect $area): ?array
    {
        return $this->layout->childrenOf($container, $area);
    }
}
