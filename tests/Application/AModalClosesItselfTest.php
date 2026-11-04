<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Popup\Overlay;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AModalClosesItselfTest extends TestCase
{
    #[Test]
    public function escapeClosesTheModalAndTheBindClosureDropsIt(): void
    {
        $harness = ModelHarness::for(ClosingModalApp::start(), 60, 12);
        self::assertStringContainsString('New card', $harness->screen()->plainText());

        $harness->press('escape');

        self::assertStringNotContainsString('New card', $harness->screen()->plainText());
        self::assertSame('list', $harness->focused()?->value, 'the keyboard goes back behind it');
    }

    #[Test]
    public function aClickOutsideClosesItToo(): void
    {
        $harness = ModelHarness::for(ClosingModalApp::start(), 60, 12)->clickAt(0, 0);

        self::assertStringNotContainsString('New card', $harness->screen()->plainText());
    }

    #[Test]
    public function aClosedModalLeftInTheTreeDrawsNothingAndClaimsNoInput(): void
    {
        $harness = ModelHarness::for(ClosingModalApp::start(keepClosed: true), 60, 12)->press('escape');

        self::assertStringNotContainsString('New card', $harness->screen()->plainText());
        self::assertSame('list', $harness->focused()?->value);
    }
}

final readonly class ClosingModalApp extends App
{
    public function __construct(private ListView $list, private ?Modal $modal, private bool $keepClosed)
    {
    }

    public static function start(bool $keepClosed = false): self
    {
        return new self(ListView::fromStrings(['Buy milk']), Modal::new('New card', TextInput::new()), $keepClosed);
    }

    public function view(): Widget
    {
        $page = Layout::column($this->bind('list'));

        return $this->modal === null ? $page : Overlay::new($page, Component::bind(
            'modal',
            $this->modal,
            static fn (self $app, Modal $modal): self => $modal->isOpen() || $app->keepClosed
                ? $app->cloneWith(modal: $modal)
                : $app->cloneWith(modal: null),
        ));
    }
}
