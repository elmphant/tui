<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\CheckboxGroup;
use Elmphant\Tui\Widget\ListView;
use Elmphant\Tui\Widget\Radio;
use Elmphant\Tui\Widget\Select;
use Elmphant\Tui\Widget\SimpleListItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A reader chooses an option, not a row number. A host that reloads, sorts or
 * filters what it handed in leaves the number pointing at whatever moved into
 * that place — so the widget answers with a record nobody picked, and nothing
 * on the screen says so.
 */
final class AChoiceSurvivesItsOptionsChangingTest extends TestCase
{
    #[Test]
    public function anOptionThatMovedIsStillTheChosenOne(): void
    {
        $chosen = Select::new(['alpha', 'beta', 'gamma'])->withSelected(2);
        self::assertSame('gamma', $chosen->selectedValue(), 'precondition: the reader picked gamma');

        $reordered = $chosen->withOptions(['zulu', 'alpha', 'beta', 'gamma']);

        self::assertSame('gamma', $reordered->selectedValue(), 'the choice follows the option, not its place');
    }

    #[Test]
    public function anItemThatMovedIsStillTheSelectedOne(): void
    {
        $chosen = ListView::new([
            new SimpleListItem('alpha'),
            new SimpleListItem('beta'),
            new SimpleListItem('gamma'),
        ])->withSelected(2);
        self::assertSame('gamma', $chosen->selectedItem()?->title(), 'precondition: the reader picked gamma');

        $reloaded = $chosen->withItems([
            new SimpleListItem('zulu'),
            new SimpleListItem('alpha'),
            new SimpleListItem('beta'),
            new SimpleListItem('gamma'),
        ]);

        self::assertSame('gamma', $reloaded->selectedItem()?->title(), 'a reload does not hand back another record');
    }

    #[Test]
    public function aRadioKeepsTheOptionItWasOnWhenTheListGrows(): void
    {
        $chosen = Radio::new(['alpha', 'beta', 'gamma'])->withSelected(2);
        self::assertSame('gamma', $chosen->selectedValue(), 'precondition: the reader picked gamma');

        $grown = $chosen->withOptions(['zulu', 'alpha', 'beta', 'gamma']);

        self::assertSame('gamma', $grown->selectedValue());
    }

    #[Test]
    public function tickedBoxesStayOnTheOptionsTheyWereTickedFor(): void
    {
        $ticked = CheckboxGroup::new(['alpha', 'beta', 'gamma'])->withChecked([0, 2]);
        self::assertSame(['alpha', 'gamma'], $ticked->checkedValues(), 'precondition: two boxes are ticked');

        $grown = $ticked->withOptions(['zulu', 'alpha', 'beta', 'gamma']);

        self::assertSame(['alpha', 'gamma'], $grown->checkedValues(), 'the ticks follow the options they were put on');
    }
}
