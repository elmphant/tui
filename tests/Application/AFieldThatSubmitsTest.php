<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Message\Submitted;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AFieldThatSubmitsTest extends TestCase
{
    #[Test]
    public function theBindClosureTakesTheValueWhenEnterSubmitsTheField(): void
    {
        $harness = ModelHarness::for(new SubmittingFieldApp(TextInput::new()->withSubmit()), 40, 4)
            ->type('Buy milk')
            ->press('enter')
            ->type('Call Ada')
            ->press('enter');

        $app = $harness->model();
        self::assertInstanceOf(SubmittingFieldApp::class, $app);
        self::assertSame(['Buy milk', 'Call Ada'], $app->added);
    }

    #[Test]
    public function aFieldNotToldToSubmitDoesNotCommitOnEnter(): void
    {
        $harness = ModelHarness::for(new SubmittingFieldApp(TextInput::new()), 40, 4)
            ->type('Buy milk')
            ->press('enter');

        $app = $harness->model();
        self::assertInstanceOf(SubmittingFieldApp::class, $app);
        self::assertSame([], $app->added, 'Enter stays free for what encloses the field, as a form moves on with it');
    }
}

final readonly class SubmittingFieldApp extends App
{
    /**
     * @param list<string> $added
     */
    public function __construct(private TextInput $field, public array $added = [])
    {
    }

    public function view(): Widget
    {
        return Component::bind(
            'field',
            $this->field,
            static fn (self $app, TextInput $field, Message $message): self => $message instanceof Submitted
                ? $app->cloneWith(field: $field->withValue(''), added: [...$app->added, $message->value])
                : $app->cloneWith(field: $field),
        );
    }
}
