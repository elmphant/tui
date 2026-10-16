<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Focus\GraftsItsChildren;
use Elmphant\Tui\Widget\Focus\HoldsTheKeyboard;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PlacedChildren;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

/**
 * Marks where the keyboard is, so every field says it the same way instead of
 * each inventing a colour of its own.
 */
final readonly class FocusGutter implements Interactive, HoldsTheKeyboard, GraftsItsChildren, WidgetContainer, MeasureHeight, KeybindingsAware
{
    use Cloneable;
    /**
     * Held: wide enough to catch the eye at a glance.
     */
    public const BAR = '▌';

    /**
     * Idle: a hairline, so the reserved column reads as a track rather than a gap.
     */
    public const RAIL = '▏';

    private const COLUMNS = 2;

    private function __construct(private Widget $child, private ?bool $held = null)
    {
    }

    /**
     * For a field that answers for itself.
     */
    public static function around(Focusable $child): self
    {
        return new self($child);
    }

    /**
     * For a block that cannot — a label and its field together, or the row a
     * container's cursor rests on. Whoever composed it knows, so it says.
     */
    public static function beside(Widget $child, bool $held): self
    {
        return new self($child, $held);
    }

    /**
     * As tall as what it runs beside; the track itself costs columns, not rows.
     */
    public function measureHeight(int $width): int
    {
        $inner = $width - self::COLUMNS;

        if ($inner < 1 || !$this->child instanceof MeasureHeight) {
            return 0;
        }

        return $this->child->measureHeight($inner);
    }

    public function sizing(): Sizing
    {
        return $this->child->sizing();
    }

    /**
     * Only what a host said. A track around a field that answers for itself asks
     * the tree while drawing instead.
     */
    public function isHeld(): bool
    {
        return $this->held ?? false;
    }

    /**
     * What it wraps, and where that lands — so a host walking the tree for one of
     * its own widgets still finds it behind the track.
     */
    public function holds(): Widget
    {
        return $this->child;
    }

    public function withChildAt(int $index, Widget $child): static
    {
        return $this->cloneWith(child: $child);
    }

    public function contentRectIn(Rect $area): Rect
    {
        return self::contentIn($area);
    }

    /**
     * @return iterable<Widget>
     */
    public function children(): iterable
    {
        yield $this->child;
    }

    /**
     * @return iterable<array{Widget, Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        yield [$this->child, self::contentIn($area)];
    }

    public static function defaultKeybindings(): Keybindings
    {
        return Keybindings::empty();
    }

    /**
     * The track binds nothing of its own; what a reader can press is the field's.
     */
    public function effectiveKeybindings(): Keybindings
    {
        return $this->child instanceof KeybindingsAware
            ? $this->child->effectiveKeybindings()
            : self::defaultKeybindings();
    }

    public function withKeybindings(?Keybindings $keybindings): static
    {
        return $this->child instanceof KeybindingsAware
            ? $this->cloneWith(child: $this->child->withKeybindings($keybindings))
            : $this;
    }

    public function withGlobalKeybindings(?Keybindings $keybindings): static
    {
        return $this->child instanceof KeybindingsAware
            ? $this->cloneWith(child: $this->child->withGlobalKeybindings($keybindings))
            : $this;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $held = $this->held ?? $context->hasFocus();

        for ($row = $area->y; $row < $area->bottom(); ++$row) {
            $frame->putStyled(
                $area->x,
                $row,
                $held ? self::BAR : self::RAIL,
                $held ? $context->theme->focusedBorderStyle : $context->theme->dim,
            );
        }

        if ($area->width <= self::COLUMNS) {
            return;
        }

        foreach (PlacedChildren::of($this, $area, $context) as [$child, $childArea]) {
            $draw = static function () use ($child, $frame, $childArea, $context): void {
                $child->render($frame, $childArea, $context);
            };

            $this->held === null ? $context->withinChild(0, $draw) : $draw();
        }
    }

    /**
     * Reserved whether or not the child holds the focus: a track that appeared
     * only on focus would shift every field sideways as the reader tabs.
     */
    private static function contentIn(Rect $area): Rect
    {
        return new Rect(
            $area->x + self::COLUMNS,
            $area->y,
            max(0, $area->width - self::COLUMNS),
            $area->height,
        );
    }
}
