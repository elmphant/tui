<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Widget\Field\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComponentWithWidgetTest extends TestCase
{
    /**
     * Routing hands the keyboard to the widget the tree named, keeping the id.
     */
    #[Test]
    public function replacingTheWidgetKeepsTheIdItIsBoundUnder(): void
    {
        $component = Component::bind(
            'field',
            TextInput::new(),
            static fn (object $app, TextInput $field): object => $app,
        );

        $focused = $component->withWidget(TextInput::new()->withFocus(true));

        self::assertEquals(ComponentId::named('field'), $focused->id);
        self::assertTrue($focused->widget->isFocused());
    }
}
