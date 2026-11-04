# Building an app

[← README](../README.md)

Most apps extend `App`. You write `view()`, and you override the hooks you need.

## The hooks

```php
abstract readonly class App
{
    abstract public function view(): Widget;                  // what the screen shows now
    public function theme(): ?Theme { return null; }          // asked every frame; null keeps the start theme

    protected function setup(): ?Cmd { return null; }         // commands to run at start
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
}
```

- `actions()` declares the keys that work wherever the keyboard is, each with
  its handler and label: `parent::actions()->with('archive', ['ctrl+a'], static fn (self $app): self => $app->archived(), 'archive')`.
  The footer leads with them and the key help lists them. A letter key is left
  out while a field takes text, since it types then. `inFooter: false` keeps a
  shortcut out of the footer and in the key help only.
- Ctrl+Q and Ctrl+C quit. `parent::actions()->withQuitKeys('q', 'ctrl+q')`
  changes the keys. Declare `Actions::QUIT` again with your own handler to ask
  before quitting.
- A component's `withAction()` keys work only while it holds the keyboard.
- `onComponentsChanged()` runs after bound widgets changed. Use it to re-filter a
  list when a search field changed. The ids are `ComponentId` objects, so
  `in_array('search', $ids, true)` is always false. Check `$id->value === 'search'`.
- `theme()` is asked before every frame. To switch themes, keep a field on the
  App: `return $this->dark ? Theme::dark() : Theme::light();`.
- To save state when the program ends, implement `Runtime\ShutdownAware`. Its
  `onShutdown()` runs on the last App, after a quit key, `Cmd::quit()` or a
  signal, and before the terminal is restored. Do the work right there: no
  command runs any more.

## Which hook for this?

| You want to … | Use |
|---|---|
| answer a key wherever the keyboard is | `actions()`, `parent::actions()->with('archive', ['ctrl+a'], …)` |
| answer a key only while one widget holds the keyboard | `$this->bind('list')->withAction('delete', ['d'], …)` |
| take what a widget did (typed, moved, chose, submitted) | `$this->bind('field')->on(Submitted::class, …)`, or the bind closure |
| take the answer to a command (a process, HTTP, a timer) | `onMessage()`, matched by its `EffectId` |
| redraw on a clock | `onTick()` after `Cmd::tick()`. An animated widget needs neither |
| read any key before the widgets do | `onKey()`. Prefer an action, so the key shows in the footer and the help |

## What a hook returns

Every hook, handler and bind closure returns the next App, or an `UpdateResult`
once it asks for work:

- `$this` — the message meant nothing here. In `onKey()`, the key then goes
  on to the focused widget.
- `$next` — a new App.
- `new UpdateResult($next, $command)` — a new App and a command, or
  `new UpdateResult($this, $command)` for only a command.

The App is immutable. Return a new one instead of changing a property. Write
small typed `withX()` methods, or call the protected
`$this->cloneWith(list: $list)`, which returns `$this` when nothing changed.
Never redeclare `cloneWith()` in your App.

## Keep interactive widgets on the App

A widget such as `TextInput`, `Panel`, `Select` or `Table` holds state: a
cursor, a selection, a scroll position. Keep it in a property of the App. If
`view()` creates it fresh each time, that state is lost on every frame.

Then **bind** it in `view()` with `Component::bind()`. A bound widget gets keys
and clicks, takes part in tab order, and hands its new state back through the
closure. A whole todo app:

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

- **Start the App from a static factory.** A widget's constructor is private (it
  guards what the widget may hold), so `private TextInput $field = new TextInput()`
  does not compile. `TodoApp::new()` builds the widgets once.
- **The record is the item.** `Todo` implements `ListItem`, so the App keeps one
  `ListView` and no second array beside it. `cursorItem()`, `withCursorItem()`
  and `withoutCursorItem()` edit the row the reader is on, and the cursor stays
  on it by `key()`.
- **`withSubmit()` makes Enter commit the field**, so the closure takes the value
  inline. A field left without it hands Enter on, the way a form moves to the
  next field.
- **`withAction()` gives a key to one component.** Its handler runs on the app
  with the list already stored, only while the list holds the keyboard, and the
  footer and the key help name Space and `d` only then. A fourth argument
  words the key (`'remove'`), else the action's name does. In the field, Space
  is text. Several alike components (the columns of a board) each hear their
  own: the handler may capture a plain value such as the column's index.
- **`withAddedItem()`** appends a row and puts the cursor on it.

Rules for binding:

- **Ids are unique** in the tree. Use a readable string. `Cmd::focus('settings-form')`
  moves the keyboard there.
- **A widget that is not bound does not react.** It only draws.
- **The closure must be `static`.** Update the `$app` it is given, not `$this`:
  `$this` may be an older App by the time the closure runs. The closure may
  capture plain values (strings, numbers), never objects.
- **To start work as well**, return `new UpdateResult($app->withList($list), $command)`
  instead of the App.
- **To react to a choice**, add `->on(Chosen::class, …)`. A widget that can
  choose something (`ListView`, `Table`, `Select`, `Radio`, `Dropdown`, the
  filterable ones) hands on a `Chosen` when the reader presses Enter or clicks
  an item. Its `choice` is the `ListItem`, the `Row` or the option string, and
  moving the cursor is never a choice. Such a widget is handed back even when
  nothing in it changed, so choosing the same row twice is seen. A `TextInput`
  with `withSubmit()` hands a `Submitted` with its value instead of the key.
- **To close what was bound**, set it to `null` in the App you return (a modal,
  a picker).

`$this->bind('list')` binds the widget the property holds and writes it back there.
`->on(Submitted::class, static fn (self $app, Submitted $submitted): self => …)` runs
after that store, on the stored app, and its answer wins: ignoring an outcome never
loses what the widget did.

A property may hold several widgets, keyed: `$this->bind('columns', at: $key)` binds
`$this->columns[$key]`, writes it back under that key, and names it `columns.$key`
for `Cmd::focus()`. Reordering or removing entries keeps each widget, and the
keyboard, with its key. String keys survive a reorder best.

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
`modules.$key`. `Mount::named($name, $model, $view, $writeBack)` is the long form
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
