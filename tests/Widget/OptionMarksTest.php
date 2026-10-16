<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Text\CharWidth;
use Elmphant\Tui\Widget\Choice\OptionMarks;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OptionMarksTest extends TestCase
{
    #[Test]
    public function drawsAChoiceInParenthesesAndAToggleInBracketsOverTheSameColumns(): void
    {
        self::assertSame('(●) ', OptionMarks::SELECTED);
        self::assertSame('( ) ', OptionMarks::UNSELECTED);
        self::assertSame('[x] ', OptionMarks::CHECKED);
        self::assertSame('[ ] ', OptionMarks::UNCHECKED);

        $widths = array_map(
            CharWidth::stringWidth(...),
            [OptionMarks::SELECTED, OptionMarks::UNSELECTED, OptionMarks::CHECKED, OptionMarks::UNCHECKED],
        );

        self::assertSame([4, 4, 4, 4], $widths, 'a form mixing both controls lines its labels up');
    }
}
