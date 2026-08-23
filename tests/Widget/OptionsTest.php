<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\Choice\CheckboxGroup;
use Elmphant\Tui\Widget\Choice\CheckboxRow;
use Elmphant\Tui\Widget\Choice\Radio;
use Elmphant\Tui\Widget\Choice\RadioRow;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A reader chooses an option, not a row number. A host that reloads, sorts or
 * filters what it handed in leaves the number pointing at whatever moved into
 * that place — so the widget answers with a record nobody picked, and nothing
 * on the screen says so.
 */
final class OptionsTest extends TestCase
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

    #[Test]
    public function aRowKeepsTheOptionItWasOnWhenTheListGrows(): void
    {
        $chosen = RadioRow::new(['alpha', 'beta', 'gamma'])->withSelected(2);
        self::assertSame('gamma', $chosen->selectedValue(), 'precondition: the reader picked gamma');

        $grown = $chosen->withOptions(['zulu', 'alpha', 'beta', 'gamma']);

        self::assertSame('gamma', $grown->selectedValue());
    }

    #[Test]
    public function theCursorStaysOnTheOptionItWasRestingOn(): void
    {
        $resting = RadioRow::new(['alpha', 'beta', 'gamma'])->withCursor(2);

        $grown = $resting->withOptions(['zulu', 'alpha', 'beta', 'gamma']);

        self::assertSame(3, $grown->cursor(), 'the cursor is on an option, so it follows where that went');
    }

    #[Test]
    public function aRowOfBoxesKeepsItsTicksOnTheSameLabels(): void
    {
        $ticked = CheckboxRow::new(['alpha', 'beta', 'gamma'])->withChecked([true, false, true]);
        self::assertSame([0, 2], $ticked->checkedIndices(), 'precondition: alpha and gamma are ticked');

        $grown = $ticked->withOptions(['zulu', 'alpha', 'beta', 'gamma']);

        self::assertSame([1, 3], $grown->checkedIndices(), 'the ticks are on labels, not on places in a row');
    }

    #[Test]
    public function anOptionThatIsGoneLeavesNothingChosen(): void
    {
        $chosen = Select::new(['alpha', 'beta', 'gamma'])->withSelected(2);

        $narrowed = $chosen->withOptions(['alpha', 'beta']);

        self::assertNull($narrowed->selectedValue(), 'the choice went with the option, rather than sliding onto another');
    }

    #[Test]
    public function aRadioWhoseOptionIsGoneStandsOnTheFirst(): void
    {
        $chosen = Radio::new(['alpha', 'beta', 'gamma'])->withSelected(2);

        $narrowed = $chosen->withOptions(['beta', 'gamma-ish']);

        self::assertSame('beta', $narrowed->selectedValue(), 'a radio always stands on something');
    }

    #[Test]
    public function theOptionsItAlreadyHasChangeNothing(): void
    {
        $options = ['alpha', 'beta'];
        $select = Select::new($options)->withSelected(1);

        self::assertSame($select, $select->withOptions($options), 'nothing to re-resolve, so nothing is rebuilt');
    }

    #[Test]
    public function aCursorWhoseOptionIsGoneGoesToTheTop(): void
    {
        $resting = RadioRow::new(['alpha', 'beta', 'gamma'])->withCursor(2);

        $narrowed = $resting->withOptions(['alpha']);

        self::assertSame(0, $narrowed->cursor(), 'a cursor always rests inside the list');
    }

    #[Test]
    public function aCursorOnTheFirstOptionStaysThere(): void
    {
        $resting = RadioRow::new(['alpha', 'beta']);
        self::assertSame(0, $resting->cursor(), 'precondition: the cursor starts at the top');

        $grown = $resting->withOptions(['alpha', 'beta', 'gamma']);

        self::assertSame(0, $grown->cursor(), 'alpha did not move, so neither does the cursor');
    }
}
