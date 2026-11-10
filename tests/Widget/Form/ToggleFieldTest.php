<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget\Form;

use Elmphant\Tui\Testing\WidgetHarness;
use Elmphant\Tui\Widget\Choice\Toggle;
use Elmphant\Tui\Widget\Form\Field;
use Elmphant\Tui\Widget\Form\Form;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToggleFieldTest extends TestCase
{
    #[Test]
    public function aToggleFieldHoldsItsAnswerByName(): void
    {
        $form = Form::new('Settings')->withField(Field::toggle('newsletter', 'Newsletter'));

        self::assertSame(['newsletter' => false], $form->values());
        self::assertSame(['newsletter' => true], $form->withValues(['newsletter' => true])->values());
    }

    #[Test]
    public function enterOnAToggleMovesOnToTheNextField(): void
    {
        $form = Form::new('Settings')
            ->withField(Field::toggle('newsletter', 'Newsletter'))
            ->withField(Field::text('email', 'Email'));

        $filled = WidgetHarness::for($form, 60, 16)->press('y', 'enter')->type('ada@example.com');

        self::assertSame(['newsletter' => true, 'email' => 'ada@example.com'], $filled->widget()->values());
    }

    #[Test]
    public function aRuleThatDemandsYesSeesNo(): void
    {
        $field = Field::toggle('terms', 'Terms')->addRule(static fn (string|bool|array $on) => $on === true ? null : 'Accept the terms');

        self::assertSame('Accept the terms', $field->problem());
        self::assertNull($field->withWidget(Toggle::new()->withOn(true))->problem());
    }

    #[Test]
    public function aToggleIsNeverAnEmptyField(): void
    {
        self::assertFalse(Field::toggle('terms', 'Terms')->isEmpty());
    }

    #[Test]
    public function aToggleHandedInAsAnyWidgetIsJudgedTheSame(): void
    {
        $field = Field::of('terms', 'Terms', Toggle::new())->addRule(static fn (string|bool|array $on) => $on === true ? null : 'Accept the terms');

        self::assertSame('Accept the terms', $field->problem());
        self::assertFalse($field->isEmpty());
    }

    #[Test]
    public function aRequiredToggleIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"terms" holds a Toggle, which always has an answer. Use addRule() to demand Yes.');

        Field::toggle('terms', 'Terms')->withRequired();
    }

    #[Test]
    public function aToggleHandedToARequiredFieldIsRefused(): void
    {
        $this->expectException(LogicException::class);

        Field::text('terms', 'Terms')->withRequired()->withWidget(Toggle::new());
    }

    #[Test]
    public function theLabelOfAToggleFieldIsTheRowAbove(): void
    {
        $screen = WidgetHarness::for(Form::new('Settings')->withField(Field::toggle('newsletter', 'Newsletter')), 60, 16)->screen();

        self::assertSame($screen->rowOf('Newsletter') + 1, $screen->rowOf('○ Yes / ● No'));
    }
}
