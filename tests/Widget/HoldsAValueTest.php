<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\Choice\Checkbox;
use Elmphant\Tui\Widget\Choice\CheckboxGroup;
use Elmphant\Tui\Widget\Choice\CheckboxRow;
use Elmphant\Tui\Widget\Choice\Radio;
use Elmphant\Tui\Widget\Choice\RadioRow;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Filter\FilterableCheckboxGroup;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\HoldsAValue;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\ValueItCannotHold;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HoldsAValueTest extends TestCase
{
    /**
     * @return iterable<string, array{HoldsAValue, string|bool|list<string>|null}>
     */
    public static function fields(): iterable
    {
        yield 'text input' => [TextInput::new()->withValue('Ada'), 'Ada'];
        yield 'checkbox' => [Checkbox::new('admin')->withChecked(true), true];
        yield 'radio row' => [RadioRow::new(['a', 'b'])->withSelectedValue('b'), 'b'];
        yield 'radio' => [Radio::new(['a', 'b'])->withSelectedValue('a'), 'a'];
        yield 'select' => [Select::new(['a', 'b'])->withSelectedValue('b'), 'b'];
        yield 'checkbox row' => [CheckboxRow::new(['a', 'b', 'c'])->withChecked([true, false, true]), ['a', 'c']];
        yield 'checkbox group' => [CheckboxGroup::new(['a', 'b', 'c'])->withCheckedValues(['b']), ['b']];
        yield 'filterable list' => [FilterableList::new([new SimpleListItem('a'), new SimpleListItem('b')])->withSelectedValue('b'), 'b'];
        yield 'filterable checkbox group' => [FilterableCheckboxGroup::new(['a', 'b'])->activated('a'), ['a']];
        yield 'dropdown' => [Dropdown::new(Select::new(['a', 'b'])->withSelectedValue('a')), 'a'];
        yield 'nothing chosen' => [RadioRow::new(['a', 'b']), null];
    }

    /**
     * @param string|bool|list<string>|null $expected
     */
    #[Test]
    #[DataProvider('fields')]
    public function aFieldSaysWhatItHolds(HoldsAValue $field, string|bool|array|null $expected): void
    {
        self::assertSame($expected, $field->value());
    }

    /**
     * @param string|bool|list<string>|null $expected
     */
    #[Test]
    #[DataProvider('fields')]
    public function aFieldHoldsWhatItIsGiven(HoldsAValue $field, string|bool|array|null $expected): void
    {
        $emptied = $field->withValue(null);

        self::assertSame($expected, $emptied->withValue($expected)->value());
    }

    #[Test]
    public function aTextFieldRefusesAListOfValues(): void
    {
        $this->expectException(ValueItCannotHold::class);

        TextInput::new()->withValue(['a']);
    }
}
