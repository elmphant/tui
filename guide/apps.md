# Building an app

[← README](../README.md)

Most apps extend `App`. You describe the screen in `view()`, keep the widgets on
the App, and say what should happen when the reader uses them. The framework
stores what the widgets did, routes keys and clicks, and names the keys.

## A first app

```php
use Elmphant\Tui\Application\App;
use Elmphant\Tui\Message\Submitted;
use Elmphant\Tui\Widget\Bar\{FocusHints, Footer, Header};
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Listing\{ListItem, ListItemBehavior, ListView};
use Elmphant\Tui\Widget\Widget;

final readonly class Todo implements ListItem
{
    use ListItemBehavior; // the rest of ListItem, from title() and key()

    public function __construct(public int $id, public string $name, public bool $done = false) {}

    public function title(): string { return ($this->done ? '[x] ' : '[ ] ') . $this->name; }

    public function key(): string { return (string) $this->id; }

    public function toggled(): self { return new self($this->id, $this->name, !$this->done); }
}

final readonly class TodoApp extends App
{
    public function __construct(private TextInput $field, private ListView $list, private int $nextId = 1) {}

    public static function new(): self
    {
        return new self(TextInput::new()->withPlaceholder('What needs doing?')->withSubmit(), ListView::new([]));
    }

    public function view(): Widget
    {
        return Layout::column(
            Header::new('Todo')->withRule(),
            $this->bind('field')->on(Submitted::class, static fn (self $app, Submitted $submitted): self => $app->added($submitted->value)),
            $this->bind('list')
                ->withAction('toggle', ['space'], static fn (self $app): self => $app->toggled())
                ->withAction('delete', ['d'], static fn (self $app): self => $app->cloneWith(list: $app->list->withoutCursorItem())),
            Footer::new()->withLeft(FocusHints::new()),
        );
    }

    private function toggled(): self
    {
        $todo = $this->list->cursorItem();

        return $todo instanceof Todo ? $this->cloneWith(list: $this->list->withCursorItem($todo->toggled())) : $this;
    }

    private function added(string $name): self
    {
        return trim($name) === '' ? $this : $this->cloneWith(
            field: $this->field->withValue(''),
            list: $this->list->withAddedItem(new Todo($this->nextId, trim($name))),
            nextId: $this->nextId + 1,
        );
    }
}

// Program::run(TodoApp::new());
```

Type a todo and press Enter. Tab moves to the list, Space ticks a row, `d`
deletes it, Ctrl+Q quits. The footer names the keys that work where the keyboard
is, and `?` lists them all.

- **The widgets live on the App.** A `TextInput` or a `ListView` holds state: a
  cursor, a selection, what is typed. Kept in a property, that state survives
  every frame. Built fresh in `view()`, it would be lost each time.
- **`$this->bind('list')` makes a widget react.** It gets keys and clicks, takes
  part in tab order, and the framework stores what it did back in the property.
  A widget that is not bound only draws.
- **`->on(Submitted::class, …)` reacts to what a widget did.** It runs after the
  widget is stored, on the stored App, and returns the next App.
- **`withAction()` gives a key to one widget.** It works only while that widget
  holds the keyboard. In the field, Space is text.
- **Start the App from a static factory.** A widget's constructor is private, so
  `private TextInput $field = new TextInput()` does not compile. The App's own
  constructor stays public: the framework builds the next App with it.
- **The record is the item.** `Todo` implements `ListItem`, so the App keeps one
  `ListView` and no second array. `cursorItem()`, `withCursorItem()`,
  `withoutCursorItem()` and `withAddedItem()` edit it, and the cursor stays on
  its row by `key()`.

The App is immutable. Return a new one instead of changing a property: small
typed `withX()` methods, or the protected `$this->cloneWith(list: $list)`, which
returns `$this` when nothing changed. Never redeclare `cloneWith()`, and don't
`use Cloneable` again: `App` already does. `cloneWith()` builds the next App with
the constructor, so keep it public. A static factory can still build the first one.

## What a widget tells you

A widget that did something the reader meant hands on an outcome, and
`->on(Outcome::class, …)` hears it:

| Outcome | From | Carries |
|---|---|---|
| `Submitted` | a `TextInput` with `withSubmit()`, on Enter | `$value` |
| `Chosen` | `ListView`, `Table`, `Select`, `Radio`, `Dropdown`, the filterable ones, on Enter or a click on an item, and `TreeView` on a leaf | `$choice`: the `ListItem`, `Row`, `TreeNode` or option |
| `Pressed` | a `Button`, and a `Form` for its button or Enter in its last field | nothing: read the form's values on the stored App |
| `Dismissed` | a `Modal`, on Escape or a click outside, and a `Form` given `withCancel()`, on Escape | nothing |

