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
 * Use one consistent focus marker across fields.
 */
final readonly class FocusGutter implements Interactive, HoldsTheKeyboard, GraftsItsChildren, WidgetContainer, MeasureHeight, KeybindingsAware
{
    use Cloneable;
    public const BAR = '▌';

    public const RAIL = '▏';

    private const COLUMNS = 2;

    private function __construct(private Widget $child, private ?bool $held = null)
    {
    }

    public static function around(Focusable $child): self
    {
        return new self($child);
    }

    /**
     * Let the host supply focus for a block that cannot report it, such as a label with its field.
     */
    public static function beside(Widget $child, bool $held): self
    {
        return new self($child, $held);
    }

    public function measureHeight(int $width): int
    {
        if (!$this->child instanceof MeasureHeight) {
            return $this->child->sizing()->rows->min;
        }

        $inner = $width - self::COLUMNS;

        if ($inner < 1) {
            return 0;
        }

        return $this->child->measureHeight($inner);
    }

    public function sizing(): Sizing
    {
        return $this->child->sizing();
    }

    /**
     * Return only explicit host focus; self-reporting fields read tree focus during rendering.
     */
    public function isHeld(): bool
    {
        return $this->held ?? false;
    }

    public function content(): Widget
    {
        return $this->child;
    }

    public function withChildAt(int $index, Widget $child): static
    {
        return $this->cloneWith(child: $child);
    }

    public function contentArea(Rect $area): Rect
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
     * Always reserve gutter width so tabbing cannot shift fields sideways.
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
