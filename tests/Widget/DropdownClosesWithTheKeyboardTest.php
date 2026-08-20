<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Dropdown;
use Elmphant\Tui\Widget\Select;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A list floating over the layout belongs to whatever holds the keyboard. Left
 * hanging once the keyboard has gone, it covers rows the reader is now working in
 * and nothing they press will shut it.
 */
final class DropdownClosesWithTheKeyboardTest extends TestCase
{
    #[Test]
    public function anOpenListGoesWhenTheKeyboardMovesToAnotherComponent(): void
    {
        $driven = ModelHarness::for(new PickerBesideAFieldApp(), 30, 12)->initialize();

        $driven->dispatch(new KeyMsg(' '));
        self::assertStringContainsString(
            'yaml',
            $driven->renderScreen()->getPlainText(),
            'precondition: the list is open',
        );

        $driven->dispatch(new KeyMsg(Key::Tab));

        self::assertStringNotContainsString(
            'yaml',
            $driven->renderScreen()->getPlainText(),
            'the keyboard is on the field now, so the list is not still hanging over it',
        );
    }
}

final readonly class PickerBesideAFieldApp extends App
{
    public function __construct(
        public ?Dropdown $picker = null,
        public TextInput $field = new TextInput(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind(
                'picker',
                $this->picker ?? Dropdown::new(Select::new(['json', 'yaml'])),
                static fn (self $app, Dropdown $picker): self => $app->cloneWith(picker: $picker),
            ),
            Component::bind(
                'field',
                $this->field,
                static fn (self $app, TextInput $field): self => $app->cloneWith(field: $field),
            ),
        );
    }
}
