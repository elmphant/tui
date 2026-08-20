<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Drawing a tree with nobody at the keyboard is what a test of layout or wording
 * wants, and it has to be sayable in one breath — otherwise every such test either
 * spells the decision out or quietly skips it and asserts on a frame no terminal
 * shows.
 */
final class AnUnfocusedContextDrawsAComponentTest extends TestCase
{
    #[Test]
    public function sayingNobodyHoldsTheKeyboardIsEnoughToDrawWith(): void
    {
        $context = RenderContext::unfocused(Theme::dark());
        $context->beginFrame(0.0);

        $frame = new Frame(20, 3);
        $context->renderRoot($this->boundField(), $frame, new Rect(0, 0, 20, 3));

        self::assertNotSame('', implode('', $frame->characters));
    }

    private function boundField(): Component
    {
        return Component::bind(
            'field',
            TextInput::new()->withValue('typed'),
            static fn (object $model, TextInput $field): object => $model,
        );
    }
}
