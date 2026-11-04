<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
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

        self::assertSame(['toggle', 'delete'], self::actionsOf($harness));
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
    public function __construct(public TextInput $field, private ListView $list, public array $actions = [])
    {
    }

    public static function start(): self
    {
        return new self(TextInput::new(), ListView::fromStrings(['Buy milk', 'Call Ada']));
    }

    public function view(): Widget
    {
        return Layout::column(
            $this->bindProperty('field', $this->field),
            $this->bindProperty('list', $this->list)->withActions(new Keybindings([
                'toggle' => ['space'],
                'delete' => ['x', 'ctrl+x'],
            ])),
            Footer::new()->withLeft(FocusHints::new()->withLeadingKeys($this->applicationKeybindings(), [
                'archive' => 'archive',
                'quit' => Actions::QUIT,
            ])),
        );
    }

    protected function applicationKeybindings(): Keybindings
    {
        return parent::applicationKeybindings()->plus(new Keybindings(['archive' => ['a']]));
    }

    protected function onAction(string $action, KeyMsg $message, Rect $screen): UpdateResult
    {
        return \in_array($action, ['toggle', 'delete', 'archive'], true)
            ? new UpdateResult($this->cloneWith(actions: [...$this->actions, $action]))
            : parent::onAction($action, $message, $screen);
    }
}
