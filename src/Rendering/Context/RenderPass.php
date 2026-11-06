<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Terminal\ColorProfile;

final readonly class RenderPass
{
    private function __construct(
        public RenderContext $context,
        /**
         * @internal
         */
        public FrameClock $clock,
        /**
         * @internal
         */
        public FocusPath $focus,
        /**
         * @internal
         */
        public FloatQueue $floats,
        /**
         * @internal
         */
        public LayoutInForce $layout,
        private ?Rect $area = null,
    ) {
    }

    /**
     * For a test that draws a tree and cares about layout or wording, not about where the keyboard is.
     */
    public static function unfocused(Theme $theme, ColorProfile $colorProfile = ColorProfile::TrueColor): self
    {
        return self::new($theme, $colorProfile, FocusPath::nowhere());
    }

    /**
     * @param Flashes|null $flashes @internal the runtime's lit rows
     */
    public static function new(Theme $theme, ColorProfile $colorProfile = ColorProfile::TrueColor, ?FocusPath $focus = null, ?Flashes $flashes = null): self
    {
        $clock = new FrameClock();
        $focus ??= FocusPath::undecided();
        $floats = new FloatQueue();
        $layout = new LayoutInForce();

        return new self(
            new RenderContext($theme, $colorProfile, $clock, $focus, $floats, $layout, $flashes ?? new Flashes()),
            $clock,
            $focus,
            $floats,
            $layout,
        );
    }

    public function withTheme(Theme $theme): self
    {
        return new self(
            context: $this->context->withTheme($theme),
            clock: $this->clock,
            focus: $this->focus,
            floats: $this->floats,
            layout: $this->layout,
            area: $this->area,
        );
    }

    /**
     * Where the root draws when that is less than the frame: an inline region, with the rows below it left to popups.
     */
    public function withArea(Rect $area): self
    {
        return new self(
            context: $this->context,
            clock: $this->clock,
            focus: $this->focus,
            floats: $this->floats,
            layout: $this->layout,
            area: $area,
        );
    }

    public function areaIn(Frame $frame): Rect
    {
        return $this->area ?? $frame->area();
    }

    /**
     * @internal
     */
    public function begin(float $now): void
    {
        $this->clock->begin($now);
        $this->focus->begin();
        $this->floats->begin();
    }
}
