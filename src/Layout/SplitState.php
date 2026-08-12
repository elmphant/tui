<?php

declare(strict_types=1);

namespace Elmphant\Tui\Layout;

use Elmphant\Tui\Core\Cloneable;
use InvalidArgumentException;

/**
 * How a Split divides its area, as permille of the axis.
 */
final readonly class SplitState
{
    use Cloneable;

    public const TOTAL = 1000;

    /**
     * @param list<int> $shares   one weight per pane, divided in proportion
     * @param ?int      $dragging the divider being dragged, if any
     */
    public function __construct(
        public array $shares,
        public ?int $dragging = null,
    ) {
        if ($shares === [] || min($shares) < 0 || array_sum($shares) < 1) {
            throw new InvalidArgumentException(sprintf('SplitState shares must be non-negative with at least one above zero, got [%s]', implode(', ', $shares)));
        }
    }

    public function withDragging(?int $divider): self
    {
        return $this->cloneWith(dragging: $divider);
    }

    public static function even(int $panes): self
    {
        $panes = max(1, $panes);
        $share = intdiv(self::TOTAL, $panes);
        $shares = array_fill(0, $panes - 1, $share);
        $shares[] = self::TOTAL - $share * ($panes - 1);

        return new self($shares);
    }

    /**
     * Whatever an application stored, or an even division when it stored nonsense.
     */
    public static function fromArray(mixed $shares, int $panes): self
    {
        if (!is_array($shares) || count($shares) !== $panes) {
            return self::even($panes);
        }

        $total = 0;
        foreach ($shares as $share) {
            if (!is_int($share) || $share < 0) {
                return self::even($panes);
            }
            $total += $share;
        }

        return $total > 0 ? new self(array_values($shares)) : self::even($panes);
    }

    /**
     * @return list<int> what an application stores
     */
    public function toArray(): array
    {
        return $this->shares;
    }

    /**
     * The pair either side of a divider, sharing the space it had in a new ratio.
     */
    public function withPairDividedAt(int $divider, int $first, int $pairTotal): self
    {
        if ($pairTotal <= 0 || !isset($this->shares[$divider], $this->shares[$divider + 1])) {
            return $this;
        }

        $pair = $this->shares[$divider] + $this->shares[$divider + 1];
        $firstShare = (int)round($pair * $first / $pairTotal);

        $shares = $this->shares;
        array_splice($shares, $divider, 2, [$firstShare, $pair - $firstShare]);

        return $this->cloneWith(shares: $shares);
    }
}
