<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Layout;

use Elmphant\Tui\Layout\SplitState;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SplitStateTest extends TestCase
{
    #[Test]
    public function survivesTheTripThroughASessionFile(): void
    {
        $dragged = SplitState::even(2)->withPairDividedAt(0, 14, 20);

        $restored = SplitState::fromArray($dragged->toArray(), 2);

        self::assertSame($dragged->shares, $restored->shares);
    }

    #[Test]
    public function anEvenDivisionSpendsEveryPermille(): void
    {
        self::assertSame([1000], SplitState::even(1)->shares);
        self::assertSame([500, 500], SplitState::even(2)->shares);
        self::assertSame([333, 333, 334], SplitState::even(3)->shares, 'The remainder lands on the last pane');
    }

    #[Test]
    public function fewerThanOnePaneStillDividesTheWholeAxis(): void
    {
        self::assertSame([1000], SplitState::even(0)->shares);
        self::assertSame([1000], SplitState::even(-3)->shares);
    }

    #[Test]
    public function aStoredPaneOfNothingIsKept(): void
    {
        self::assertSame([1, 0, 0], SplitState::fromArray([1, 0, 0], 3)->shares);
    }

    #[Test]
    public function storedSharesAreRenumberedIntoAList(): void
    {
        $restored = SplitState::fromArray([2 => 500, 0 => 250, 1 => 250], 3);

        self::assertSame([0, 1, 2], array_keys($restored->shares));
    }

    #[Test]
    public function aPairWithNoSpaceBetweenThemKeepsItsShares(): void
    {
        self::assertSame([500, 500], SplitState::even(2)->withPairDividedAt(0, 5, 0)->shares);
    }

    #[Test]
    public function dividingAPairLeavesEveryOtherPaneAlone(): void
    {
        self::assertSame([333, 167, 500], SplitState::even(3)->withPairDividedAt(1, 5, 20)->shares);
        self::assertSame([167, 499, 334], SplitState::even(3)->withPairDividedAt(0, 5, 20)->shares);
    }

    #[Test]
    public function aDividerOutsideThePanesChangesNothing(): void
    {
        $state = new SplitState([500, 500]);

        self::assertSame([500, 500], $state->withPairDividedAt(1, 5, 20)->shares, 'The last pane has no pane after it');
        self::assertSame([500, 500], $state->withPairDividedAt(5, 5, 20)->shares, 'Beyond the panes entirely');
        self::assertSame([500, 500], $state->withPairDividedAt(-1, 5, 20)->shares, 'Before the panes');
    }

    #[Test]
    public function halfAPermilleRoundsUpAndAnythingLessRoundsDown(): void
    {
        $state = new SplitState([500, 500]);

        self::assertSame([668, 332], $state->withPairDividedAt(0, 6675, 10000)->shares);
        self::assertSame([667, 333], $state->withPairDividedAt(0, 6674, 10000)->shares);
    }

    /**
     * @return iterable<string, array{list<int>}>
     */
    public static function unusableSharesProvider(): iterable
    {
        yield 'no panes at all' => [[]];
        yield 'a pane of less than nothing' => [[-1, 500, 501]];
        yield 'nothing to divide' => [[0, 0, 0]];
    }

    /**
     * @param list<int> $shares
     */
    #[Test]
    #[DataProvider('unusableSharesProvider')]
    public function sharesThatDivideNothingAreRefused(array $shares): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SplitState shares must be non-negative with at least one above zero');

        new SplitState($shares);
    }

    #[Test]
    public function fallsBackToAnEvenDivisionWhenTheFileHeldNonsense(): void
    {
        $even = SplitState::even(3)->shares;

        self::assertSame($even, SplitState::fromArray(null, 3)->shares, 'Missing');
        self::assertSame($even, SplitState::fromArray([500, 500], 3)->shares, 'Written for another pane count');
        self::assertSame($even, SplitState::fromArray(['a', 'b', 'c'], 3)->shares, 'Not numbers');
        self::assertSame($even, SplitState::fromArray([-1, 500, 501], 3)->shares, 'Negative');
        self::assertSame($even, SplitState::fromArray([0, 0, 0], 3)->shares, 'Nothing to divide');
    }
}
