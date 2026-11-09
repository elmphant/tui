<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\InputContext;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\Pressed;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Form\Field;
use Elmphant\Tui\Widget\Form\Form;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AFormHoldsBackItsSubmitTest extends TestCase
{
    #[Test]
    public function pressingSubmitWithAProblemShowsItAndSendsTheKeyboardThere(): void
    {
        $harness = ModelHarness::for(SignUpApp::new(), width: 60, height: 20)->init();

        $harness->press('tab', 'tab', 'enter');

        self::assertSame(0, $harness->model()->submitted);
        self::assertNotNull($harness->screen()->find('Name is required'));
        self::assertEquals($harness->model()->form->stopOf('name', 'form'), $harness->focused());
    }

    #[Test]
    public function aClickOnSubmitWithAProblemIsHeldBackToo(): void
    {
        $harness = ModelHarness::for(SignUpApp::new(), width: 60, height: 20)->init();

        $harness->click('Send', offset: 1);

        self::assertSame(0, $harness->model()->submitted);
        self::assertNotNull($harness->screen()->find('Name is required'));
    }

    #[Test]
    public function enterInTheLastFieldWithAProblemDoesTheSame(): void
    {
        $harness = ModelHarness::for(SignUpApp::new(), width: 60, height: 20)->init();

        $harness->press('tab')->type('sam@example.com')->press('enter');

        self::assertSame(0, $harness->model()->submitted);
        self::assertNotNull($harness->screen()->find('Name is required'));
        self::assertEquals($harness->model()->form->stopOf('name', 'form'), $harness->focused());
    }

    #[Test]
    public function aFormWithoutProblemsIsSubmitted(): void
    {
        $harness = ModelHarness::for(SignUpApp::new(), width: 60, height: 20)->init();

        $harness->type('Sam')->press('tab', 'tab', 'enter');

        self::assertSame(1, $harness->model()->submitted);
    }

    #[Test]
    public function theSubmitMessageWaitsUntilTheFormHasNoProblems(): void
    {
        $harness = ModelHarness::for(SignUpApp::sending(), width: 60, height: 20)->init();

        $harness->press('tab', 'tab', 'enter');
        self::assertSame(0, $harness->model()->signedUp, 'from the button');
        $harness->press('tab', 'enter');
        self::assertSame(0, $harness->model()->signedUp, 'from Enter in the last field');

        $harness->type('Sam')->press('tab', 'tab', 'enter');
        self::assertSame(1, $harness->model()->signedUp);
    }
}

final readonly class SignedUp implements Message
{
}

final readonly class SignUpApp extends App
{
    public function __construct(public Form $form, public int $submitted = 0, public int $signedUp = 0)
    {
    }

    public static function new(): self
    {
        return new self(self::form()->withSubmit('Send'));
    }

    public static function sending(): self
    {
        return new self(self::form()->withSubmit('Send', new SignedUp()));
    }

    public function view(): Widget
    {
        return $this->bind('form')->on(Pressed::class, static fn (self $app): self => $app->cloneWith(submitted: $app->submitted + 1));
    }

    protected function onMessage(Message $message, InputContext $input): self
    {
        return $message instanceof SignedUp ? $this->cloneWith(signedUp: $this->signedUp + 1) : $this;
    }

    private static function form(): Form
    {
        return Form::new('Sign up')
            ->withField(Field::text('name', 'Name')->withRequired())
            ->withField(Field::text('email', 'Email'));
    }
}
