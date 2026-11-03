<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Invariant;

use Elmphant\Tui\Layout\Constraint;
use LogicException;

final class ConstraintSweep
{
    private const SEED = 20260808;

    private const SHAPES = 12;

    private const EXTENTS = [0, 1, 3, 7, 20, 80];

    /**
     * @return iterable<string, array{list<Constraint>, int}>
     */
    public static function cases(): iterable
    {
        foreach (self::shapes() as $name => $constraints) {
            foreach (self::EXTENTS as $extent) {
                yield $name . ' in ' . $extent => [$constraints, $extent];
            }
        }
    }

    /**
     * @return iterable<string, array{list<Constraint>, int}>
     */
    public static function casesWhereEveryFloorFits(): iterable
    {
        foreach (self::cases() as $name => [$constraints, $extent]) {
            if (self::floorsOf($constraints) <= $extent) {
                yield $name => [$constraints, $extent];
            }
        }
    }

    /**
     * @return iterable<string, array{list<Constraint>, int}>
     */
    public static function casesWithAnUncappedWeightedChild(): iterable
    {
        foreach (self::casesWhereEveryFloorFits() as $name => [$constraints, $extent]) {
            foreach ($constraints as $constraint) {
                if ($constraint->flex > 0 && $constraint->max === PHP_INT_MAX) {
                    yield $name => [$constraints, $extent];
                    break;
                }
            }
        }
    }

    /**
     * @param list<Constraint> $constraints
     */
    public static function floorsOf(array $constraints): int
    {
        $floors = 0;
        foreach ($constraints as $constraint) {
            $floors += $constraint->min;
        }

        return $floors;
    }

    /**
     * @return array<string, Constraint>
     */
    private static function catalogue(): array
    {
        return [
            'exact0' => Constraint::exact(0),
            'exact1' => Constraint::exact(1),
            'exact3' => Constraint::exact(3),
            'exact7' => Constraint::exact(7),
            'min0' => Constraint::min(0),
            'min2' => Constraint::min(2),
            'min5' => Constraint::min(5),
            'flex1' => Constraint::flex(),
            'flex2' => Constraint::flex(2),
            'flex3' => Constraint::flex(3),
            'max4' => Constraint::max(4),
            'max12' => Constraint::max(12),
            'floor2grow1' => Constraint::min(2)->withFlex(),
            'floor4grow3' => Constraint::min(4)->withFlex(3),
            'between2and6' => Constraint::min(2)->withMax(6)->withFlex(),
        ];
    }

    /**
     * @return array<string, list<Constraint>>
     */
    private static function shapes(): array
    {
        $catalogue = self::catalogue();
        $names = array_keys($catalogue);
        $state = self::SEED;
        $shapes = [];

        for ($shape = 0; $shape < self::SHAPES; ++$shape) {
            $state = self::next($state);
            $length = 2 + self::roll($state, 4);
            $picked = [];

            for ($child = 0; $child < $length; ++$child) {
                $state = self::next($state);
                $picked[] = $names[self::roll($state, count($names))];
            }

            $shapes[implode('+', $picked)] = array_map(
                static fn (string $name): Constraint => $catalogue[$name],
                $picked,
            );
        }

        if (count($shapes) !== self::SHAPES) {
            throw new LogicException(sprintf('Two draws produced the same shape, so the sweep shrank to %d', count($shapes)));
        }

        return $shapes;
    }

    private static function next(int $state): int
    {
        return ($state * 1103515245 + 12345) % 2147483648;
    }

    /**
     * Discard the generator's repeating low bits to get a useful spread from its high bits.
     */
    private static function roll(int $state, int $range): int
    {
        return intdiv($state, 65536) % $range;
    }
}
