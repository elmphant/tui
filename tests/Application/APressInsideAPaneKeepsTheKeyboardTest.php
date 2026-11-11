<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class APressInsideAPaneKeepsTheKeyboardTest extends TestCase
{
    #[Test]
    public function aPressOnTheBorderOfAPaneLeavesTheKeyboardOnTheBoundFieldInIt(): void
    {
        $harness = ModelHarness::for(PaneAroundABoundFieldApp::new(), 40, 8)->init();
        $before = $harness->focused()?->value;

        $harness->clickAt(10, 0);

        self::assertSame('name', $before);
        self::assertSame('name', $harness->focused()?->value);
    }
}

final readonly class PaneAroundABoundFieldApp extends App
{
    public function __construct(private TextInput $name, private Panel $pane)
    {
    }

    public static function new(): self
    {
        return new self(TextInput::new(), new Panel('Fields'));
    }

    public function view(): Widget
    {
        return Component::bind(
            'pane',
            $this->pane->withChildren([$this->bind('name'), TextInput::new()]),
            static fn (self $app, Panel $pane): self => $app,
        );
    }
}
