<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\CheckboxGroup;
use Elmphant\Tui\Widget\Radio;
use Elmphant\Tui\Widget\RadioRow;
use Elmphant\Tui\Widget\Select;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A host holds what was chosen, not where it sat. Left to set a choice by number it
 * has to search the options for the value first — the same search the widget already
 * does to keep a choice across a reload.
 */
final class OptionValueSettersTest extends TestCase
{
    #[Test]
    public function theValueNamesWhatIsChosen(): void
    {
        $select = Select::new(['alpha', 'beta', 'gamma'])->withSelectedValue('gamma');

        self::assertSame('gamma', $select->selectedValue());
    }

    #[Test]
    public function theCursorGoesToTheChosenOptionOfARow(): void
    {
        $row = RadioRow::new(['alpha', 'beta', 'gamma'])->withSelectedValue('gamma');

        self::assertSame('gamma', $row->selectedValue());
        self::assertSame(2, $row->cursor(), 'the reader is looking at what is chosen');
    }

    #[Test]
    public function theValuesNameWhatIsTicked(): void
    {
        $group = CheckboxGroup::new(['alpha', 'beta', 'gamma'])->withCheckedValues(['gamma', 'alpha']);

        self::assertSame(['alpha', 'gamma'], $group->checkedValues(), 'in the order the options read, not the order given');
    }

    #[Test]
    public function aRadioHandedAValueItDoesNotHaveStandsOnTheFirst(): void
    {
        $radio = Radio::new(['alpha', 'beta'])->withSelectedValue('gone');

        self::assertSame('alpha', $radio->selectedValue(), 'a radio always stands on something');
    }
}
