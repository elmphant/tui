<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Feedback;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\TickMsg;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Interactive;

final readonly class ProgressBar implements Interactive
{
    use Cloneable;

    private const BLOCKS = [' ', '▏', '▎', '▍', '▌', '▋', '▊', '▉', '█'];

    private float $progress;

    public function __construct(
        float $progress = 0.0,
        private ?float $display = null,
        private ?Style $fillStyleOverride = null,
        private ?Style $emptyStyleOverride = null,
        private string $label = '',
        private bool $showPercent = true,
        private bool $clickable = true,
        private float $springFactor = 0.15,
    ) {
        $this->progress = max(0.0, min(1.0, $progress));
    }

    public static function new(float $progress = 0.0): self
    {
        return new self($progress);
    }

    public function withProgress(float $progress): self
    {
        return $this->cloneWith(progress: max(0.0, min(1.0, $progress)));
    }

    public function withLabel(string $label): self
    {
        return $this->cloneWith(label: $label);
    }

    public function withFillStyle(Style $style): self
    {
        return $this->cloneWith(fillStyleOverride: $style);
    }

    public function withEmptyStyle(Style $style): self
    {
        return $this->cloneWith(emptyStyleOverride: $style);
    }

    public function withShowPercent(bool $showPercent): self
    {
        return $this->cloneWith(showPercent: $showPercent);
    }

    public function withClickable(bool $clickable): self
    {
        return $this->cloneWith(clickable: $clickable);
    }

    /**
     * @internal seeds the animation state a tick would otherwise create; exposed for
     *           deterministic composition and tests
     */
    public function withDisplay(float $display): self
    {
        return $this->cloneWith(display: $display);
    }

    public function withSpringFactor(float $springFactor): self
    {
        return $this->cloneWith(springFactor: $springFactor);
    }

    public function progress(): float
    {
        return $this->progress;
    }

    /**
     * The fraction actually drawn. Until a tick has animated this bar there is
     * no animation state, and an unbound bar has no tick loop at all — so it
     * falls back to the real progress rather than sitting at zero.
     */
    public function displayValue(): float
    {
        return $this->display ?? $this->progress;
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return match (true) {
            $message instanceof TickMsg => $this->handleTick(),
            $message instanceof MouseMsg && $this->clickable && $message->isClick() && $message->inRect($area) => $this->handleClick($message, $area),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width <= 0 || $area->height < 1) {
            return;
        }

        $barArea = $this->barRect($area);
        $barWidth = $barArea->width;
        $rowY = $barArea->y;

        if ($barWidth <= 0) {
            return;
        }

        $display = $this->displayValue();
        $theme = $context->theme;
        $fillStyle = $this->fillStyleOverride ?? $theme->fillStyle;
        $emptyStyle = $this->emptyStyleOverride ?? $theme->emptyStyle;
        $baseStyle = $theme->base;

        $fillColor = $fillStyle->foregroundValue();
        $emptyColor = $emptyStyle->foregroundValue();
        $labelColor = $baseStyle->foregroundValue();
        $baseBackground = $baseStyle->backgroundValue();

        if ($this->label !== '' && $area->width > $barWidth) {
            $frame->putString($area->x, $rowY, $this->label, $labelColor, $baseBackground);
        }

        $fillFraction = $display * $barWidth;
        $fullCells = (int)$fillFraction;
        $remainder = $fillFraction - $fullCells;
        $fractionIndex = (int)round($remainder * 8);

        if ($fullCells > 0) {
            $frame->fillRect($barArea->x, $rowY, $fullCells, 1, $fillColor, $baseBackground, 0, '█');
        }
        $partialColumn = $fullCells;
        if ($fractionIndex > 0 && $partialColumn < $barWidth) {
            $frame->putChar($barArea->x + $partialColumn, $rowY, self::BLOCKS[$fractionIndex], $fillColor, $baseBackground);
            ++$partialColumn;
        }
        if ($partialColumn < $barWidth) {
            $frame->fillRect($barArea->x + $partialColumn, $rowY, $barWidth - $partialColumn, 1, $emptyColor, $baseBackground, 0, '─');
        }

        if ($this->showPercent) {
            $percentText = sprintf('%3d%%', (int)round($display * 100));
            $percentX = $barArea->x + $barWidth - strlen($percentText);
            if ($percentX >= $barArea->x) {
                $percentForeground = $display > 0.5
                    ? Color::fromValue($fillColor)->contrastingText()->value
                    : $labelColor;
                $percentBackground = $display > 0.5 ? $fillColor : $baseBackground;
                $frame->putString($percentX, $rowY, $percentText, $percentForeground, $percentBackground, Attr::BOLD);
            }
        }
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleTick(): UpdateResult
    {
        // Springing from what was last drawn, not from zero, is what keeps the
        // first tick after a change from jerking the bar backwards.
        $display = $this->displayValue();
        $difference = $this->progress - $display;
        if (abs($difference) < 0.001) {
            return new UpdateResult($this->cloneWith(display: $this->progress));
        }

        return new UpdateResult($this->cloneWith(display: $display + $difference * $this->springFactor));
    }

    /**
     * @return UpdateResult<static>
     */
    private function handleClick(MouseMsg $message, Rect $area): UpdateResult
    {
        $barArea = $this->barRect($area);
        if ($message->inRect($barArea)) {
            $localX = $message->x - $barArea->x;
            $fraction = $barArea->width > 0 ? $localX / $barArea->width : 0.0;

            return new UpdateResult($this->withProgress(max(0.0, min(1.0, $fraction))));
        }

        return new UpdateResult($this);
    }

    private function barRect(Rect $area): Rect
    {
        if ($this->label === '') {
            return new Rect($area->x, $area->y, $area->width, 1);
        }
        $labelWidth = CharWidth::stringWidth($this->label) + 1;

        return new Rect($area->x + $labelWidth, $area->y, max(0, $area->width - $labelWidth), 1);
    }
}
