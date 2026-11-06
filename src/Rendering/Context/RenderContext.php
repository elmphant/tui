<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Closure;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Terminal\ColorProfile;

final class RenderContext
{
    private const PULSE_DEPTH = 0.3;
    private const PULSE_FRAME_SECONDS = 0.05;
    private const PULSE_SECONDS = 1.2;

    public readonly Theme $theme;
    private readonly FocusPath $focus;

    /**
     * @internal a widget test asks for RenderContext::unfocused() or focused()
     */
    public function __construct(
        Theme $theme,
        public readonly ColorProfile $colorProfile = ColorProfile::TrueColor,
        private readonly FrameClock $clock = new FrameClock(),
        ?FocusPath $focus = null,
        private readonly FloatQueue $floats = new FloatQueue(),
        private readonly LayoutInForce $layout = new LayoutInForce(),
        private readonly Flashes $flashes = new Flashes(),
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
     * The same drawing pass with another theme, for one part of the tree.
     */
    public function withTheme(Theme $theme): self
    {
        return new self(
            theme: $theme,
            colorProfile: $this->colorProfile,
            clock: $this->clock,
            focus: $this->focus,
            floats: $this->floats,
            layout: $this->layout,
            flashes: $this->flashes,
        );
    }

    /**
     * Use the frame's monotonic time in seconds for animation, rather than storing animation state in the model.
     */
    public function now(): float
    {
        return $this->clock->now();
    }

    /**
     * The fill a pulse shows at this frame, fading part of the way to $away and back.
     * Where the terminal cannot blend smoothly the fill holds still and costs no frames.
     */
    public function pulsed(Color $fill, Color $away, float $periodSeconds): Color
    {
        if (!$this->colorProfile->blendsSmoothly()) {
            return $fill;
        }

        $this->requestRepaint(self::PULSE_FRAME_SECONDS);
        $phase = (1.0 - cos(2 * M_PI * $this->now() / $periodSeconds)) / 2;

        return $fill->blend($away, $phase * self::PULSE_DEPTH);
    }

    /**
     * The style a pulsing or flashed row of a list or table is drawn in: a band of the warning colour,
     * or, on a cursor or selection fill, that fill shifting toward it. Null when neither.
     */
    public function highlighted(Style $row, bool $filled, bool $pulsing, string $key): ?Style
    {
        $component = $this->focus->drawnComponent();
        $flash = $component === null ? 0.0 : $this->flashes->strengthOf($component, $key, $this->now());

        if ($flash > 0.0) {
            return $this->flashed($row, $filled, $flash);
        }

        if (!$pulsing) {
            return null;
        }

        $warning = $this->theme->warning->foreground ?? Color::none();

        if (!$filled) {
            $text = $warning->contrastingText();

            return new Style(foreground: $text, background: $this->pulsed($warning, $text->contrastingText(), self::PULSE_SECONDS));
        }

        if (!$this->colorProfile->blendsSmoothly()) {
            return $row->withAttributes($row->attributes | Attr::UNDERLINE);
        }

        $background = $this->pulsed($row->background ?? Color::none(), $warning, self::PULSE_SECONDS);

        return new Style(
            foreground: ($row->foreground ?? Color::none())->readableOn($background),
            background: $background,
            attributes: $row->attributes,
        );
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
    public function isKeyboardLoose(): bool
    {
        return $this->focus->isKeyboardLoose();
    }

    /**
     * @return array<string, string>
     */
    public function focusedHints(): array
    {
        return $this->focus->hints();
    }

    /**
     * The key that opens the key help right now, with its label. Empty when the app has none.
     *
     * @return array<string, string>
     */
    public function keyHelpHint(): array
    {
        return $this->focus->keyHelpHint();
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
    public function focusHere(Closure $render): void
    {
        $this->focus->focusHere($render);
    }

    /**
     * Focus a child when rendering manually, for example a field inside a Panel.
     *
     * @param Closure(): void $render
     */
    public function focusOnChild(int $index, Closure $render): void
    {
        $this->focus->focusOnChild($index, $render);
    }

    /**
     * @param Closure(): void $render
     */
    public function drawUnfocused(Closure $render): void
    {
        $this->focus->drawUnfocused($render);
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
     * The one way a widget draws another, so drawing a child can change in one place.
     */
    public function draw(Drawable $part, Frame $frame, Rect $area): void
    {
        $part->render($frame, $area, $this);
    }

    /**
     * Draw a published child inside withinChild(), or as it is where it has no index.
     */
    public function drawChild(?int $index, Drawable $child, Frame $frame, Rect $area): void
    {
        if ($index === null) {
            $this->draw($child, $frame, $area);

            return;
        }

        $this->withinChild($index, fn () => $this->draw($child, $frame, $area));
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
        $this->floats->float($frame, $popup, $anchor, $this->theme);
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

    private function flashed(Style $row, bool $filled, float $strength): Style
    {
        $warning = $this->theme->warning->foreground ?? Color::none();
        $blends = $this->colorProfile->blendsSmoothly();

        if ($blends) {
            $this->requestRepaint(self::PULSE_FRAME_SECONDS);
        }

        if (!$filled) {
            $faded = $this->theme->canvas ?? $this->theme->selectedStyle->background ?? $warning;
            $background = $blends ? $warning->blend($faded, 1.0 - $strength) : $warning;

            return new Style(foreground: $background->contrastingText(), background: $background);
        }

        if (!$blends) {
            return $row->withAttributes($row->attributes | Attr::UNDERLINE);
        }

        $background = $warning->blend($row->background ?? $warning, 1.0 - $strength);

        return new Style(
            foreground: ($row->foreground ?? Color::none())->readableOn($background),
            background: $background,
            attributes: $row->attributes,
        );
    }
}
