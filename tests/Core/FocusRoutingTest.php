<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\App;
use Elmphant\Tui\Core\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Layout\Layout;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\TextInput;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusRoutingTest extends TestCase
{
    /**
     * Two widgets flagged focused: the keys must follow the tree, not the order.
     */
    #[Test]
    public function keysGoToTheComponentTheTreeFocusedNotTheFirstOneFlagged(): void
    {
        $app = new RoutedFocusApp(
            TextInput::new()->withFocus(true),
            TextInput::new()->withFocus(true),
        );

        $typed = $app->update(
            new KeyMsg('x'),
            new Rect(0, 0, 40, 6),
            ComponentId::named('second'),
        );

        self::assertInstanceOf(RoutedFocusApp::class, $typed->model);
        self::assertSame('', $typed->model->first->value(), 'The first field must not receive a key it does not have');
        self::assertSame('x', $typed->model->second->value(), 'The focused component receives it');
    }

    #[Test]
    public function theNamedComponentReceivesKeysWithoutCarryingTheFlagItself(): void
    {
        $app = new RoutedFocusApp();

        $typed = $app->update(
            new KeyMsg('x'),
            new Rect(0, 0, 40, 6),
            ComponentId::named('second'),
        );

        self::assertInstanceOf(RoutedFocusApp::class, $typed->model);
        self::assertSame('', $typed->model->first->value());
        self::assertSame('x', $typed->model->second->value());
    }
}

final readonly class RoutedFocusApp extends App
{
    public function __construct(
        public TextInput $first = new TextInput(),
        public TextInput $second = new TextInput(),
    ) {
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind('first', $this->first, static fn (self $app, TextInput $w): self => $app->cloneWith(first: $w)),
            Component::bind('second', $this->second, static fn (self $app, TextInput $w): self => $app->cloneWith(second: $w)),
        );
    }
}
