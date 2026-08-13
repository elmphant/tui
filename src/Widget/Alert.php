<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\CharWidth;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\StyleToken;

final readonly class Alert implements Widget
{
    use Cloneable;

    private const PADDING = 1;

    /**
     * How far towards the far end of the fade one pulse travels.
     */
    private const PULSE_DEPTH = 0.3;

    private const PULSE_FRAME_SECONDS = 0.05;

    private function __construct(
        private string $message,
        private StyleToken $severity,
        private ?float $pulsePeriod = null,
    ) {
    }

    public static function warning(string $message): self
    {
        return new self($message, StyleToken::Warning);
    }

    public static function error(string $message): self
    {
        return new self($message, StyleToken::Error);
    }

    public static function success(string $message): self
    {
        return new self($message, StyleToken::Success);
    }

    public static function info(string $message): self
    {
        return new self($message, StyleToken::Info);
    }

    /**
     * Breathes the fill so the row draws the eye without moving the layout.
     */
    public function pulsing(float $periodSeconds = 1.2): self
    {
        return $this->cloneWith(pulsePeriod: max(0.1, $periodSeconds));
    }

    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex()->withBasis(self::PADDING + CharWidth::stringWidth($this->message)),
            Constraint::exact(1),
        );
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $fill = $this->severity->resolve($context->theme)->foreground ?? Color::none();
        $text = $fill->contrastingText();
        $style = new Style(foreground: $text, background: $this->pulsed($fill, $text, $context));
        $frame->fillRect($area->x, $area->y, $area->width, 1, $style->foregroundValue(), $style->backgroundValue(), $style->attributes);

        $available = max(0, $area->width - 2 * self::PADDING);
        $frame->putStyled($area->x + self::PADDING, $area->y, CharWidth::truncateToWidth($this->message, $available), $style);
    }

    private function pulsed(Color $fill, Color $text, RenderContext $context): Color
    {
        if ($this->pulsePeriod === null || !$context->colorProfile->blendsSmoothly()) {
            return $fill;
        }

        $context->requestRepaint(self::PULSE_FRAME_SECONDS);
        $phase = (1.0 - cos(2 * M_PI * $context->now() / $this->pulsePeriod)) / 2;

        // Whatever contrasts with the text is the direction away from it, so the
        // fade only ever widens the gap the message has to be read across.
        return $fill->blend($text->contrastingText(), $phase * self::PULSE_DEPTH);
    }
}
