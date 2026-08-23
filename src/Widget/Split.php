<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget;

use Elmphant\Tui\Core\Cloneable;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
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
use Elmphant\Tui\Message\Message;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use InvalidArgumentException;

/**
 * Children side by side, divided where the host says.
 *
 * The division lives in the SplitState handed in, so a host can save it
 * and hand it back. Children are reached through WidgetContainer, so bound
 * components inside them stay visible to layout and routing.
 */
final readonly class Split implements Widget, WidgetContainer, Interactive, KeybindingsAware
{
    use Cloneable;
    use KeybindingsAwareBehavior;

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
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
        $childCount = count($this->children);
        $shareCount = count($this->state->shares);

        if ($childCount !== $shareCount) {
            throw new InvalidArgumentException(sprintf('Split needs as many shares as children, got %d children and %d shares', $childCount, $shareCount));
        }
    }

    public static function defaultKeybindings(): Keybindings
    {
        return Keybindings::empty();
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
        return $this->cloneWith(minimums: array_values($minimums));
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
        if ($message instanceof MouseMsg && $message->button === MouseButton::Left) {
            if ($message->action === MouseAction::Release) {
                return $this->state->dragging === null
                    ? $this->toPanes($message, $area)
                    : new UpdateResult($this->with($this->state->withDragging(null)));
            }

            if ($message->action === MouseAction::Press) {
                $divider = $this->dividerAt($message, $area);

                if ($divider !== null) {
                    return new UpdateResult($this->with($this->state->withDragging($divider)));
                }

                // A press is a fresh grip, so whatever was held has been let go of —
                // the release saying so may have gone to a layer over this one.
                $letGo = $this->state->dragging === null
                    ? $this
                    : $this->with($this->state->withDragging(null));

                return $letGo->toPanes($message, $area);
            }

            if ($message->action === MouseAction::Motion && $this->state->dragging !== null) {
                return new UpdateResult($this->with($this->dragged($message, $area)));
            }
        }

        return $this->toPanes($message, $area);
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
        $index = 0;

        foreach ($context->childrenOf($this, $area) as [$child, $pane]) {
            $child->render($frame, $pane, $context);

            if ($index < $last) {
                $this->renderDivider($frame, $area, $pane, $index, $context);
            }

            ++$index;
        }
    }

    /**
     * @return UpdateResult<static>
     */
    private function toPanes(Message $message, Rect $area): UpdateResult
    {
        $panes = [];
        $rects = [];

        foreach ($this->childWidgets($area) as $index => [$child, $rect]) {
            if ($child instanceof Interactive) {
                $panes[$index] = $child;
                $rects[$index] = $rect;
            }
        }

        if ($panes === []) {
            return new UpdateResult($this);
        }

        $batch = (new ChildDispatcher($this->globalKeybindings))->dispatchList($panes, $rects, $message);

        if ($batch->overrides === []) {
            return new UpdateResult($this, $batch->command);
        }

        return new UpdateResult(
            $this->cloneWith(children: array_values(array_replace($this->children, $batch->overrides))),
            $batch->command,
        );
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
        return $this->cloneWith(state: $state);
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
