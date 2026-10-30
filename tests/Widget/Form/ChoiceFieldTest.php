<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget\Form;

use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Testing\WidgetHarness;
use Elmphant\Tui\Widget\Form\ChoiceField;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChoiceFieldTest extends TestCase
{
    private const SITES = [
        'main' => 'Main website',
        'intranet' => 'Intranet portal',
        'shop' => 'Shop frontend',
    ];

    #[Test]
    public function choicesThatFitOnOneRowAreARowOfMarks(): void
    {
        $screen = WidgetHarness::for(ChoiceField::new(self::SITES)->withValue('intranet'), 80, 3)->screen();

        self::assertStringContainsString('( ) Main website', $screen->lineAt(0));
        self::assertStringContainsString('(●) Intranet portal', $screen->lineAt(0));
    }

    #[Test]
    public function choicesThatWouldWrapCollapseIntoAListShowingWhatIsChosen(): void
    {
        $screen = WidgetHarness::for(ChoiceField::new(self::SITES)->withValue('intranet'), 30, 3)->screen();

        self::assertStringContainsString('Intranet portal', $screen->lineAt(0));
        self::assertStringNotContainsString('Main website', $screen->plainText());
    }

    #[Test]
    public function aCollapsedFieldWithNothingChosenAsksForAChoice(): void
    {
        self::assertStringContainsString('▾ choose…', WidgetHarness::for(ChoiceField::new(self::SITES), 30, 3)->screen()->lineAt(0));
    }

    #[Test]
    public function whatIsChosenIsTheValueNotTheLabel(): void
    {
        $field = WidgetHarness::for(ChoiceField::new(self::SITES), 80, 3)->press('right', 'enter');

        self::assertSame('intranet', $field->widget()->value());
    }

    #[Test]
    public function nothingIsChosenUntilTheReaderChooses(): void
    {
        self::assertNull(ChoiceField::new(self::SITES)->value());
    }

    #[Test]
    public function aCollapsedFieldStepsThroughTheChoicesWithTheArrows(): void
    {
        $field = WidgetHarness::for(ChoiceField::new(self::SITES)->withValue('main'), 30, 3)->press('right');

        self::assertSame('intranet', $field->widget()->value());
        self::assertStringContainsString('Intranet portal', $field->screen()->lineAt(0));
    }

    #[Test]
    public function theArrowsStartAtTheFirstChoiceAndStopAtEitherEnd(): void
    {
        $steps = [
            [['right'], 'main'],
            [['right', 'left'], 'main'],
            [['right', 'right', 'right', 'right'], 'shop'],
            [['right', 'right', 'right', 'right', 'left'], 'intranet'],
        ];

        foreach ($steps as [$keys, $value]) {
            self::assertSame($value, WidgetHarness::for(ChoiceField::new(self::SITES), 30, 3)->press(...$keys)->widget()->value(), implode(' ', $keys));
        }
    }

    #[Test]
    public function aCollapsedFieldStepsThroughChoicesNamedByNumbers(): void
    {
        $field = ChoiceField::new([10 => 'The first long choice', 20 => 'The second long choice'])->withValue('10');

        self::assertSame('20', WidgetHarness::for($field, 30, 3)->press('right')->widget()->value());
    }

    #[Test]
    public function aCollapsedFieldIsChosenFromItsFilteredList(): void
    {
        $field = WidgetHarness::for(ChoiceField::new(self::SITES), 30, 8)->press('enter')->type('shop')->press('enter');

        self::assertSame('shop', $field->widget()->value());
    }

    #[Test]
    public function aChoiceKeepsWhenTheFieldSwitchesBetweenRowAndList(): void
    {
        $chosenInARow = WidgetHarness::for(ChoiceField::new(self::SITES), 80, 3)->press('right', 'right', 'enter')->widget();

        $narrow = WidgetHarness::for($chosenInARow, 30, 3);

        self::assertSame('shop', $narrow->widget()->value());
        self::assertStringContainsString('Shop frontend', $narrow->screen()->lineAt(0));
    }

    #[Test]
    public function newChoicesKeepWhatWasChosenWhenItIsStillOffered(): void
    {
        $field = ChoiceField::new(self::SITES)->withValue('shop');

        self::assertSame('shop', $field->withOptions(['shop' => 'Shop', 'docs' => 'Docs'])->value());
        self::assertNull($field->withOptions(['docs' => 'Docs'])->value());
    }

    #[Test]
    public function aCollapsedFieldFiltersWithTheMatcherItIsGiven(): void
    {
        $initials = new class implements Matcher {
            public function matching(string $query, array $haystack): array
            {
                return array_keys(array_filter(
                    $haystack,
                    static fn (string $label): bool => implode('', array_map(static fn (string $word): string => $word[0], explode(' ', strtolower($label)))) === $query,
                ));
            }
        };

        $field = WidgetHarness::for(ChoiceField::new(self::SITES)->withMatcher($initials), 30, 8)
            ->press('enter')->type('ip')->press('enter');

        self::assertSame('intranet', $field->widget()->value());
    }

    #[Test]
    public function aClearableChoiceIsTakenBackByChoosingItAgain(): void
    {
        $row = WidgetHarness::for(ChoiceField::new(self::SITES)->withValue('main')->withClearable(), 80, 3)->press('enter');
        $list = WidgetHarness::for(ChoiceField::new(self::SITES)->withValue('main')->withClearable(), 30, 8)->press('enter', 'enter');

        self::assertNull($row->widget()->value());
        self::assertNull($list->widget()->value());
    }

    #[Test]
    public function itsHintsHoldInEitherShape(): void
    {
        $hints = WidgetHarness::for(ChoiceField::new(self::SITES), 80, 3)->focusedHints();

        self::assertContains('move', $hints);
        self::assertContains('choose', $hints);
    }
}
