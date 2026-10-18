<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget\Form;

use Elmphant\Tui\Core\Cmd\MessageCmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Testing\UserInput;
use Elmphant\Tui\Testing\WidgetHarness;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Widget\Form\Field;
use Elmphant\Tui\Widget\Form\Form;
use Elmphant\Tui\Widget\Form\ShowErrors;
use Elmphant\Tui\Widget\Prose\Text;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FormTest extends TestCase
{
    #[Test]
    public function everyFieldShowsItsLabelDescriptionAndWhetherItIsRequired(): void
    {
        $screen = WidgetHarness::for(self::userForm(), 70, 24)->screen();

        self::assertStringContainsString('Create user', $screen->lineAt(0));
        self::assertStringContainsString('Username *required  The login name', $screen->plainText());
        self::assertStringContainsString('[ ] Admin', $screen->plainText());
    }

    #[Test]
    public function theKeyboardArrivesOnTheFirstFieldAndTabWalksOnToTheNext(): void
    {
        $form = WidgetHarness::for(self::userForm(), 70, 24)->type('ada')->press('tab')->type('secret');

        self::assertSame('ada', $form->widget()->values()['username']);
        self::assertSame('secret', $form->widget()->values()['password']);
    }

    #[Test]
    public function aFormSaysWhatEachFieldHolds(): void
    {
        $form = WidgetHarness::for(self::userForm(), 70, 24)
            ->type('ada')
            ->press('tab', 'tab', 'right', 'enter', 'tab', 'space');

        self::assertSame(
            ['username' => 'ada', 'password' => '', 'site' => 'intranet', 'admin' => true],
            $form->widget()->values(),
        );
    }

    #[Test]
    public function aTextFieldIsAsWideAsALineOneTypesNotAsWideAsThePane(): void
    {
        $screen = WidgetHarness::for(self::userForm(), 120, 24)->screen();

        $field = $screen->locate('Username')['row'] + 1;
        $column = $screen->locate('Username')['column'];
        self::assertSame(Attr::UNDERLINE, $screen->styleAt($field, $column + Field::TEXT_WIDTH - 1)->attributes & Attr::UNDERLINE);
        self::assertSame(0, $screen->styleAt($field, $column + Field::TEXT_WIDTH)->attributes & Attr::UNDERLINE);
    }

    #[Test]
    public function aRequiredFieldLeftEmptyIsMissing(): void
    {
        self::assertSame(['username'], self::userForm()->missing());
    }

    #[Test]
    public function nothingIsMarkedMissingUntilASubmitIsTried(): void
    {
        $form = WidgetHarness::for(self::userForm(), 70, 24);
        self::assertStringNotContainsString('Username is required', $form->screen()->plainText());

        $tried = WidgetHarness::for(self::userForm()->submitted(), 70, 24);

        self::assertStringContainsString('Username is required', $tried->screen()->plainText());
    }

    #[Test]
    public function aProblemKeepsABlankLineBeforeTheNextField(): void
    {
        $screen = WidgetHarness::for(self::userForm()->submitted(), 70, 24)->screen();

        $problem = $screen->locate('Username is required')['row'];
        self::assertSame($problem + 2, $screen->locate('Password')['row']);
    }

    #[Test]
    public function aMissingFieldStopsSayingSoOnceItIsFilledIn(): void
    {
        $form = WidgetHarness::for(self::userForm()->submitted(), 70, 24)->type('ada');

        self::assertStringNotContainsString('Username is required', $form->screen()->plainText());
        self::assertSame([], $form->widget()->missing());
    }

    #[Test]
    public function aFieldTellsWhatItsOwnRuleFindsWrongWithWhatWasTypedIn(): void
    {
        $form = Form::new('Task')
            ->withField(Field::text('due', 'Due')->withRule(
                static fn (mixed $value): ?string => \is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? null : 'Due is not a YYYY-MM-DD date',
            ))
            ->submitted();

        $typed = WidgetHarness::for($form, 70, 24)->type('tomorrow');

        self::assertStringContainsString('Due is not a YYYY-MM-DD date', $typed->screen()->plainText());
        self::assertSame(['due' => 'Due is not a YYYY-MM-DD date'], $typed->widget()->problems());
    }

    #[Test]
    public function aRuleLeavesAnEmptyFieldToRequired(): void
    {
        $form = Form::new('Task')
            ->withField(Field::text('due', 'Due')->withRule(static fn (mixed $value): string => 'never valid'));

        self::assertSame([], $form->problems());
    }

    #[Test]
    public function aFormCanSayWhatIsMissingAsSoonAsTheReaderLeavesAField(): void
    {
        $form = WidgetHarness::for(self::userForm()->withErrorsShown(ShowErrors::AfterLeaving), 70, 24);
        self::assertStringNotContainsString('Username is required', $form->screen()->plainText());

        $form->press('tab');

        self::assertStringContainsString('Username is required', $form->screen()->plainText());
    }

    #[Test]
    public function theSubmitButtonIsTheLastStopAndSendsItsMessage(): void
    {
        $form = WidgetHarness::for(self::userForm(), 70, 24)->press('tab', 'tab', 'tab', 'tab', 'enter');

        self::assertEquals([new MessageCmd(new TestMessage('run'))], $form->issued(MessageCmd::class));
    }

    #[Test]
    public function theSubmitButtonInThePinnedFootAnswersAClick(): void
    {
        $form = WidgetHarness::for(self::userForm(), 70, 24)->click('Run', offset: 1);

        self::assertEquals([new MessageCmd(new TestMessage('run'))], $form->issued(MessageCmd::class));
    }

    #[Test]
    public function theFootSaysWhatTheFormIsAbout(): void
    {
        self::assertStringContainsString('❯ backend:user:create', WidgetHarness::for(self::userForm(), 70, 24)->screen()->plainText());
    }

    #[Test]
    public function theFootCanBeRewrittenAsTheFieldsChange(): void
    {
        $screen = WidgetHarness::for(self::userForm()->withFoot('❯ backend:user:create ada'), 70, 24)->screen();

        self::assertStringContainsString('❯ backend:user:create ada', $screen->plainText());
    }

    #[Test]
    public function aDisabledSubmitSaysWhyAndSendsNothing(): void
    {
        $form = WidgetHarness::for(self::userForm()->withSubmitDisabled('not from here'), 70, 24)->click('Run', offset: 1);

        self::assertStringContainsString('not from here', $form->screen()->plainText());
        self::assertSame([], $form->issued(MessageCmd::class));
    }

    #[Test]
    public function atItsLeastHeightAFormStillShowsItsFootAndTheFieldTheKeyboardIsIn(): void
    {
        $form = self::userForm();
        $screen = WidgetHarness::for($form, 70, $form->leastHeight(70))->screen();

        self::assertStringContainsString('Run', $screen->plainText());
        self::assertStringContainsString('▎', $screen->lineAt($screen->locate('├')['row'] - 1));
    }

    #[Test]
    public function itsLeastHeightGivesUpTheBlankAboveTheFoot(): void
    {
        $form = Form::new('About')->withIntro(Text::new('Clears every cache'))->withSubmit('Run', new TestMessage('run'));
        $screen = WidgetHarness::for($form, 70, $form->leastHeight(70))->screen();

        self::assertStringContainsString('Clears every cache', $screen->lineAt($screen->locate('├')['row'] - 1));
    }

    #[Test]
    public function aFieldIsNamedForTheKeyboardToBeSentTo(): void
    {
        self::assertEquals(ComponentId::named('form/4'), self::userForm()->stopOf('password', ComponentId::named('form')));
    }

    #[Test]
    public function anIntroStandsAboveTheFieldsAndTheFieldsStillTakeTheKeyboard(): void
    {
        $intro = self::userForm()->withIntro(Text::new('Creates a user in the backend'));
        $form = WidgetHarness::for($intro, 70, 24)->type('ada');

        $screen = $form->screen();
        self::assertLessThan($screen->locate('Username')['row'], $screen->locate('Creates a user')['row']);
        self::assertSame('ada', $form->widget()->values()['username']);
        self::assertEquals(ComponentId::named('form/5'), $intro->stopOf('password', ComponentId::named('form')));
    }

    #[Test]
    public function aFieldScrolledToComesIntoViewWithItsLabel(): void
    {
        $form = WidgetHarness::for(self::tallForm(), 50, 10)->press('tab', 'tab', 'tab', 'tab');

        self::assertStringContainsString('Field 5', $form->screen()->plainText());
    }

    #[Test]
    public function reachingTheSubmitLeavesTheFieldsWhereTheyWere(): void
    {
        $onTheLast = WidgetHarness::for(self::tallForm(), 50, 12)->press('tab', 'tab', 'tab', 'tab', 'tab');
        self::assertStringContainsString('Field 6', $onTheLast->screen()->plainText());

        self::assertStringContainsString('Field 6', $onTheLast->press('tab')->screen()->plainText());
    }

    #[Test]
    public function aKeyTheFormHasNoUseForIsLeftToItsHost(): void
    {
        $form = self::userForm();

        self::assertSame($form, $form->update(UserInput::key('esc'), Rect::fromSize(70, 24))->model);
    }

    #[Test]
    public function escapeTheFieldsLeaveAloneSendsTheCancelMessage(): void
    {
        $form = WidgetHarness::for(self::userForm()->withCancel(new TestMessage('cancel')), 70, 24)->press('esc');

        self::assertEquals([new MessageCmd(new TestMessage('cancel'))], $form->issued(MessageCmd::class));
    }

    #[Test]
    public function escapeThatClosesAnOpenListIsNotACancel(): void
    {
        $form = WidgetHarness::for(self::userForm()->withCancel(new TestMessage('cancel')), 30, 24)
            ->press('tab', 'tab', 'enter');
        self::assertStringContainsString('Intranet', $form->screen()->plainText(), 'the list is open');

        self::assertSame([], $form->press('esc')->issued(MessageCmd::class));
    }

    #[Test]
    public function aBarMarksTheFieldThatHoldsTheKeyboard(): void
    {
        $screen = WidgetHarness::for(self::userForm(), 70, 24)->press('tab')->screen();

        $password = $screen->locate('Password');
        self::assertSame('▎', $screen->textAt($password['row'], $password['column'] - 2, 1));
        self::assertNotSame('▎', $screen->textAt($screen->locate('Username')['row'], $password['column'] - 2, 1));
    }

    private static function tallForm(): Form
    {
        $form = Form::new('Tall')->withSubmit('Run', new TestMessage('run'));
        foreach (range(1, 6) as $number) {
            $form = $form->withField(Field::text('field' . $number, 'Field ' . $number));
        }

        return $form;
    }

    private static function userForm(): Form
    {
        return Form::new('Create user')
            ->withField(Field::text('username', 'Username', 'The login name')->required())
            ->withField(Field::text('password', 'Password')->secret())
            ->withField(Field::choice('site', 'Site', ['main' => 'Main', 'intranet' => 'Intranet', 'shop' => 'Shop']))
            ->withField(Field::flag('admin', 'Admin', 'Create the user with admin rights'))
            ->withSubmit('Run', new TestMessage('run'), foot: '❯ backend:user:create');
    }
}
