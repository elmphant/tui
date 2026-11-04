<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Message\Pressed;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Form\Field;
use Elmphant\Tui\Widget\Form\Form;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AFormSubmitsWithoutAMessageTest extends TestCase
{
    #[Test]
    public function enterInTheLastFieldReachesTheFormsHandler(): void
    {
        $harness = ModelHarness::for(SubmittingApp::start(), 50, 14)->init();

        $harness->type('milk')->press('enter')->type('two')->press('enter');

        $model = $harness->model();
        self::assertInstanceOf(SubmittingApp::class, $model);
        self::assertSame([['title' => 'milk', 'note' => 'two']], $model->sent);
    }

    #[Test]
    public function theSubmitButtonReachesTheSameHandler(): void
    {
        $harness = ModelHarness::for(SubmittingApp::start(), 50, 14)->init();

        $harness->type('milk')->click('Save');

        $model = $harness->model();
        self::assertInstanceOf(SubmittingApp::class, $model);
        self::assertSame([['title' => 'milk', 'note' => '']], $model->sent);
    }
}

final readonly class SubmittingApp extends App
{
    /**
     * @param list<array<string, mixed>> $sent
     */
    public function __construct(public Form $form, public array $sent = [])
    {
    }

    public static function start(): self
    {
        return new self(Form::new('Card')->withField(Field::text('title', 'Title'))->withField(Field::text('note', 'Note'))->withSubmit('Save'));
    }

    public function view(): Widget
    {
        return $this->bind('form')->on(Pressed::class, static fn (self $app): self => new self($app->form, [...$app->sent, $app->form->values()]));
    }
}
