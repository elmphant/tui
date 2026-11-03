<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusStateTest extends TestCase
{
    #[Test]
    public function aTreeStartsWithTheKeyboardOnNothingAndNotYetDecided(): void
    {
        $undecided = FocusState::undecided();

        self::assertNull($undecided->focused());
        self::assertTrue($undecided->isUndecided());
    }

    #[Test]
    public function aStateOnAComponentHasTheKeyboardThere(): void
    {
        $state = FocusState::on(ComponentId::named('output'));

        self::assertEquals(ComponentId::named('output'), $state->focused());
        self::assertFalse($state->isUndecided());
    }

    #[Test]
    public function aPaneOnlyThePointerReachesKeepsTheKeyboardWhenTabHasNoStops(): void
    {
        $held = FocusState::on(ComponentId::named('output'));

        $resolved = $held->resolvedAgainst([], self::order('output'));

        self::assertEquals(ComponentId::named('output'), $resolved->focused());
    }

    #[Test]
    public function theFirstMoveFromNothingLandsOnTheFirstComponent(): void
    {
        $moved = FocusState::undecided()->next(self::order('search', 'results'));

        self::assertEquals(ComponentId::named('search'), $moved->focused());
    }

    /**
     * An empty tab order must not turn decided focus into undecided focus, which rendering rejects.
     */
    #[Test]
    public function aMoveWithNowhereToGoLeavesTheKeyboardWhereItWas(): void
    {
        $held = FocusState::on(ComponentId::named('search'));

        $moved = $held->next([]);

        self::assertEquals(ComponentId::named('search'), $moved->focused());
        self::assertFalse($moved->isUndecided(), 'and the answer stays an answer');
    }

    #[Test]
    public function aMoveLandsOnTheComponentAfterTheFocusedOne(): void
    {
        $moved = FocusState::on(ComponentId::named('search'))->next(self::order('search', 'results'));

        self::assertEquals(ComponentId::named('results'), $moved->focused());
    }

    #[Test]
    public function aMoveOffTheLastComponentWrapsToTheFirst(): void
    {
        $moved = FocusState::on(ComponentId::named('results'))->next(self::order('search', 'results'));

        self::assertEquals(ComponentId::named('search'), $moved->focused());
    }

    #[Test]
    public function theFirstBackwardMoveFromNothingLandsOnTheLastComponent(): void
    {
        $moved = FocusState::undecided()->previous(self::order('search', 'results', 'preview'));

        self::assertEquals(ComponentId::named('preview'), $moved->focused());
    }

    #[Test]
    public function focusOnAComponentThatLeftTheTreeFallsBackToTheFirstOne(): void
    {
        $gone = FocusState::on(ComponentId::named('modal-field'));

        $resolved = $gone->resolvedAgainst(self::order('search', 'results'));

        self::assertEquals(ComponentId::named('search'), $resolved->focused());
    }

    #[Test]
    public function focusOnAStopThatLeftStaysWithTheContainerItWasIn(): void
    {
        $gone = FocusState::on(ComponentId::named('tabs/2'));

        $resolved = $gone->resolvedAgainst(self::order('side/0', 'tabs', 'tabs/0'));

        self::assertEquals(ComponentId::named('tabs'), $resolved->focused());
    }

    #[Test]
    public function focusOnAStopThatLeftLandsOnTheFirstStopStillUnderItsContainer(): void
    {
        $gone = FocusState::on(ComponentId::named('form/3'));

        $resolved = $gone->resolvedAgainst(self::order('side/0', 'form/0', 'form/1'));

        self::assertEquals(ComponentId::named('form/0'), $resolved->focused());
    }

    #[Test]
    public function focusOnAContainerThatIsNoStopLandsOnTheFirstStopUnderIt(): void
    {
        $container = FocusState::on(ComponentId::named('form'));

        $resolved = $container->resolvedAgainst(self::order('side/0', 'form/1', 'form/4'));

        self::assertEquals(ComponentId::named('form/1'), $resolved->focused());
    }

    #[Test]
    public function aTreeWithNothingFocusableKeepsTheKeyboardOnNothing(): void
    {
        self::assertNull(FocusState::on(ComponentId::named('search'))->resolvedAgainst([])->focused());
    }

    /**
     * @return list<ComponentId>
     */
    private static function order(string ...$names): array
    {
        return array_map(ComponentId::named(...), $names);
    }
}
