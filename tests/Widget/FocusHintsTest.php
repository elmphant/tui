<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ScreenBuffer;
use Elmphant\Tui\Widget\Bar\FocusHints;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Bar\Header;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{Footer}>
     */
    public static function footerSlots(): iterable
    {
        $hints = FocusHints::new()->withLeadingHints(['q' => 'quit']);

        yield 'left' => [Footer::new()->withLeft($hints)];
        yield 'centre' => [Footer::new()->withCenter($hints)];
        yield 'right' => [Footer::new()->withRight($hints)];
    }

    #[Test]
    #[DataProvider('footerSlots')]
    public function aFooterSlotShowsTheFocusedKeysBesideTheHostsOwn(Footer $footer): void
    {
        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->provideHints(['space' => 'pick']);

        $frame = new Frame(60, 1);
        $footer->render($frame, Rect::fromSize(60, 1), $pass->context);

        self::assertStringContainsString(' q  quit   space  pick', ScreenBuffer::fromFrame($frame)->lineAt(0));
    }

    #[Test]
    public function aHeaderTitleStepsAsideForTheFocusedKeysInTheCentre(): void
    {
        $pass = RenderPass::new(Theme::dark());
        $pass->begin(0.0);
        $pass->focus->provideHints(['space' => 'pick']);

        $frame = new Frame(40, 1);
        Header::new('title')->withCenter(FocusHints::new()->withLeadingHints(['q' => 'quit']))
            ->render($frame, Rect::fromSize(40, 1), $pass->context);

        $line = ScreenBuffer::fromFrame($frame)->lineAt(0);
        self::assertStringContainsString('title', $line);
        self::assertStringContainsString(' q  quit   space  pick', $line);
    }
}
