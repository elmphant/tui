<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Message\Submitted;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BindAndOnTest extends TestCase
{
    #[Test]
    public function aSubmittedFieldReachesItsHandlerWithTheValue(): void
    {
        $harness = ModelHarness::for(BindOnApp::start(), 40, 6)->type('Buy milk')->press('enter');

        $app = self::appOf($harness);
        self::assertSame(['Buy milk'], array_map(static fn ($item) => $item->title(), $app->list->items()));
        self::assertSame('', $app->field->value(), 'the handler cleared the field and its app won');
    }

    #[Test]
    public function typingIsStoredWithNoHandlerInvolved(): void
    {
        $harness = ModelHarness::for(BindOnApp::start(), 40, 6)->type('Buy');

        self::assertSame('Buy', self::appOf($harness)->field->value());
    }

    #[Test]
    public function theHandlerSeesTheAppWithTheWidgetAlreadyStored(): void
    {
        $harness = ModelHarness::for(BindOnApp::start(), 40, 6)->type('Buy milk')->press('enter');

        self::assertSame('Buy milk', self::appOf($harness)->seenInField);
    }

    private static function appOf(ModelHarness $harness): BindOnApp
    {
        $app = $harness->model();
        self::assertInstanceOf(BindOnApp::class, $app);

        return $app;
    }
}

final readonly class BindOnApp extends App
{
    public function __construct(public TextInput $field, public ListView $list, public ?string $seenInField = null)
    {
    }

    public static function start(): self
    {
        return new self(TextInput::new()->withSubmit(), ListView::new([]));
    }

    public function view(): Widget
    {
        return Layout::column(
            $this->bind('field')->on(Submitted::class, static fn (self $app, Submitted $submitted): self => $app->added($submitted->value)),
            $this->bind('list'),
        );
    }

    private function added(string $name): self
    {
        return $this->cloneWith(
            field: $this->field->withValue(''),
            list: $this->list->withAddedItem(new SimpleListItem($name)),
            seenInField: $this->field->value(),
        );
    }
}
