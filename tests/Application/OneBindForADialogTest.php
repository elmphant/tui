<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Message\Dismissed;
use Elmphant\Tui\Message\Submitted;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Form\Field;
use Elmphant\Tui\Widget\Form\Form;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OneBindForADialogTest extends TestCase
{
    #[Test]
    public function theDialogHearsWhatItsFieldSubmitted(): void
    {
        $harness = ModelHarness::for(AskingApp::start(), 40, 10)->init();
        self::assertSame('dialog/0', $harness->focused()?->value, 'the field inside holds the keyboard');

        $harness->type('milk')->press('enter');

        $model = $harness->model();
        self::assertInstanceOf(AskingApp::class, $model);
        self::assertSame(['submitted milk'], $model->heard);
        self::assertNull($model->dialog);
    }

    #[Test]
    public function theDialogHearsThatItWasDismissed(): void
    {
        $harness = ModelHarness::for(AskingApp::start(), 40, 10)->init();

        $harness->type('milk')->press('escape');

        $model = $harness->model();
        self::assertInstanceOf(AskingApp::class, $model);
        self::assertSame(['dismissed'], $model->heard);
        self::assertNull($model->dialog);
    }

    #[Test]
    public function aFormInsideTheDialogOffersItsFieldsAsStops(): void
    {
        $form = Form::new('New card')->withField(Field::text('title', 'Title'))->withField(Field::text('note', 'Note'));
        $harness = ModelHarness::for(new AskingApp(Modal::new('', $form)), 50, 16)->init();

        $harness->type('milk')->press('tab')->type('two pints');

        $dialog = $harness->model()->dialog ?? null;
        self::assertInstanceOf(Modal::class, $dialog);
        $content = $dialog->content();
        self::assertInstanceOf(Form::class, $content);
        self::assertSame(['title' => 'milk', 'note' => 'two pints'], $content->values());
    }
}

final readonly class AskingApp extends App
{
    /**
     * @param list<string> $heard
     */
    public function __construct(public ?Modal $dialog, public array $heard = [])
    {
    }

    public static function start(): self
    {
        return new self(Modal::new('New card', TextInput::new()->withSubmit()));
    }

    public function view(): Widget
    {
        $page = Text::new('board');

        return $this->dialog === null ? $page : Overlay::new($page, $this->bind('dialog')
            ->on(Submitted::class, static fn (self $app, Submitted $submitted): self => new self(null, [...$app->heard, "submitted $submitted->value"]))
            ->on(Dismissed::class, static fn (self $app): self => new self(null, [...$app->heard, 'dismissed'])));
    }
}
