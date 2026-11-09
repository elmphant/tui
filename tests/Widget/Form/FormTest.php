<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget\Form;

use Elmphant\Tui\Core\Cmd\MessageCmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Attr;
use Elmphant\Tui\Testing\UserInput;
use Elmphant\Tui\Testing\WidgetHarness;
use Elmphant\Tui\Tests\Support\TestMessage;
use Elmphant\Tui\Widget\Choice\Checkbox;
use Elmphant\Tui\Widget\Form\Field;
use Elmphant\Tui\Widget\Form\Form;
use Elmphant\Tui\Widget\Form\ShowErrors;
use Elmphant\Tui\Widget\Prose\Text;
use LogicException;
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
    public function aRequiredFlagShowsItIsRequiredBeforeTheFormIsSubmitted(): void
    {
        $form = Form::new('Sign up')
            ->withField(Field::flag('terms', 'Accept the terms', 'You must agree to continue')->withRequired());

        $screen = WidgetHarness::for($form, 70, 24)->screen();

        self::assertStringContainsString('*required  You must agree to continue', $screen->plainText());
        self::assertStringContainsString('[ ] Accept the terms', $screen->plainText());
    }

    #[Test]
    public function theKeyboardArrivesOnTheFirstFieldAndTabWalksOnToTheNext(): void
    {
        $form = WidgetHarness::for(self::userForm(), 70, 24)->type('ada')->press('tab')->type('secret');

        self::assertSame('ada', $form->widget()->values()['username']);
        self::assertSame('secret', $form->widget()->values()['password']);
    }

    #[Test]
    public function aSecretFieldKeepsWhatIsTypedOffTheScreen(): void
    {
        $form = WidgetHarness::for(Form::new('Login')->withField(Field::secret('password', 'Password')), 70, 12)->type('hunter2');

        self::assertSame('hunter2', $form->widget()->values()['password']);
        self::assertStringNotContainsString('hunter2', $form->screen()->plainText());
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

        $field = $screen->findOrFail('Username')['row'] + 1;
        $column = $screen->findOrFail('Username')['column'];
        self::assertSame(Attr::UNDERLINE, $screen->styleAt($column + Field::TEXT_WIDTH - 1, $field)->attributes & Attr::UNDERLINE);
        self::assertSame(0, $screen->styleAt($column + Field::TEXT_WIDTH, $field)->attributes & Attr::UNDERLINE);
    }

    #[Test]
    public function aFormIsFilledInByNameForEditing(): void
    {
        $values = ['username' => 'ada', 'password' => 'secret', 'site' => 'shop', 'admin' => true];

        self::assertSame($values, self::userForm()->withValues($values)->values());
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

        $tried = WidgetHarness::for(self::userForm()->withSubmitAttempted(), 70, 24);

        self::assertStringContainsString('Username is required', $tried->screen()->plainText());
    }

    #[Test]
    public function aProblemKeepsABlankLineBeforeTheNextField(): void
    {
        $screen = WidgetHarness::for(self::userForm()->withSubmitAttempted(), 70, 24)->screen();

        $problem = $screen->findOrFail('Username is required')['row'];
        self::assertSame($problem + 2, $screen->findOrFail('Password')['row']);
    }

    #[Test]
    public function aMissingFieldStopsSayingSoOnceItIsFilledIn(): void
    {
        $form = WidgetHarness::for(self::userForm()->withSubmitAttempted(), 70, 24)->type('ada');

        self::assertStringNotContainsString('Username is required', $form->screen()->plainText());
        self::assertSame([], $form->widget()->missing());
    }

    #[Test]
    public function aFieldTellsWhatItsOwnRuleFindsWrongWithWhatWasTypedIn(): void
    {
        $form = Form::new('Task')
            ->withField(Field::text('due', 'Due')->addRule(
                static fn (mixed $value): ?string => \is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? null : 'Due is not a YYYY-MM-DD date',
            ))
            ->withSubmitAttempted();

        $typed = WidgetHarness::for($form, 70, 24)->type('tomorrow');

        self::assertStringContainsString('Due is not a YYYY-MM-DD date', $typed->screen()->plainText());
        self::assertSame(['due' => 'Due is not a YYYY-MM-DD date'], $typed->widget()->problems());
    }

    #[Test]
    public function everyRuleOfAFieldIsAsked(): void
    {
        $form = Form::new('Task')->withField(Field::text('due', 'Due')
            ->addRule(static fn (mixed $value): ?string => $value === 'never' ? 'Due cannot be never' : null)
            ->addRule(static fn (mixed $value): ?string => $value === 'soon' ? 'Due needs a date' : null));

        self::assertSame(['due' => 'Due cannot be never'], $form->withValues(['due' => 'never'])->problems());
        self::assertSame(['due' => 'Due needs a date'], $form->withValues(['due' => 'soon'])->problems());
    }

    #[Test]
    public function aSecretFieldShowsDotsForWhatIsTyped(): void
    {
        $form = WidgetHarness::for(self::userForm(), 70, 24)->press('tab')->type('hunter2');

        self::assertStringNotContainsString('hunter2', $form->screen()->plainText());
        self::assertSame('hunter2', $form->widget()->values()['password']);
    }

    #[Test]
    public function theRuleAboveTheFootSaysWhatIsMissingOnceASubmitIsTried(): void
    {
        self::assertStringNotContainsString('fill in what is required', WidgetHarness::for(self::userForm(), 70, 24)->screen()->plainText());

        self::assertStringContainsString('fill in what is required', WidgetHarness::for(self::userForm()->withSubmitAttempted(), 70, 24)->screen()->plainText());
    }

    #[Test]
    public function aRuleLeavesAnEmptyFieldToRequired(): void
    {
        $form = Form::new('Task')
            ->withField(Field::text('due', 'Due')->addRule(static fn (mixed $value): string => 'never valid'));

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
    public function aFieldLeftEarlierStillSaysWhatIsWrongAfterTheNextIsLeft(): void
    {
        $form = Form::new('Login')
            ->withField(Field::text('username', 'Username')->withRequired())
            ->withField(Field::text('password', 'Password')->withRequired())
            ->withField(Field::text('site', 'Site'))
            ->withErrorsShown(ShowErrors::AfterLeaving);

        $screen = WidgetHarness::for($form, 70, 24)->press('tab', 'tab')->screen()->plainText();

        self::assertStringContainsString('Username is required', $screen);
        self::assertStringContainsString('Password is required', $screen);
    }

    #[Test]
    public function enterInATextFieldMovesOnToTheNextField(): void
    {
        $form = WidgetHarness::for(self::userForm(), 70, 24)->type('ada')->press('enter')->type('secret');

        self::assertSame('secret', $form->widget()->values()['password']);
    }

    #[Test]
    public function enterInTheLastFieldSubmits(): void
    {
        $form = Form::new('Login')
            ->withField(Field::text('username', 'Username'))
            ->withField(Field::text('password', 'Password'))
            ->withSubmit('Log in', new TestMessage('login'));

        $harness = WidgetHarness::for($form, 70, 24)->press('tab')->type('secret')->press('enter');

        self::assertEquals([new MessageCmd(new TestMessage('login'))], $harness->issued(MessageCmd::class));
    }

    #[Test]
    public function theSubmitButtonIsTheLastStopAndSendsItsMessage(): void
    {
        $form = WidgetHarness::for(self::userForm(), 70, 24)->type('ada')->press('tab', 'tab', 'tab', 'tab', 'enter');

        self::assertEquals([new MessageCmd(new TestMessage('run'))], $form->issued(MessageCmd::class));
    }

    #[Test]
    public function theSubmitButtonInThePinnedFootAnswersAClick(): void
    {
        $form = WidgetHarness::for(self::userForm(), 70, 24)->type('ada')->click('Run', offset: 1);

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
    public function aFootSetBeforeTheSubmitButtonStays(): void
    {
        $form = Form::new('Notes')
            ->withField(Field::text('title', 'Title'))
            ->withFoot('❯ notes:add')
            ->withSubmit('Add', new TestMessage('add'));

        self::assertStringContainsString('❯ notes:add', WidgetHarness::for($form, 70, 12)->screen()->plainText());
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
        self::assertStringContainsString('▎', $screen->lineAt($screen->findOrFail('├')['row'] - 1));
    }

    #[Test]
    public function itsLeastHeightGivesUpTheBlankAboveTheFoot(): void
    {
        $form = Form::new('About')->withIntro(Text::new('Clears every cache'))->withSubmit('Run', new TestMessage('run'));
        $screen = WidgetHarness::for($form, 70, $form->leastHeight(70))->screen();

        self::assertStringContainsString('Clears every cache', $screen->lineAt($screen->findOrFail('├')['row'] - 1));
    }

    #[Test]
    public function aFieldIsNamedForTheKeyboardToBeSentTo(): void
    {
        self::assertEquals(ComponentId::named('form/4'), self::userForm()->stopOf('password', ComponentId::named('form')));
    }

    #[Test]
    public function theFormCanBeNamedByTheStringItWasBoundWith(): void
    {
        self::assertEquals(ComponentId::named('form/4'), self::userForm()->stopOf('password', 'form'));
    }

    #[Test]
    public function anIntroStandsAboveTheFieldsAndTheFieldsStillTakeTheKeyboard(): void
    {
        $intro = self::userForm()->withIntro(Text::new('Creates a user in the backend'));
        $form = WidgetHarness::for($intro, 70, 24)->type('ada');

        $screen = $form->screen();
        self::assertLessThan($screen->findOrFail('Username')['row'], $screen->findOrFail('Creates a user')['row']);
        self::assertSame('ada', $form->widget()->values()['username']);
        self::assertEquals(ComponentId::named('form/5'), $intro->stopOf('password', ComponentId::named('form')));
    }

    #[Test]
    public function aFieldWithoutANameIsRefusedBecauseNothingCouldAskForItsValue(): void
    {
        $this->expectException(LogicException::class);

        Field::text(' ', 'Name');
    }

    #[Test]
    public function anIntroThatTakesInputIsRefusedBecauseNothingWouldKeepWhatItTook(): void
    {
        $this->expectException(LogicException::class);

        self::userForm()->withIntro(Checkbox::new('Agree'));
    }

    #[Test]
    public function aFieldScrolledToComesIntoViewWithItsLabel(): void
    {
        $form = WidgetHarness::for(self::tallForm(), 50, 10)->press('tab', 'tab', 'tab', 'tab');

        self::assertStringContainsString('Field 5', $form->screen()->plainText());
    }

    #[Test]
    public function aFormTooShortForALabelAndItsFieldShowsTheField(): void
    {
        $form = WidgetHarness::for(self::tallForm(), 28, 6)->type('typed');
        self::assertNotNull($form->screen()->find('typed'));
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
    public function aCancelKeyTheAppBindsSendsTheCancelMessage(): void
    {
        $form = self::userForm()
            ->withCancel(new TestMessage('cancel'))
            ->withKeybindings(new Keybindings([Actions::CANCEL => ['ctrl+g']]));

        $pressed = WidgetHarness::for($form, 70, 24)->press('ctrl+g');

        self::assertEquals([new MessageCmd(new TestMessage('cancel'))], $pressed->issued(MessageCmd::class));
    }

    #[Test]
    public function aNextFieldKeyTheAppBindsMovesOn(): void
    {
        $form = self::userForm()->withKeybindings(new Keybindings([Actions::NEXT_FIELD => ['ctrl+n']]));

        $moved = WidgetHarness::for($form, 70, 24)->type('ada')->press('ctrl+n')->type('secret');

        self::assertSame('secret', $moved->widget()->values()['password']);
    }

    #[Test]
    public function escapeIsBoundOnlyOnceTheFormHasSomethingToSendForIt(): void
    {
        self::assertSame(
            [[], ['escape']],
            [
                array_map(strval(...), self::userForm()->effectiveKeybindings()->patternsOf(Actions::CANCEL)),
                array_map(strval(...), self::userForm()->withCancel(new TestMessage('cancel'))->effectiveKeybindings()->patternsOf(Actions::CANCEL)),
            ],
        );
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

        $password = $screen->findOrFail('Password');
        self::assertSame('▎', $screen->textAt($password['column'] - 2, $password['row'], 1));
        self::assertNotSame('▎', $screen->textAt($password['column'] - 2, $screen->findOrFail('Username')['row'], 1));
    }

    #[Test]
    public function aFieldNamedByANumberIsFilledInByThatName(): void
    {
        $form = Form::new('People')->withField(Field::text('0', 'Name'))->withValues(['0' => 'Alice']);

        self::assertSame('Alice', $form->values()['0']);
    }

    #[Test]
    public function aSubmittedFormDrawsItsFieldsTheirProblemAndTheButtonUnderARule(): void
    {
        $form = Form::new('Sign up')
            ->withField(Field::text('name', 'Name', 'Your name')->withRequired())
            ->withField(Field::text('city', 'City', 'where you live'))
            ->withSubmit('Save', new TestMessage('save'))
            ->withSubmitAttempted();

        $expected = <<<'SCREEN'
            ╭ Sign up ─────────────────────────╮
            │                                  │
            │ ▎ Name *required  Your name      │
            │ ▎                                │
            │   Name is required               │
            │                                  │
            │   City  where you live           │
            │                                  │
            │                                  │
            │                                  │
            │                                  │
            ├──────── fill in what is required ┤
            │                         ╭──────╮ │
            │                         │ Save │ │
            │                         ╰──────╯ │
            ╰──────────────────────────────────╯
            SCREEN;

        self::assertSame($expected, WidgetHarness::for($form, 36, 16)->screen()->plainText());
    }

    #[Test]
    public function aFormWithoutAButtonKeepsAPaddedFrameAndAnUnlabelledFieldStartsWithWhatItHas(): void
    {
        $form = Form::new('Notes')
            ->withField(Field::text('title', 'Title'))
            ->withField(Field::text('note', '', 'unnamed')->withRequired())
            ->withField(Field::text('memo', '', 'just text'));

        $expected = <<<'SCREEN'
            ╭ Notes ───────────────────────────╮
            │                                  │
            │ ▎ Title                          │
            │ ▎                                │
            │                                  │
            │   *required  unnamed             │
            │                                  │
            │                                  │
            │   just text                      │
            │                                  │
            │                                  │
            │                                  │
            │                                  │
            │                                  │
            │                                  │
            ╰──────────────────────────────────╯
            SCREEN;

        self::assertSame($expected, WidgetHarness::for($form, 36, 16)->screen()->plainText());
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
            ->withField(Field::text('username', 'Username', 'The login name')->withRequired())
            ->withField(Field::secret('password', 'Password'))
            ->withField(Field::choice('site', 'Site', ['main' => 'Main', 'intranet' => 'Intranet', 'shop' => 'Shop']))
            ->withField(Field::flag('admin', 'Admin', 'Create the user with admin rights'))
            ->withFoot('❯ backend:user:create')
            ->withSubmit('Run', new TestMessage('run'));
    }
}
