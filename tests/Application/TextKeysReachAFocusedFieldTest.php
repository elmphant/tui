<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Listing\SimpleListItem;
use Elmphant\Tui\Widget\Popup\Dropdown;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextKeysReachAFocusedFieldTest extends TestCase
{
    #[Test]
    public function lettersTypedIntoAFieldAreTextNotShortcuts(): void
    {
        $harness = ModelHarness::for(ShortcutsAroundAFieldApp::new())->initialize()->type('need');

        self::assertSame('need', $harness->model()->field->value());
        self::assertSame([], $harness->model()->shortcuts);
    }

    #[Test]
    public function lettersFilterAnOpenDropdownRatherThanReachTheApplication(): void
    {
        $dropdown = Dropdown::new(FilterableList::new([new SimpleListItem('need'), new SimpleListItem('other')]))->withOpen();
        $harness = ModelHarness::for(new ShortcutsAroundADropdownApp($dropdown))->initialize()->type('ne');

        $content = $harness->model()->dropdown->content();
        self::assertInstanceOf(FilterableList::class, $content);
        self::assertSame('ne', $content->query());
        self::assertSame([], $harness->model()->shortcuts);
    }

    #[Test]
    public function lettersAreShortcutsWhileAListHoldsTheKeyboard(): void
    {
        $harness = ModelHarness::for(ShortcutsAroundAFieldApp::new())->initialize()->press('tab')->type('ne');

        self::assertSame(['n', 'edit'], $harness->model()->shortcuts);
    }

    #[Test]
    public function aModifiedKeyStaysTheApplicationsWhileAFieldHoldsTheKeyboard(): void
    {
        $harness = ModelHarness::for(ShortcutsAroundAFieldApp::new())->initialize()->press('ctrl+e');

        self::assertSame(['edit'], $harness->model()->shortcuts);
    }
}

final readonly class ShortcutsAroundADropdownApp extends App
{
    /**
     * @param Dropdown<FilterableList> $dropdown
     * @param list<string>             $shortcuts
     */
    public function __construct(public Dropdown $dropdown, public array $shortcuts = [])
    {
    }

    public function view(): Widget
    {
        return $this->bindProperty('dropdown', $this->dropdown);
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return \is_string($message->key)
            ? new UpdateResult($this->cloneWith(shortcuts: [...$this->shortcuts, $message->key]))
            : UpdateResult::ignored($this);
    }
}

final readonly class ShortcutsAroundAFieldApp extends App
{
    /**
     * @param list<string> $shortcuts
     */
    public function __construct(
        public TextInput $field,
        public ListView $list,
        public array $shortcuts = [],
    ) {
    }

    public static function new(): self
    {
        return new self(TextInput::new(), ListView::new([new SimpleListItem('a')]));
    }

    public function view(): Widget
    {
        return Layout::vertical(
            $this->bindProperty('field', $this->field),
            $this->bindProperty('list', $this->list),
        );
    }

    protected function applicationKeybindings(): Keybindings
    {
        return parent::applicationKeybindings()->merge(new Keybindings(['edit' => ['e', 'ctrl+e']]));
    }

    protected function onAction(string $action, KeyMsg $message): UpdateResult
    {
        return $action === 'edit'
            ? new UpdateResult($this->cloneWith(shortcuts: [...$this->shortcuts, 'edit']))
            : parent::onAction($action, $message);
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return \in_array($message->key, ['n', 'd'], true)
            ? new UpdateResult($this->cloneWith(shortcuts: [...$this->shortcuts, $message->key]))
            : UpdateResult::ignored($this);
    }
}
