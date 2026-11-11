<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Popup\Modal;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StopsFollowTheScreenOrderTest extends TestCase
{
    #[Test]
    public function aBoundFieldAboveAnUnboundOneInAPaneIsTheFirstStop(): void
    {
        $harness = ModelHarness::for(PaneOfMixedFieldsApp::new(), 40, 8)->init();

        self::assertSame(['first', 'pane/1'], array_map(static fn ($id): string => $id->value, $harness->model()->ringIds()));
    }

    #[Test]
    public function anOpenModalBoundInsideAPaneHoldsTheOnlyStops(): void
    {
        $harness = ModelHarness::for(PaneWithADialogApp::new(), 40, 12)->init();

        self::assertSame(['dialog/0'], array_map(static fn ($id): string => $id->value, $harness->model()->ringIds()));
    }
}

final readonly class PaneWithADialogApp extends App
{
    public function __construct(private Modal $dialog, private Panel $pane)
    {
    }

    public static function new(): self
    {
        return new self(Modal::new('Name', TextInput::new()), new Panel('Fields'));
    }

    public function view(): Widget
    {
        return Component::bind(
            'pane',
            $this->pane->withChildren([TextInput::new(), $this->bind('dialog')]),
            static fn (self $app, Panel $pane): self => $app,
        );
    }
}

final readonly class PaneOfMixedFieldsApp extends App
{
    public function __construct(private TextInput $first, private Panel $pane)
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
            $this->pane->withChildren([$this->bind('first'), TextInput::new()]),
            static fn (self $app, Panel $pane): self => $app,
        );
    }
}
