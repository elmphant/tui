<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;

/**
 * Marks where the keyboard is, so every field says it the same way instead of
 * each inventing a colour of its own.
 */
final readonly class FocusGutter implements Focusable
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

    private function __construct(private Focusable $child)
    {
    }

    public static function around(Focusable $child): self
    {
        return new self($child);
    }

    public function constraint(): Constraint
    {
        return $this->child->constraint();
    }

    public function isFocused(): bool
    {
        return $this->child->isFocused();
    }

    public function withFocus(bool $focused): static
    {
        return $this->cloneWith(child: $this->child->withFocus($focused));
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

        $held = $this->child->isFocused();

        for ($row = $area->y; $row < $area->bottom(); ++$row) {
            $frame->putStyled(
                $area->x,
                $row,
                $held ? self::BAR : self::RAIL,
                $held ? $context->theme->focusedGutterStyle : $context->theme->dim,
            );
        }

        if ($area->width > self::COLUMNS) {
            $this->child->render($frame, self::contentIn($area), $context);
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
