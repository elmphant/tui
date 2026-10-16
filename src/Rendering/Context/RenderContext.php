<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Closure;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Terminal\ColorProfile;

/**
 * What a widget may ask while it draws.
 */
final class RenderContext
{
    private readonly FocusPath $focus;

    public function __construct(
        public readonly Theme $theme,
        public readonly ColorProfile $colorProfile = ColorProfile::TrueColor,
        private readonly FrameClock $clock = new FrameClock(),
        ?FocusPath $focus = null,
        private readonly FloatQueue $floats = new FloatQueue(),
        private readonly LayoutInForce $layout = new LayoutInForce(),
    ) {
        $this->focus = $focus ?? FocusPath::undecided();
    }

    /**
     * A context to draw a tree with nobody at the keyboard — what a test of layout
     * or wording wants. The plain constructor leaves the question unanswered, which
     * is fine for one widget on its own but refused for a tree of components.
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
     * Monotonic time of the frame being rendered, in seconds.
     *
     * Widgets derive animation phase from this instead of carrying
     * message-driven animation state.
     */
    public function now(): float
    {
        return $this->clock->now();
    }

    /**
     * Ask the runtime to render another frame within $delay seconds.
     */
    public function requestRepaint(float $delay): void
    {
        $this->clock->requestRepaint($delay);
    }

    public function focusedComponent(): ?ComponentId
    {
        return $this->focus->focusedComponent();
    }

    /**
     * The keyboard is on this subtree as a whole, nothing within it having a name
     * of its own. A container drawing under one shows its first focusable, or the
     * reader is left with nothing lit.
     */
    public function keyboardIsLoose(): bool
    {
        return $this->focus->keyboardIsLoose();
    }

    /**
     * The keys whatever holds the keyboard answers, readable at any point in the
     * tree rather than only after the focused widget has drawn.
     *
     * @return array<string, string>
     */
    public function focusedHints(): array
    {
        return $this->focus->hints();
    }

    /**
     * Whether the keyboard is in the widget being rendered. A widget asks rather
     * than remembers, so it cannot disagree with where the keys are routed.
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
     * Draw a part that must not show the focus its container holds — the list
     * beside the field, the pane that is not the active one.
     *
     * @param Closure(): void $render
     */
    public function withoutFocus(Closure $render): void
    {
        $this->focus->withoutFocus($render);
    }

    /**
     * Opened by Component around the subtree it binds.
     *
     * @param Closure(): void $render
     */
    public function withinComponent(ComponentId $id, Closure $render): void
    {
        $this->focus->withinComponent($id, $render);
    }

    /**
     * Draw one child of a container that publishes its children as stops of their
     * own. The keyboard reaches at most one of them, and what is left of the path
     * says which.
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
     * Positioned children only when geometry was already resolved; never invokes
     * the container's own layout, so a large viewport-aware container can skip
     * positioning off-screen children during a render-only pass.
     *
     * @return list<array{object, Rect}>|null
     */
    public function plannedChildrenOf(object $container, Rect $area): ?array
    {
        return $this->layout->childrenOf($container, $area);
    }
}
