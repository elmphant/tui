<?php

declare(strict_types=1);

namespace Elmphant\Tui\Rendering\Context;

use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Terminal\ColorProfile;

final readonly class RenderPass
{
    private function __construct(
        public RenderContext $context,
        public FrameClock $clock,
        public FocusPath $focus,
        public FloatQueue $floats,
        public LayoutInForce $layout,
    ) {
    }

    public static function new(Theme $theme, ColorProfile $colorProfile = ColorProfile::TrueColor, ?FocusPath $focus = null): self
    {
        $clock = new FrameClock();
        $focus ??= FocusPath::undecided();
        $floats = new FloatQueue();
        $layout = new LayoutInForce();

        return new self(
            new RenderContext($theme, $colorProfile, $clock, $focus, $floats, $layout),
            $clock,
            $focus,
            $floats,
            $layout,
        );
    }

    public function withTheme(Theme $theme): self
    {
        return new self(
            context: new RenderContext($theme, $this->context->colorProfile, $this->clock, $this->focus, $this->floats, $this->layout),
            clock: $this->clock,
            focus: $this->focus,
            floats: $this->floats,
            layout: $this->layout,
        );
    }

    public function begin(float $now): void
    {
        $this->clock->begin($now);
        $this->focus->begin();
        $this->floats->begin();
    }
}
