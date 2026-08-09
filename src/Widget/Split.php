<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use InvalidArgumentException;

/**
 * Children side by side, divided where the application says.
 *
 * The division lives in the SplitState handed in, so an application can save it
 * and hand it back. Children are reached through WidgetContainer, so bound
 * components inside them stay visible to layout and routing.
 */
final readonly class Split implements Widget, WidgetContainer, Interactive
{
    private const GAP = 1;

    /**
     * @param list<Widget> $children
     * @param list<int>    $minimums
     */
    private function __construct(
        private SplitState $state,
        private array $children,
        private Direction $direction = Direction::Horizontal,
        private array $minimums = [],
    ) {
        $childCount = count($this->children);
        $shareCount = count($this->state->shares);

        if ($childCount !== $shareCount) {
            throw new InvalidArgumentException(sprintf('Split needs as many shares as children, got %d children and %d shares', $childCount, $shareCount));
        }
    }

    public static function row(SplitState $state, Widget ...$children): self
    {
        return new self($state, array_values($children), Direction::Horizontal);
    }

    public static function column(SplitState $state, Widget ...$children): self
    {
        return new self($state, array_values($children), Direction::Vertical);
    }

    /**
     * How far a drag may squeeze each pane.
     */
    public function withMinimums(int ...$minimums): self
    {
        return new self($this->state, $this->children, $this->direction, array_values($minimums));
    }

    public function state(): SplitState
    {
        return $this->state;
    }

    /**
     * @return UpdateResult<static>
     */
    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$message instanceof MouseMsg || $message->button !== MouseButton::Left) {
            return new UpdateResult($this);
        }

        if ($message->action === MouseAction::Release) {
            return new UpdateResult($this->state->dragging === null ? $this : $this->with($this->state->withDragging(null)));
        }

        if ($message->action === MouseAction::Press) {
            $divider = $this->dividerAt($message, $area);

            return new UpdateResult($divider === null ? $this : $this->with($this->state->withDragging($divider)));
        }

        return $message->action === MouseAction::Motion && $this->state->dragging !== null
            ? new UpdateResult($this->with($this->dragged($message, $area)))
            : new UpdateResult($this);
    }

    /**
     * The divider under the pointer, or null.
     */
    public function dividerAt(MouseMsg $message, Rect $area): ?int
    {
        if (!$message->inRect($area)) {
            return null;
        }

        $offset = 0;
        $sizes = $this->sizes($area);
        $pointer = $this->along($message);

        for ($divider = 0; $divider < count($sizes) - 1; ++$divider) {
            $offset += $sizes[$divider];
            $edge = $this->axisStart($area) + $offset;

            if ($pointer >= $edge && $pointer < $edge + self::GAP) {
                return $divider;
            }

            $offset += self::GAP;
        }

        return null;
    }

    public function constraint(): Constraint
    {
        return Constraint::flex();
    }

    /**
     * @return iterable<Widget>
     */
    public function children(): iterable
    {
        return $this->children;
    }

    /**
     * @return list<array{0: Widget, 1: Rect}>
     */
    public function childWidgets(Rect $area): iterable
    {
        $pairs = [];
        $offset = 0;

        foreach ($this->sizes($area) as $index => $size) {
            $pairs[] = [$this->children[$index], $this->paneRect($area, $offset, $size)];
            $offset += $size + self::GAP;
        }

        return $pairs;
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $last = count($this->children) - 1;

        foreach ($this->childWidgets($area) as $index => [$child, $pane]) {
            $child->render($frame, $pane, $context);

            if ($index < $last) {
                $this->renderDivider($frame, $area, $pane, $index, $context);
            }
        }
    }

    /**
     * A drag moves one boundary; every pane outside the pair keeps its share.
     */
    private function dragged(MouseMsg $message, Rect $area): SplitState
    {
        $divider = $this->state->dragging;

        if ($divider === null) {
            return $this->state;
        }

        $sizes = $this->sizes($area);
        $paneStart = $this->axisStart($area) + array_sum(array_slice($sizes, 0, $divider)) + $divider * self::GAP;
        $pairTotal = $sizes[$divider] + $sizes[$divider + 1];
        $minimums = $this->resolvedMinimums(count($sizes));
        $first = max(
            $minimums[$divider],
            min($pairTotal - $minimums[$divider + 1], $this->along($message) - $paneStart),
        );

        return $this->state->withPairDividedAt($divider, $first, $pairTotal);
    }

    /**
     * Sizes trimmed or padded to the space there is, taking from and giving to
     * whichever panes have room above their floor.
     *
     * @param list<int> $sizes
     * @param list<int> $floors
     *
     * @return list<int>
     */
    private static function settled(array $sizes, array $floors, int $available): array
    {
        $excess = array_sum($sizes) - $available;

        while ($excess > 0) {
            $room = 0;

            foreach ($sizes as $index => $size) {
                $slack = $size - $floors[$index];

                if ($slack > 0 && $excess > 0) {
                    $take = min($slack, $excess);
                    $sizes[$index] -= $take;
                    $excess -= $take;
                    $room += $slack;
                }
            }

            if ($room === 0) {
                break;
            }
        }

        if ($excess < 0 && $sizes !== []) {
            $sizes[count($sizes) - 1] -= $excess;
        }

        return $sizes;
    }

    /**
     * @return list<int>
     */
    private function resolvedMinimums(int $panes): array
    {
        $minimums = [];

        for ($index = 0; $index < $panes; ++$index) {
            $minimums[] = max(1, $this->minimums[$index] ?? 1);
        }

        return $minimums;
    }

    /**
     * The line between two panes, in the accent colour while it is dragged.
     */
    private function renderDivider(Frame $frame, Rect $area, Rect $pane, int $divider, RenderContext $context): void
    {
        $style = $this->state->dragging === $divider ? $context->theme->accent : $context->theme->borderStyle;
        $horizontal = $this->direction === Direction::Horizontal;
        $character = $horizontal ? '│' : '─';
        $length = $horizontal ? $area->height : $area->width;

        for ($cell = 0; $cell < $length; ++$cell) {
            $horizontal
                ? $frame->putStyled($pane->x + $pane->width, $area->y + $cell, $character, $style)
                : $frame->putStyled($area->x + $cell, $pane->y + $pane->height, $character, $style);
        }
    }

    private function along(MouseMsg $message): int
    {
        return $this->direction === Direction::Horizontal ? $message->x : $message->y;
    }

    private function axisStart(Rect $area): int
    {
        return $this->direction === Direction::Horizontal ? $area->x : $area->y;
    }

    private function paneRect(Rect $area, int $offset, int $size): Rect
    {
        return $this->direction === Direction::Horizontal
            ? new Rect($area->x + $offset, $area->y, $size, $area->height)
            : new Rect($area->x, $area->y + $offset, $area->width, $size);
    }

    private function with(SplitState $state): self
    {
        return new self($state, $this->children, $this->direction, $this->minimums);
    }

    /**
     * @return list<int>
     */
    private function sizes(Rect $area): array
    {
        $panes = count($this->children);
        $axis = $this->direction === Direction::Horizontal ? $area->width : $area->height;
        $available = $axis - self::GAP * min($panes - 1, max(0, $axis));

        if ($available <= 0) {
            return array_fill(0, $panes, 0);
        }

        $minimums = $this->resolvedMinimums($panes);

        $weights = array_sum($minimums) > $available ? $minimums : $this->state->shares;
        $total = array_sum($weights);
        $sizes = [];

        foreach ($weights as $index => $weight) {
            $wanted = (int)round($available * $weight / $total);
            $sizes[] = $weights === $minimums ? $wanted : max($minimums[$index], $wanted);
        }

        // Rounding each share independently misses the axis by a column either
        // way, so the panes with room absorb the difference.
        return self::settled($sizes, $weights === $minimums ? array_fill(0, $panes, 0) : $minimums, $available);
    }
}
