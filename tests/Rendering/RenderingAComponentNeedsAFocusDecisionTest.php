<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Rendering;

use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\TextInput;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A tree of components drawn with nothing said about the keyboard is a frame no
 * terminal ever shows: every field draws unfocused and a popup belonging to the
 * focused one is absent, so a test asserting on it passes over the bug it was
 * written to catch. Nowhere is an answer; saying nothing is not.
 */
final class RenderingAComponentNeedsAFocusDecisionTest extends TestCase
{
    #[Test]
    public function drawingOneWithoutSayingWhereTheKeyboardIsIsRefused(): void
    {
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/focusOn/');

        $context->renderRoot($this->boundField(), new Frame(20, 3), new Rect(0, 0, 20, 3));
    }

    private function boundField(): Component
    {
        return Component::bind(
            'field',
            new TextInput(),
            static fn (object $model, TextInput $field): object => $model,
        );
    }
}