All four live in `Elmphant\Tui\Message`. Moving a cursor is never an outcome. To
follow the cursor, such as a preview of the row under it, override
`onComponentsChanged()`: it runs after a bound widget changed, and reads the
cursor off the stored widget (`$this->files->cursorItem()`, a tree's
`cursorNode()`). A widget is stored even when nothing in it changed, so choosing
the same row twice is heard twice. Each outcome carries the key or click that caused it as `$trigger`.

Rules for binding:

- **Ids are unique** in the tree. `bind('list')` is named `list`, and
  `bind('list', 'todo-list')` names it yourself. `Cmd::focus('todo-list')`
  moves the keyboard there.
- **Handlers must be `static`.** Use the `$app` they are given, not `$this`,
  which may be an older App by then. They may capture plain values (strings,
  numbers), never objects.
- **To close what was bound**, set it to `null` in the App you return (a modal,
  a picker).
- **A property may hold several widgets, keyed.** `$this->bind('columns', at: $key)`
  binds `$this->columns[$key]`, stores it back under that key, and names it
  `columns.$key`. Reordering or removing entries keeps each widget, and the
  keyboard, with its key.
- **A widget built in `view()` instead of kept on the App** is bound with
  `Component::bind($id, $widget, static fn (self $app, Widget $updated): self => …)`,
  where the closure stores it wherever it belongs.

## Keys

`actions()` declares the keys that work wherever the keyboard is, each with its
handler and its label:

```php
use Elmphant\Tui\Application\{App, AppActions};
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;

final readonly class Inbox extends App
{
    public function __construct(private int $archived = 0) {}

    public function view(): Widget
    {
        return Text::new("{$this->archived} archived");
    }

    protected function actions(): AppActions
    {
        return parent::actions()
            ->with('archive', ['ctrl+a'], static fn (self $app): self => new self($app->archived + 1), 'archive')
            ->withQuitKeys('q', 'ctrl+q');
    }
}
```

- The footer leads with them and the key help lists them, in the declared words.
  A letter key is left out while a field takes text, since it types then.
  `inFooter: false` leaves a shortcut to the key help alone.
- Ctrl+Q and Ctrl+C quit. `withQuitKeys()` changes the keys. Declare
  `Actions::QUIT` again with your own handler to ask before quitting.
- A widget's `withAction()` keys come first while it holds the keyboard, so
  alike widgets (the columns of a board) each hear their own.
- `onKey()` reads any key before the widgets do. Prefer an action, so the key
  shows in the footer and the help.
- `onMouse()` hears what the pointer does, such as a drag from one list onto
  another. `$input->componentAt($message)` names the bound widget under it.

More in [Keys and focus](keys-and-focus.md).

## Effects

Every hook, handler and bind closure returns the next App, or an `UpdateResult`
once it asks for work:

- `$this` — the message meant nothing here. In `onKey()`, the key then goes on
  to the focused widget.
- `$next` — a new App.
- `new UpdateResult($next, $command)` — a new App and a command, or
  `new UpdateResult($this, $command)` for only a command.

A command runs outside the App: a process, an HTTP request, a timer. Its answer
arrives in `onMessage()`, matched by the command's `EffectId`. `setup()` returns
the commands to start with, such as the first load. It only starts commands and
cannot change the App, so build the starting state in the constructor or a
static factory. `onTick()` runs on
the clock `Cmd::tick()` starts, and an animated widget needs neither. See
[Effects](effects.md).

## Mounting a sub-model

A larger part of an app, such as a tab or a module, can have its own model,
widgets and commands. Mount it under a name:

- Its widgets are named `name/widget`. Inside, the sub-model uses the short
  names, also in `Cmd::focus('field')`.
- It hears a key before its widgets do, like an App.
- Results of its commands come back to it.
- If it implements `Initializes`, it starts with the App.

```php
use Elmphant\Tui\Application\{App, Initializes};
use Elmphant\Tui\Core\{Cmd, EffectId, Message, Reducer, UpdateResult};
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\ProcessResultMsg;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;

final readonly class Uptime implements Reducer, Initializes
{
    public function __construct(public string $shown = 'asking…') {}

    public function init(): ?Cmd
    {
        return Cmd::exec(['uptime'])->withId(EffectId::named('uptime'));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return $message instanceof ProcessResultMsg && $message->isFrom('uptime')
            ? new UpdateResult(new self(trim($message->stdout)))
            : new UpdateResult($this);
    }

    public function view(): Widget
    {
        return Text::new($this->shown);
    }
}

final readonly class Machine extends App
{
    public function __construct(public Uptime $uptime = new Uptime()) {}

    public function view(): Widget
    {
        return $this->mount('uptime', $this->uptime->view());
    }
}
```

