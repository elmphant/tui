<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

/**
 * Marks where the keyboard is, so every field says it the same way instead of
 * each inventing a colour of its own.
 */
final readonly class FocusGutter implements Interactive, HoldsTheKeyboard, WidgetContainer, MeasureHeight
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
     * Deliberately not Focusable: a pane cycling its focusable children would stop
     * on the track instead of the field inside it.
     */
    public function isHeld(): bool
    {
        return $this->held ?? ($this->child instanceof Focusable && $this->child->isFocused());
    }

    /**
     * What it wraps, and where that lands — so a host walking the tree for one of
     * its own widgets still finds it behind the track.
     */
    public function holds(): Widget
    {
        return $this->child;
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
    public function childWidgets(Rect $area): iterable
    {
        yield [$this->child, self::contentIn($area)];
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$this->child instanceof Interactive) {
            return new UpdateResult($this);
        }

        $result = $this->child->update($message, self::contentIn($area));

        return new UpdateResult($this->cloneWith(child: $result->model), $result->command);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $held = $this->isHeld();

        for ($row = $area->y; $row < $area->bottom(); ++$row) {
            $frame->putStyled(
                $area->x,
                $row,
                $held ? self::BAR : self::RAIL,
                $held ? $context->theme->focusedGutterStyle() : $context->theme->dim,
            );
        }

        if ($area->width > self::COLUMNS) {
            foreach ($context->childrenOf($this, $area) as [$child, $childArea]) {
                $child->render($frame, $childArea, $context);
            }
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
