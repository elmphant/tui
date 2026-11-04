<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\Cmd\QuitCmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\Presets\VimMode;
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
        $harness = ModelHarness::for(ShortcutsAroundAFieldApp::new())->init()->type('need');

        self::assertSame('need', $harness->model()->field->value());
        self::assertSame([], $harness->model()->shortcuts);
    }

    #[Test]
    public function lettersFilterAnOpenDropdownRatherThanReachTheApplication(): void
    {
        $dropdown = Dropdown::new(FilterableList::new([new SimpleListItem('need'), new SimpleListItem('other')]))->withOpen();
        $harness = ModelHarness::for(new ShortcutsAroundADropdownApp($dropdown))->init()->type('ne');

        $content = $harness->model()->dropdown->content();
        self::assertInstanceOf(FilterableList::class, $content);
        self::assertSame('ne', $content->query());
        self::assertSame([], $harness->model()->shortcuts);
    }

    #[Test]
    public function aGlobalLetterBindingIsTypedIntoAFilterOnceSlashOpensIt(): void
    {
        $finder = FilterableList::new([new SimpleListItem('jekyll'), new SimpleListItem('kafka')]);
        $harness = ModelHarness::for(new VimFinderApp($finder))->init()->press('/')->type('jek');

        self::assertSame('jek', $harness->model()->finder->query());
    }

    #[Test]
    public function lettersAreShortcutsWhileAListHoldsTheKeyboard(): void
    {
        $harness = ModelHarness::for(ShortcutsAroundAFieldApp::new())->init()->press('tab')->type('ne');

        self::assertSame(['n', 'edit'], $harness->model()->shortcuts);
    }

    #[Test]
    public function aQuitLetterQuitsWhileAListHoldsTheKeyboard(): void
    {
        $harness = ModelHarness::for(ShortcutsAroundAFieldApp::new())->init()->press('tab', 'q');

        self::assertInstanceOf(QuitCmd::class, $harness->lastCommand());
    }

    #[Test]
    public function aQuitLetterIsTypedWhileAFieldHoldsTheKeyboard(): void
    {
        $harness = ModelHarness::for(ShortcutsAroundAFieldApp::new())->init()->type('q');

        self::assertSame('q', $harness->model()->field->value());
    }

    #[Test]
    public function aModifiedKeyStaysTheApplicationsWhileAFieldHoldsTheKeyboard(): void
    {
        $harness = ModelHarness::for(ShortcutsAroundAFieldApp::new())->init()->press('ctrl+e');

        self::assertSame(['edit'], $harness->model()->shortcuts);
    }
}

final readonly class VimFinderApp extends App
{
    public function __construct(public FilterableList $finder)
    {
    }

    public function view(): Widget
    {
        return $this->bindProperty('finder', $this->finder);
    }

    protected function globalKeybindings(): Keybindings
    {
        return VimMode::keybindings();
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
        return new Keybindings([
            Actions::QUIT => ['ctrl+q', 'ctrl+c', 'q'],
            'edit' => ['e', 'ctrl+e'],
        ]);
    }

    protected function onAction(string $action, KeyMsg $message, Rect $screen): UpdateResult
    {
        return $action === 'edit'
            ? new UpdateResult($this->cloneWith(shortcuts: [...$this->shortcuts, 'edit']))
            : parent::onAction($action, $message, $screen);
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return \in_array($message->key, ['n', 'd'], true)
            ? new UpdateResult($this->cloneWith(shortcuts: [...$this->shortcuts, $message->key]))
            : UpdateResult::ignored($this);
    }
}
