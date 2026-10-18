<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\SplitState;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Structure\Split;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Table;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SplitFocusTest extends TestCase
{
    #[Test]
    public function onlyThePaneHoldingTheKeyboardOfABoundSplitFillsItsCursorRow(): void
    {
        $app = ModelHarness::for(new BoundSplitApp(self::table('LEFT'), self::table('RIGHT')), width: 60, height: 5);
        $app->press('tab');
        self::assertSame('right', $app->focused()?->value);

        $screen = $app->renderScreen();
        $left = $screen->locate('LEFT one');
        $right = $screen->locate('RIGHT one');

        self::assertNotNull($screen->styleAt($right['row'], $right['column'])->background);
        self::assertNull($screen->styleAt($left['row'], $left['column'])->background);
    }

    private static function table(string $name): Table
    {
        return Table::fromCells([Column::new($name, Constraint::flex())], [["{$name} one"], ["{$name} two"]]);
    }
}

final readonly class BoundSplitApp extends App
{
    public function __construct(public Table $left, public Table $right, public SplitState $state = new SplitState([500, 500]))
    {
    }

    public function view(): Widget
    {
        return Component::bind(
            'split',
            Split::row(
                $this->state,
                Component::bind('left', $this->left, static fn (self $app, Table $left): self => new self($left, $app->right, $app->state)),
                Component::bind('right', $this->right, static fn (self $app, Table $right): self => new self($app->left, $right, $app->state)),
            ),
            static fn (self $app, Split $split): self => new self($app->left, $app->right, $split->state()),
        );
    }
}
