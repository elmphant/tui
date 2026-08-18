<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Testing;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HarnessFocusRoutingTest extends TestCase
{
    #[Test]
    public function aTypedCharacterReachesTheComponentHoldingTheKeyboard(): void
    {
        $harness = ModelHarness::for(new TypedFieldsApp(), 40, 2)->dispatch(new KeyMsg('x'));

        self::assertSame('x', $harness->model()->left->value());
    }
}

final readonly class TypedFieldsApp extends App
{
    public function __construct(
        public TextInput $left = new TextInput(),
        public TextInput $right = new TextInput(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('left', $this->left, static fn (self $app, TextInput $field): self => $app->cloneWith(left: $field)),
            Component::bind('right', $this->right, static fn (self $app, TextInput $field): self => $app->cloneWith(right: $field)),
        );
    }
}
