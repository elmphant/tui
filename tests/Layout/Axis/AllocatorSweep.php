<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout\Axis;

use Elmphant\Tui\Layout\Axis\AxisSpecification;
use Elmphant\Tui\Layout\Axis\ItemPolicy;
use Elmphant\Tui\Layout\Axis\Justification;
use Elmphant\Tui\Layout\Axis\Overflow;

final class AllocatorSweep
{
    private const EXTENTS = [0, 1, 2, 5, 9, 20];

    private const GAPS = [0, 1, 2];

    /**
     * @return iterable<string, array{list<ItemPolicy>, AxisSpecification, int}>
     */
    public static function cases(): iterable
    {
        foreach (self::policyLists() as $listName => $policies) {
            foreach (self::specifications() as $specificationName => $specification) {
                foreach (self::EXTENTS as $extent) {
                    yield $listName . ', ' . $specificationName . ', extent ' . $extent => [$policies, $specification, $extent];
                }
            }
        }
    }

    /**
     * @return iterable<string, array{list<ItemPolicy>, AxisSpecification, int}>
     */
    public static function casesWhereEveryBasisFits(): iterable
    {
        foreach (self::cases() as $name => [$policies, $specification, $extent]) {
            if (self::basesOf($policies) <= self::cellsForSlots($policies, $specification, $extent)) {
                yield $name => [$policies, $specification, $extent];
            }
        }
    }

    /**
     * @return iterable<string, array{list<ItemPolicy>, AxisSpecification, int}>
     */
    public static function casesWhereEveryFloorFits(): iterable
    {
        foreach (self::cases() as $name => [$policies, $specification, $extent]) {
            if (self::floorsOf($policies) <= self::cellsForSlots($policies, $specification, $extent)) {
                yield $name => [$policies, $specification, $extent];
            }
        }
    }

    /**
     * @return iterable<string, array{list<ItemPolicy>, AxisSpecification, int}>
     */
    public static function casesWhereEveryFloorFitsAndEverySlotYields(): iterable
    {
        foreach (self::casesWhereEveryFloorFits() as $name => [$policies, $specification, $extent]) {
            $everySlotYields = true;
            foreach ($policies as $policy) {
                $everySlotYields = $everySlotYields && $policy->shrink > 0;
            }

            if ($everySlotYields) {
                yield $name => [$policies, $specification, $extent];
            }
        }
    }

    /**
     * @return iterable<string, array{list<ItemPolicy>, AxisSpecification, int}>
     */
    public static function casesUnder(Overflow $overflow): iterable
    {
        foreach (self::cases() as $name => [$policies, $specification, $extent]) {
            if ($specification->overflow === $overflow) {
                yield $name => [$policies, $specification, $extent];
            }
        }
    }

    /**
     * @return iterable<string, array{list<ItemPolicy>, AxisSpecification, int}>
     */
    /**
     * A handful of unrelated axes, to be resolved between two runs of the axis
     * under test.
     *
     * @return list<array{list<ItemPolicy>, AxisSpecification, int}>
     */
    public static function intervening(): array
    {
        $catalogue = self::catalogue();

        return [
            [[$catalogue['wideAndYielding'], $catalogue['zeroWidth']], new AxisSpecification(3, Justification::End, Overflow::SqueezeToFloor), 4],
            [[$catalogue['growFromNothing'], $catalogue['fixedFour'], $catalogue['unweightedSix']], new AxisSpecification(1, Justification::SpaceEvenly, Overflow::ClipTail), 17],
            [[$catalogue['eagerFloorTwo']], new AxisSpecification(0, Justification::Center, Overflow::Retain), 0],
        ];
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    public static function cellsForSlots(array $policies, AxisSpecification $specification, int $extent): int
    {
        return max(0, $extent - self::gapCells($policies, $specification, $extent));
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    public static function gapCells(array $policies, AxisSpecification $specification, int $extent = PHP_INT_MAX): int
    {
        $openings = max(0, count($policies) - 1);

        if ($openings === 0) {
            return 0;
        }

        return $openings * min($specification->gap, intdiv(max(0, $extent), $openings));
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    public static function basesOf(array $policies): int
    {
        $bases = 0;
        foreach ($policies as $policy) {
            $bases += $policy->basis;
        }

        return $bases;
    }

    /**
     * @param list<ItemPolicy> $policies
     */
    public static function floorsOf(array $policies): int
    {
        $floors = 0;
        foreach ($policies as $policy) {
            $floors += $policy->min;
        }

        return $floors;
    }

    /**
     * @return array<string, AxisSpecification>
     */
    private static function specifications(): array
    {
        $specifications = [];

        foreach (Justification::cases() as $justification) {
            foreach (Overflow::cases() as $overflow) {
                foreach (self::GAPS as $gap) {
                    $name = sprintf('%s %s gap %d', $justification->name, $overflow->name, $gap);
                    $specifications[$name] = new AxisSpecification($gap, $justification, $overflow);
                }
            }
        }

        return $specifications;
    }

    /**
     * @return array<string, list<ItemPolicy>>
     */
    private static function policyLists(): array
    {
        $catalogue = self::catalogue();

        $lists = [
            ['growFromNothing', 'growFromNothing'],
            ['fixedFour', 'growFromNothing'],
            ['cappedAtThree', 'growFromNothing', 'fixedFour'],
            ['wideAndYielding', 'wideAndYielding', 'wideAndYielding'],
            ['unweightedSix', 'unweightedSix'],
            ['flooredThree', 'cappedAtThree', 'growFromNothing'],
            ['zeroWidth', 'wideAndYielding', 'wideAndYielding'],
            ['eagerFloorTwo', 'fixedFour', 'wideAndYielding', 'zeroWidth'],
            ['growFromNothing'],
            ['floorAboveItsBasis', 'growFromNothing'],
            ['ceilingBelowItsBasis', 'wideAndYielding'],
            ['floorAboveItsBasis', 'ceilingBelowItsBasis', 'fixedFour'],
        ];

        $named = [];
        foreach ($lists as $names) {
            $named[implode('+', $names)] = array_map(
                static fn (string $name): ItemPolicy => $catalogue[$name],
                $names,
            );
        }

        return $named;
    }

    /**
     * @return array<string, ItemPolicy>
     */
    private static function catalogue(): array
    {
        return [
            'growFromNothing' => new ItemPolicy(basis: 0, min: 0, max: PHP_INT_MAX, grow: 1, shrink: 1),
            'fixedFour' => new ItemPolicy(basis: 4, min: 4, max: 4),
            'cappedAtThree' => new ItemPolicy(basis: 0, min: 0, max: 3, grow: 2, shrink: 1),
            'wideAndYielding' => new ItemPolicy(basis: 10, min: 1, max: PHP_INT_MAX, shrink: 1),
            'unweightedSix' => new ItemPolicy(basis: 6),
            'flooredThree' => new ItemPolicy(basis: 3, min: 3, max: PHP_INT_MAX, grow: 1, shrink: 2),
            'zeroWidth' => new ItemPolicy(basis: 0, min: 0, max: 0),
            'eagerFloorTwo' => new ItemPolicy(basis: 2, min: 2, max: 8, grow: 3, shrink: 1),
            'floorAboveItsBasis' => new ItemPolicy(basis: 0, min: 6, max: PHP_INT_MAX, grow: 1, shrink: 1),
            'ceilingBelowItsBasis' => new ItemPolicy(basis: 10, min: 0, max: 4, grow: 1, shrink: 1),
        ];
    }
}
