<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\FocusState;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FocusRoutingTest extends TestCase
{
    #[Test]
    public function keysGoToTheComponentTheTreeFocusedNotTheFirstOneFlagged(): void
    {
        $app = new RoutedFocusApp(
            TextInput::new(),
            TextInput::new(),
        );

        $typed = $app->update(
            new KeyMsg('x'),
            new Rect(0, 0, 40, 6),
            FocusState::on(ComponentId::named('second')),
        );

        self::assertInstanceOf(RoutedFocusApp::class, $typed->model);
        self::assertSame('', $typed->model->first->value(), 'The first field must not receive a key it does not have');
        self::assertSame('x', $typed->model->second->value(), 'The focused component receives it');
    }

    #[Test]
    public function theNamedComponentReceivesKeysWithoutCarryingTheFlagItself(): void
    {
        $app = new RoutedFocusApp(TextInput::new(), TextInput::new());

        $typed = $app->update(
            new KeyMsg('x'),
            new Rect(0, 0, 40, 6),
            FocusState::on(ComponentId::named('second')),
        );

        self::assertInstanceOf(RoutedFocusApp::class, $typed->model);
        self::assertSame('', $typed->model->first->value());
        self::assertSame('x', $typed->model->second->value());
    }
}

final readonly class RoutedFocusApp extends App
{
    public function __construct(
        public TextInput $first,
        public TextInput $second,
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
