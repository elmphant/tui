<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\Choice\CheckboxGroup;
use Elmphant\Tui\Widget\Choice\RadioRow;
use Elmphant\Tui\Widget\Choice\Select;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Select by value so hosts do not have to duplicate the widget's lookup after sorting or reloads.
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
}
