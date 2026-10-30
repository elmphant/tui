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
    public function aTreeStartsWithTheKeyboardOnNothing(): void
    {
        self::assertNull((new FocusState())->focused());
    }

    #[Test]
    public function aPaneOnlyThePointerReachesKeepsTheKeyboardWhenTabHasNoStops(): void
    {
        $held = new FocusState(ComponentId::named('output'));

        $resolved = $held->resolvedAgainst([], self::order('output'));

        self::assertEquals(ComponentId::named('output'), $resolved->focused());
    }

    #[Test]
    public function theFirstMoveFromNothingLandsOnTheFirstComponent(): void
    {
        $moved = (new FocusState())->next(self::order('search', 'results'));

        self::assertEquals(ComponentId::named('search'), $moved->focused());
    }

    /**
     * An empty tab order must not turn decided focus into undecided focus, which rendering rejects.
     */
    #[Test]
    public function aMoveWithNowhereToGoLeavesTheKeyboardWhereItWas(): void
    {
        $held = new FocusState(ComponentId::named('search'));

        $moved = $held->next([]);

        self::assertEquals(ComponentId::named('search'), $moved->focused());
        self::assertFalse($moved->isUndecided(), 'and the answer stays an answer');
    }

    #[Test]
    public function aMoveLandsOnTheComponentAfterTheFocusedOne(): void
    {
        $moved = (new FocusState(ComponentId::named('search')))->next(self::order('search', 'results'));

        self::assertEquals(ComponentId::named('results'), $moved->focused());
    }

    #[Test]
    public function aMoveOffTheLastComponentWrapsToTheFirst(): void
    {
        $moved = (new FocusState(ComponentId::named('results')))->next(self::order('search', 'results'));

        self::assertEquals(ComponentId::named('search'), $moved->focused());
    }

    #[Test]
    public function theFirstBackwardMoveFromNothingLandsOnTheLastComponent(): void
    {
        $moved = (new FocusState())->previous(self::order('search', 'results', 'preview'));

        self::assertEquals(ComponentId::named('preview'), $moved->focused());
    }

    #[Test]
    public function focusOnAComponentThatLeftTheTreeFallsBackToTheFirstOne(): void
    {
        $gone = new FocusState(ComponentId::named('modal-field'));

        $resolved = $gone->resolvedAgainst(self::order('search', 'results'));

        self::assertEquals(ComponentId::named('search'), $resolved->focused());
    }

    #[Test]
    public function focusOnAStopThatLeftStaysWithTheContainerItWasIn(): void
    {
        $gone = new FocusState(ComponentId::named('tabs/2'));

        $resolved = $gone->resolvedAgainst(self::order('side/0', 'tabs', 'tabs/0'));

        self::assertEquals(ComponentId::named('tabs'), $resolved->focused());
    }

    #[Test]
    public function focusOnAStopThatLeftLandsOnTheFirstStopStillUnderItsContainer(): void
    {
        $gone = new FocusState(ComponentId::named('form/3'));

        $resolved = $gone->resolvedAgainst(self::order('side/0', 'form/0', 'form/1'));

        self::assertEquals(ComponentId::named('form/0'), $resolved->focused());
    }

    #[Test]
    public function focusOnAContainerThatIsNoStopLandsOnTheFirstStopUnderIt(): void
    {
        $container = new FocusState(ComponentId::named('form'));

        $resolved = $container->resolvedAgainst(self::order('side/0', 'form/1', 'form/4'));

        self::assertEquals(ComponentId::named('form/1'), $resolved->focused());
    }

    #[Test]
    public function aTreeWithNothingFocusableKeepsTheKeyboardOnNothing(): void
    {
        self::assertNull((new FocusState(ComponentId::named('search')))->resolvedAgainst([])->focused());
    }

    /**
     * @return list<ComponentId>
     */
    private static function order(string ...$names): array
    {
        return array_map(ComponentId::named(...), $names);
    }
}
