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
    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult { return UpdateResult::ignored($this); }
    protected function onAction(string $action, KeyMsg $message): UpdateResult {}  // 'quit' quits
    protected function onMouse(MouseMsg $message, Rect $screen, Keyboard $keyboard): UpdateResult { return UpdateResult::ignored($this); }  // $keyboard: focused(), hints()
    protected function onTick(TickMsg $message, Rect $screen): UpdateResult { return UpdateResult::ignored($this); }
    protected function onResize(ResizeMsg $message): UpdateResult { return UpdateResult::ignored($this); }
    protected function onMessage(Message $message, Rect $screen): UpdateResult { return UpdateResult::ignored($this); }
    protected function onComponentsChanged(array $ids): UpdateResult { return UpdateResult::ignored($this); } // list<ComponentId>, not strings: compare $id->value

    protected function applicationKeybindings(): Keybindings {} // actions for onAction(), checked before any widget
    protected function quitBindings(): Keybindings {}           // extra keys that quit
    protected function globalKeybindings(): Keybindings {}      // bindings every widget inherits (VimMode)
    protected function focusCycleBindings(): Keybindings {}     // tab / shift+tab
    protected function keyHelpBindings(): Keybindings {}        // ? opens the key help; empty turns it off
    protected function actionDescription(string $action): string {} // what an action does, in the key help
    protected function mounts(): array { return []; }           // mounts kept alive but not drawn now
    protected function bindProperty(string $property, Interactive $widget): Component {}
}
```

- Ctrl+Q and Ctrl+C quit. They live in `applicationKeybindings()`, so override it
  to change them, for example to ask before quitting.
- `onAction()` gets the actions that `applicationKeybindings()` matched.
- `onComponentsChanged()` runs after bound widgets changed. Use it to re-filter a
  list when a search field changed. The ids are `ComponentId` objects, so
  `in_array('search', $ids, true)` is always false. Check `$id->value === 'search'`.
- `theme()` is asked before every frame. To switch themes, keep a field on the
  App: `return $this->dark ? Theme::dark() : Theme::light();`.

## What a hook returns

Every hook returns an `UpdateResult`:

- `UpdateResult::ignored($this)` — the message meant nothing here. In `onKey()`,
  the key then goes on to the focused widget.
- `new UpdateResult($next, $command)` — a new App, and maybe a command.
- `$this->then($command)` — only a command.

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
closure:

```php
use Elmphant\Tui\Application\{App, Component};
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;

final readonly class Settings extends App
{
    public function __construct(
        private Panel $form,
        private Footer $footer,
    ) {}

    public function view(): Widget
    {
        return Layout::vertical(
            Component::bind(
                'settings-form',
                $this->form,
                static fn (self $app, Panel $form): self => $app->withForm($form),
            ),
            Component::bind(
                'status-footer',
                $this->footer,
                static fn (self $app, Footer $footer): self => new self($app->form, $footer),
            ),
        );
    }

    private function withForm(Panel $form): self
    {
        return new self($form, $this->footer);
    }
}
```

Rules for binding:

- **Ids are unique** in the tree. Use a readable string. `Cmd::focus('settings-form')`
  moves the keyboard there.
- **A widget that is not bound does not react.** It only draws.
- **The closure must be `static`.** Update the `$app` it is given, not `$this`:
  `$this` may be an older App by the time the closure runs. The closure may
  capture plain values (strings, numbers), never objects.
- **To start work as well**, return `new UpdateResult($app->withList($list), $command)`
  instead of the App.
- **To react to a choice**, take the message as a third argument. A widget that
  can choose something (`ListView`, `Table`, `FilterableList`, …) answers
  `commits($message)`:
  `static fn (self $app, ListView $list, Message $message): self => $list->commits($message) ? $app->opened($list) : $app->withList($list)`.
  Such a widget is handed back even when nothing in it changed, so choosing the
  same row twice is seen.
- **To close what was bound**, set it to `null` in the App you return (a modal,
  a picker).

`bindProperty('list', $this->list)` is a shortcut that writes the widget back to
the property of that name.

## Mounting a sub-model

A larger part of an app, such as a tab or a module, can have its own model,
widgets and commands. Mount it under a name:

- Its widgets are named `name/widget`. Inside, the sub-model uses the short
  names, also in `Cmd::focus('field')`.
- It hears a key before its widgets do, like an App.
- Results of its commands come back to it.
- If it implements `Initializes`, it starts with the App.

```php
use Elmphant\Tui\Application\{App, Initializes, Mount};
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
            : UpdateResult::ignored($this);
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
        return Mount::named('uptime', $this->uptime, $this->uptime->view(), static fn (self $app, Uptime $uptime): self => new self($uptime));
    }
}
```

The last argument writes the changed model back into the App. To send a message
to the App instead of to the sub-model, return the command
`Mount::toHost($message)`. It arrives in the App's `onMessage()`.

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