`$this->mount('uptime', $view)` mounts the sub-model the property holds under its
name and stores the changed model back there. With several, keyed in an array,
`$this->mount('modules', $view, at: $key)` mounts the one under that key as
`modules.$key`. Give `id:` to name it yourself, as `bind()` takes one.
`Mount::named($name, $model, $view, $writeBack)` is the long form
for a model kept anywhere else. To send a message to the App instead of to the
sub-model, return the command `Mount::toHost($message)`. It arrives in the App's
`onMessage()`.

An App that shows only some mounts at a time, such as tabs, lists the hidden
ones in `mounts()` so they still start and get their results. A mount that is
neither drawn nor listed has all its commands stopped.

## Composing a child by hand

Without a mount, a parent can pass messages to a child itself. `Cmd::map()` wraps
everything the child's commands send later, so the parent can tell the child's
messages apart:

```php fragment
final readonly class FromSearchBox implements Message
{
    public function __construct(public Message $inner) {}
}

$childResult = $this->searchBox->update($message, $area);

return $childResult
    ->mapModel(fn (SearchBox $searchBox) => $this->withSearchBox($searchBox))
    ->mapCommand(
        fn (Cmd $command) => $command->map(
            fn (Message $message) => new FromSearchBox($message),
        ),
    );
```

## Your own messages

A message is any class that implements the empty `Message` interface:

```php
use Elmphant\Tui\Core\Message;

final readonly class SearchSubmitted implements Message
{
    public function __construct(public string $query) {}
}
```

Send one with `Cmd::message(new SearchSubmitted('php'))`, and handle it in
`onMessage()`.

## Every hook

```php
abstract readonly class App
{
    abstract public function view(): Widget;                  // what the screen shows now
    public function theme(): ?Theme { return null; }          // asked every frame; null keeps the start theme

    protected function setup(): ?Cmd { return null; }         // commands to run at start; the starting state comes from the constructor
    protected function onKey(KeyMsg $message, InputContext $input): self|UpdateResult { return $this; }  // $input: screen(), focused(), hints(), componentAt($mouse)
    protected function onMouse(MouseMsg $message, InputContext $input): self|UpdateResult { return $this; }
    protected function onTick(TickMsg $message, InputContext $input): self|UpdateResult { return $this; }
    protected function onResize(ResizeMsg $message): self|UpdateResult { return $this; }
    protected function onMessage(Message $message, InputContext $input): self|UpdateResult { return $this; }
    protected function onComponentsChanged(array $ids): self|UpdateResult { return $this; } // list<ComponentId>, not strings: compare $id->value

    protected function actions(): AppActions {}                 // keys answered anywhere, before any widget, quit keys too
    protected function globalKeybindings(): Keybindings {}      // bindings every widget inherits (VimMode)
    protected function focusCycleKeybindings(): Keybindings {}  // tab / shift+tab
    protected function keyHelpKeybindings(): Keybindings {}     // ? opens the key help; empty turns it off
    protected function mounts(): array { return []; }           // mounts kept alive but not drawn now
    protected function bind(string $property, ?string $id = null, int|string|null $at = null): Component {} // the widget a property holds (at: a key of an array), stored by the framework
    protected function mount(string $property, Widget $view, ?string $id = null, int|string|null $at = null): Mount {} // the sub-model a property holds, stored by the framework
}
```

`App`, `AppActions` and `InputContext` live in `Elmphant\Tui\Application`,
`Cmd`, `Message` and `UpdateResult` in `Elmphant\Tui\Core`, and the messages
(`KeyMsg`, `ProcessResultMsg`, the outcomes) in `Elmphant\Tui\Message`.

- `onComponentsChanged()` runs after bound widgets changed. Use it to re-filter a
  list when a search field changed. The ids are `ComponentId` objects, so
  `in_array('search', $ids, true)` is always false. Check `$id->value === 'search'`.
- `theme()` is asked before every frame. To switch themes, keep a field on the
  App: `return $this->dark ? Theme::dark() : Theme::light();`.
- To save state when the program ends, implement `Runtime\ShutdownAware`. Its
  `onShutdown()` runs on the last App, after a quit key, `Cmd::quit()` or a
  signal, and before the terminal is restored. Do the work right there: no
  command runs any more.

## Without `App`: the `Model` interface

`App` is built on a smaller interface. Implement `Model` yourself to draw every
cell by hand or to build another abstraction:

```php
interface Model
{
    public function init(): ?Cmd;                         // commands to run at start
    public function update(Message $message, Rect $area): UpdateResult;
    public function render(Frame $frame, RenderPass $pass): void;
}
```

`$pass->context` is what you hand to widgets you draw. A model that draws by hand
says where the keyboard is while it draws:
`$context->focusHere(fn () => $field->render(...))` for one widget, or
`$context->focusOnChild(2, fn () => $panel->render(...))` for the third child
of a `Panel`. See [Custom widgets](custom-widgets.md) for drawing cells.
