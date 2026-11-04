<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Closure;
use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\AppActions;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Bar\FocusHints;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComponentActionsTest extends TestCase
{
    #[Test]
    public function anActionOnAComponentFiresWhileItHoldsTheKeyboard(): void
    {
        $harness = ModelHarness::for(ScopedActionsApp::start(), 60, 8)->press('tab', 'space', 'x');

        self::assertSame(['list:toggle', 'list:delete'], self::actionsOf($harness), 'the handlers of the component hear them');
    }

    #[Test]
    public function eachOfTwoAlikeComponentsHearsItsOwnActions(): void
    {
        $harness = ModelHarness::for(ScopedActionsApp::start(), 60, 8)->press('tab', 'tab', 'space');

        self::assertSame(['other:toggle'], self::actionsOf($harness));
    }

    #[Test]
    public function anActionOnAComponentDoesNotFireWhileTheKeyboardIsElsewhere(): void
    {
        $harness = ModelHarness::for(ScopedActionsApp::start(), 60, 8)->press('ctrl+x');

        self::assertSame([], self::actionsOf($harness), 'ctrl+x is no text, so only the scope keeps it from the field');
    }

    #[Test]
    public function aFieldStillTypesTheKeysAnotherComponentBinds(): void
    {
        $harness = ModelHarness::for(ScopedActionsApp::start(), 60, 8)->type('a x');

        $app = $harness->model();
        self::assertInstanceOf(ScopedActionsApp::class, $app);
        self::assertSame('a x', $app->field->value());
        self::assertSame([], $app->actions);
    }

    #[Test]
    public function theFooterNamesAComponentsActionsOnlyWhileItHoldsTheKeyboard(): void
    {
        $harness = ModelHarness::for(ScopedActionsApp::start(), 60, 8);
        self::assertStringNotContainsString('toggle', $harness->screen()->plainText());

        self::assertStringContainsString('Space  toggle', $harness->press('tab')->screen()->plainText());
    }

    #[Test]
    public function actionsWithOneDescriptionShareOneHint(): void
    {
        $harness = ModelHarness::for(ScopedActionsApp::start(), 120, 8)->press('tab');

        self::assertStringContainsString('←/→  column', $harness->screen()->plainText());
    }

    #[Test]
    public function aLeadingHintWhoseKeyTheFieldTakesAsTextIsLeftOut(): void
    {
        $harness = ModelHarness::for(ScopedActionsApp::start(), 60, 8);
        self::assertStringNotContainsString('archive', $harness->screen()->plainText(), 'the field takes a as text');
        self::assertStringContainsString('Ctrl+Q  quit', $harness->screen()->plainText());

        self::assertStringContainsString('a  archive', $harness->press('tab')->screen()->plainText());
    }

    /**
     * @return list<string>
     */
    private static function actionsOf(ModelHarness $harness): array
    {
        $app = $harness->model();
        self::assertInstanceOf(ScopedActionsApp::class, $app);

        return $app->actions;
    }
}

final readonly class ScopedActionsApp extends App
{
    /**
     * @param list<string> $actions
     */
    public function __construct(public TextInput $field, private ListView $list, private ListView $other, public array $actions = [])
    {
    }

    public static function start(): self
    {
        return new self(TextInput::new(), ListView::fromStrings(['Buy milk', 'Call Ada']), ListView::fromStrings(['Pay rent']));
    }

    public function view(): Widget
    {
        return Layout::column(...[
            $this->bind('field'),
            ...array_map(fn (string $name): Widget => $this->bind($name)
                ->withAction('toggle', ['space'], self::recording("$name:toggle"))
                ->withAction('delete', ['x', 'ctrl+x'], self::recording("$name:delete"))
                ->withAction('left', ['left'], self::recording("$name:left"), 'column')
                ->withAction('right', ['right'], self::recording("$name:right"), 'column'), ['list', 'other']),
            Footer::new()->withLeft(FocusHints::new()),
        ]);
    }

    protected function actions(): AppActions
    {
        return parent::actions()->with('archive', ['a'], self::recording('archive'), 'archive');
    }

    private static function recording(string $entry): Closure
    {
        return static fn (self $app): self => $app->cloneWith(actions: [...$app->actions, $entry]);
    }
}
