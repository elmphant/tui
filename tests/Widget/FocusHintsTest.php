<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Bar\FocusHints;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusHintsTest extends TestCase
{
    #[Test]
    public function drawsTheKeysWhateverHoldsTheKeyboardAnswers(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->provideHints(['space' => 'pick']);

        $frame = new Frame(40, 1);
        FocusHints::new()->render($frame, Rect::fromSize(40, 1), $pass->context);

        $text = implode('', array_slice($frame->characters, 0, 30));
        self::assertStringContainsString('space', $text);
        self::assertStringContainsString('pick', $text);
    }

    #[Test]
    public function theHostsOwnKeysComeFirst(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->provideHints(['space' => 'pick']);

        $frame = new Frame(40, 1);
        FocusHints::new()->withLeadingHints(['q' => 'quit'])->render($frame, Rect::fromSize(40, 1), $pass->context);

        self::assertSame(' q  quit   space  pick', rtrim(implode('', array_slice($frame->characters, 0, 40))));
    }
}
