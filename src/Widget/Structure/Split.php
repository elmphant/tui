<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Structure;

use Elmphant\Tui\Core\Message;
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
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseButton;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Focus\CapturesThePointer;
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\PlacedChildren;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use InvalidArgumentException;

/**
 * Keep divider positions in host-owned SplitState; expose children through WidgetContainer for layout and routing.
 */
final readonly class Split implements Widget, WidgetContainer, Interactive, KeybindingsAware, CapturesThePointer
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
        private ?int $markedPane = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
        $childCount = count($this->children);
        $shareCount = count($this->state->shares);

        if ($childCount !== $shareCount) {
            throw new InvalidArgumentException(sprintf('Split needs as many shares as children, got %d children and %d shares', $childCount, $shareCount));
        }
    }

    public function withMarkedPane(?int $pane): self
    {
        return $this->cloneWith(markedPane: $pane);
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
        if ($this->state->dragging !== null) {
            return true;
        }

        foreach ($this->children as $child) {
            if ($child instanceof CapturesThePointer && $child->capturesThePointer()) {
                return true;
            }
        }

        return false;
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

                // A new press clears any previous drag; an overlaid input layer may have consumed its release.
                $letGo = $this->state->dragging === null
                    ? $this
                    : $this->with($this->state->withDragging(null));

                return $letGo->toPanes($message, $area);
            }

            if ($message->action === MouseAction::Motion && $this->state->dragging !== null) {
                return new UpdateResult($this->with($this->dragged($message, $area)));
            }
        }

        if ($message instanceof KeyMsg || $message instanceof PasteMsg) {
            return $this->toMarkedPane($message, $area);
        }

        return $this->toPanes($message, $area);
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
                $child->render($frame, $pane, $context);

                if ($index < $last) {
                    $this->renderDivider($frame, $area, $pane, $index, $context);
                }

                ++$index;
            }
        } finally {
            $frame->popClip();
        }
    }

    /**
     * Send keys only to the host-marked pane; Split does not cycle focus itself.
     *
     * @return UpdateResult<static>
     */
    private function toMarkedPane(Message $message, Rect $area): UpdateResult
    {
        foreach ($this->placedChildren($area) as $index => [$child, $rect]) {
            if ($index !== $this->markedPane || !$child instanceof Interactive) {
                continue;
            }

            $result = (new ChildDispatcher($this->globalKeybindings))->dispatchOne($child, $message, $rect);

            return new UpdateResult(
                $result->changed
                    ? $this->cloneWith(children: array_values(array_replace($this->children, [$index => $result->child])))
                    : $this,
                $result->command,
            );
        }

        return new UpdateResult($this);
    }

    /**
     * @return UpdateResult<static>
     */
    private function toPanes(Message $message, Rect $area): UpdateResult
    {
        $panes = [];
        $rects = [];

        foreach ($this->placedChildren($area) as $index => [$child, $rect]) {
            if ($child instanceof Interactive) {
                $panes[$index] = $child;
                $rects[$index] = $rect;
            }
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
