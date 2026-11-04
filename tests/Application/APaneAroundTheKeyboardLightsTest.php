<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd\ClipboardCmd;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class APaneAroundTheKeyboardLightsTest extends TestCase
{
    #[Test]
    public function aPaneThatIsNotBoundLightsWhileAListInsideItHoldsTheKeyboard(): void
    {
        $screen = ModelHarness::for(new UnboundPanesApp(ListView::fromStrings(['one']), ListView::fromStrings(['two'])), 30, 4)->screen();
        $lit = Theme::dark()->borderStyleFor(true)->foreground;

        self::assertEquals($lit, $screen->styleAt(0, 0)->foreground, 'the pane around the focused list');
        self::assertNotEquals($lit, $screen->styleAt(15, 0)->foreground, 'and not the other one');
    }

    #[Test]
    public function aDragTheListInsideAnsweredCopiesNoText(): void
    {
        $harness = ModelHarness::for(new KeptPanesApp(ListView::fromStrings(['one']), ListView::fromStrings(['two'])), 30, 4)
            ->drag(1, 1, 16, 1);

        self::assertSame([], $harness->issued(ClipboardCmd::class));
    }
}

final readonly class UnboundPanesApp extends App
{
    public function __construct(private ListView $a, private ListView $b)
    {
    }

    public function view(): Widget
    {
        return Layout::row(
            Panel::new('A')->add($this->bind('a')),
            Panel::new('B')->add($this->bind('b')),
        );
    }
}

final readonly class KeptPanesApp extends App
{
    public function __construct(private ListView $a, private ListView $b, private Panel $paneA = new Panel('A'), private Panel $paneB = new Panel('B'))
    {
    }

    public function view(): Widget
    {
        return Layout::row(
            Component::bind('paneA', $this->paneA->withChildren([$this->bind('a')]), static fn (self $app, Panel $pane): self => $app->cloneWith(paneA: $pane)),
            Component::bind('paneB', $this->paneB->withChildren([$this->bind('b')]), static fn (self $app, Panel $pane): self => $app->cloneWith(paneB: $pane)),
        );
    }
}
