<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Widget\Focusable;
use PHPUnit\Framework\Attributes\Test;

trait FocusableBehaviorTrait
{
    #[Test]
    public function focusable_contract(): void
    {
        $widget = $this->createFocusable();

        // Ensure default state (usually false, but implementation specific)
        // We only test the contract of withFocus returning a new instance with the correct state.

        $focused = $widget->withFocus(true);
        self::assertNotSame($widget, $focused, 'withFocus(true) must return a new instance (immutability)');
        self::assertTrue($focused->isFocused());

        $unfocused = $focused->withFocus(false);
        self::assertNotSame($focused, $unfocused, 'withFocus(false) must return a new instance (immutability)');
        self::assertFalse($unfocused->isFocused());
    }

    abstract protected function createFocusable(): Focusable;
}
