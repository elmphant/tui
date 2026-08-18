<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\RenderContext;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\FocusHints;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusHintsTest extends TestCase
{
    #[Test]
    public function drawsTheKeysWhateverHoldsTheKeyboardAnswers(): void
    {
        $context = new RenderContext(Theme::dark());
        $context->beginFrame(0.0);
        $context->provideFocusedHints(['space' => 'pick']);

        $frame = new Frame(40, 1);
        FocusHints::new()->render($frame, Rect::fromSize(40, 1), $context);

        $text = implode('', array_slice($frame->characters, 0, 30));
        self::assertStringContainsString('space', $text);
        self::assertStringContainsString('pick', $text);
    }
}
