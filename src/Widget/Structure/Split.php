<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Axis\Allocator;
use Elmphant\Tui\Layout\Axis\AxisSpecification;
use Elmphant\Tui\Layout\Axis\Distribution;
use Elmphant\Tui\Layout\Axis\ItemPolicy;
use Elmphant\Tui\Layout\Axis\Remainder;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Focus\CapturesThePointer;
use Elmphant\Tui\Widget\Focus\GraftsItsChildren;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\PlacedChildren;
use Elmphant\Tui\Widget\Widget;
use InvalidArgumentException;

/**
 * Keep divider positions in host-owned SplitState. The panes are published as stops, so the runtime routes to them.
 */
final readonly class Split implements Widget, GraftsItsChildren, Interactive, CapturesThePointer
{
    use Cloneable;

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

    public function withMinimums(int ...$minimums): self
    {
        return $this->cloneWith(minimums: array_values($minimums));
    }

    public function state(): SplitState
    {
        return $this->state;
    }

    public function capturesThePointer(): bool
    {
        return $this->state->dragging !== null;
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
            // A new press clears any previous drag; an overlaid input layer may have consumed its release.
            return new UpdateResult($this->with($this->state->withDragging($this->dividerAt($message, $area))));
        }

        if ($message->action === MouseAction::Motion && $this->state->dragging !== null) {
            return new UpdateResult($this->with($this->dragged($message, $area)));
        }

        return new UpdateResult($this);
    }

    /**
     * @internal
     */
    public function dividerAt(MouseMsg $message, Rect $area): ?int
    {
        if (!$message->isIn($area)) {
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

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    /**
     * @return iterable<Widget>
     */
    public function children(): iterable
    {
        return $this->children;
    }

    public function withChildAt(int $index, Widget $child): static
    {
        if (!isset($this->children[$index])) {
            return $this;
        }

        return $this->cloneWith(children: array_replace($this->children, [$index => $child]));
    }

    /**
     * @return list<array{0: Widget, 1: Rect}>
     */
    public function placedChildren(Rect $area): iterable
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
        $index = 0;

        $frame->pushClip($area);

        try {
            foreach (PlacedChildren::of($this, $area, $context) as [$child, $pane]) {
                $context->drawChild($index, $child, $frame, $pane);

                if ($index < $last) {
                    $this->renderDivider($frame, $area, $pane, $index, $context);
                }

                ++$index;
            }
        } finally {
            $frame->popClip();
        }
    }

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

        return $this->state->dividePairAt($divider, $first, $pairTotal);
    }

    /**
     * If minimum sizes cause overflow, take space back from panes above their minimum, starting at the front.
     *
     * @param list<int> $sizes
     * @param list<int> $floors
     *
     * @return list<int>
     */
    private static function settled(array $sizes, array $floors, int $available): array
    {
        $excess = array_sum($sizes) - $available;

        foreach ($sizes as $index => $size) {
            $take = min($size - $floors[$index], $excess);
            $sizes[$index] -= $take;
            $excess -= $take;
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
        return $this->cloneWith(state: $state);
    }

    /**
     * @return list<int>
     */
    private function sizes(Rect $area): array
    {
        $panes = count($this->children);
        $axis = $this->direction === Direction::Horizontal ? $area->width : $area->height;
        $available = $axis - self::GAP * min($panes - 1, $axis);
        $minimums = $this->resolvedMinimums($panes);

        $floorsDoNotFit = array_sum($minimums) > $available;
        $weights = $floorsDoNotFit ? $minimums : $this->state->shares;
        $floors = $floorsDoNotFit ? array_fill(0, $panes, 0) : $minimums;

        $policies = [];
        foreach ($weights as $index => $weight) {
            $policies[] = new ItemPolicy(min: $floors[$index], grow: max(1, $weight));
        }

        $sizes = Allocator::resolve($policies, $available, new AxisSpecification(
            remainder: Remainder::StrongestClaim,
            distribution: Distribution::AcrossTheWholeAxis,
        ))->sizes;

        return self::settled($sizes, $floors, $available);
    }
}
