<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Popup;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Focus\GraftsItsChildren;
use Elmphant\Tui\Widget\PlacedChildren;
use Elmphant\Tui\Widget\Widget;

final readonly class Overlay implements Widget, GraftsItsChildren
{
    private function __construct(
        private Widget $base,
        private Widget $overlay,
    ) {
    }

    public static function new(Widget $base, Widget $overlay): self
    {
        return new self($base, $overlay);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    /**
     * @return list<Widget>
     */
    public function children(): array
    {
        return [$this->base, $this->overlay];
    }

    /**
     * @return list<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): array
    {
        return [
            [$this->base, $area],
            [$this->overlay, $area],
        ];
    }

    public function withChildAt(int $index, Widget $child): static
    {
        return match ($index) {
            0 => new self(base: $child, overlay: $this->overlay),
            1 => new self(base: $this->base, overlay: $child),
            default => $this,
        };
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        foreach (PlacedChildren::of($this, $area, $context) as $index => [$child, $childArea]) {
            $context->drawChild(\is_int($index) ? $index : null, $child, $frame, $childArea);
        }
    }
}
