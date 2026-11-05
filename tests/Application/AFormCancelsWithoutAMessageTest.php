<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Message\Dismissed;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Form\Field;
use Elmphant\Tui\Widget\Form\Form;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AFormCancelsWithoutAMessageTest extends TestCase
{
    #[Test]
    public function escapeReachesTheFormsDismissedHandler(): void
    {
        $harness = ModelHarness::for(CancellingApp::start(), 50, 14)->init();

        $harness->type('milk')->press('esc');

        $model = $harness->model();
        self::assertInstanceOf(CancellingApp::class, $model);
        self::assertSame(1, $model->cancelled);
    }
}

final readonly class CancellingApp extends App
{
    public function __construct(public Form $form, public int $cancelled = 0)
    {
    }

    public static function start(): self
    {
        return new self(Form::new('Card')->withField(Field::text('title', 'Title'))->withCancel());
    }

    public function view(): Widget
    {
        return $this->bind('form')->on(Dismissed::class, static fn (self $app): self => new self($app->form, $app->cancelled + 1));
    }
}
