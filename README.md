# Elmphant TUI

**The Elm Architecture for terminal UIs in PHP.** Inspired by Go's [Bubbletea](https://github.com/charmbracelet/bubbletea) and Rust's [Ratatui](https://github.com/ratatui/ratatui).

The name is a play on the **PHP elephant** and the **ELM architecture** used under the hood.

PHP 8.2+ | MIT License

## Quick Start

```bash
composer require elmphant/tui
```

Elmphant requires PHP 8.2+ and `ext-mbstring`. Interactive
programs target POSIX terminals; models and widgets can be tested on any
platform without opening a terminal.

Two features bring their own package, because an app that does not use them
should not pay for one:

```bash
composer require symfony/http-client   # Cmd::http()
composer require league/commonmark     # Paragraph::fromMarkdown()
```

Either one missing is a clear exception naming the package at the moment you
reach for the feature, never a fatal autoload error.

## Hello World

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\{Cmd, UpdateResult};
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;

final readonly class Counter extends App
{
    public function __construct(private int $count = 0) {}

    private function withCount(int $count): self
    {
        return $count === $this->count ? $this : new self($count);
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return match ($message->key) {
            'k' => new UpdateResult($this->withCount($this->count + 1)),
            'j' => new UpdateResult($this->withCount($this->count - 1)),
            'x' => $this->then(Cmd::quit()),
            default => UpdateResult::ignored($this),
        };
    }

    public function view(): Widget
    {
        return Text::new("Count: {$this->count}  j/k change, x or Ctrl+Q quit");
    }
}

Program::run(new Counter());
```

## The Elm Architecture

Every Elmphant app follows the same cycle: **Model -> Update -> View -> Runtime -> repeat**.

```
  ┌─► terminal / timers / HTTP / processes
  │        │
  │        ▼
  │   messages: KeyMsg, MouseMsg, TickMsg, HttpResultMsg, ...
  │        │
  │        ▼
  │   update(message, area) -> UpdateResult(newModel, ?Cmd)
  │        │
  │        ▼
  │   view(newModel) -> Widget tree -> terminal diff
  └────────┘
```

The important rule: `update()` and `view()` describe what should happen. They do not do the work.
Terminal I/O, HTTP, processes, timers, and program shutdown happen only when the runtime dispatches a `Cmd`.

### Recommended App API

Most applications should extend `App`. It gives you widget-based rendering, screen-aware mouse handling, reserved Ctrl-Q/Ctrl-C quit shortcuts (both live in `applicationKeybindings()` — override it to rebind or intercept them, e.g. for a confirm-before-quit dialog), extra quit keys via `quitBindings()` (empty by default), an `onResize()` hook, and focus delegation. In `onKey()`, returning a changed model or any command consumes the key; `UpdateResult::ignored($this)` passes it to the focused component.

```php
abstract readonly class App
{
    abstract public function view(): Widget;                  // pure UI description
    public function theme(): ?Theme { return null; }          // asked every frame; null keeps the start theme

    protected function setup(): ?Cmd { return null; }         // initial commands
    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult { return UpdateResult::ignored($this); }
    protected function onAction(string $action, KeyMsg $message): UpdateResult {}  // 'quit' quits
    protected function onMouse(MouseMsg $message, Rect $screen, Keyboard $keyboard): UpdateResult { return UpdateResult::ignored($this); }  // $keyboard: focused(), hints()
    protected function onTick(TickMsg $message, Rect $screen): UpdateResult { return UpdateResult::ignored($this); }
    protected function onResize(ResizeMsg $message): UpdateResult { return UpdateResult::ignored($this); }
    protected function onMessage(Message $message, Rect $screen): UpdateResult { return UpdateResult::ignored($this); }
    protected function onComponentsChanged(array $ids): UpdateResult { return UpdateResult::ignored($this); }

    protected function applicationKeybindings(): Keybindings {} // actions for onAction(), before any component
    protected function quitBindings(): Keybindings {}           // extra keys that quit
    protected function globalKeybindings(): Keybindings {}      // bindings every widget inherits (VimMode)
    protected function focusCycleBindings(): Keybindings {}     // tab / shift+tab
    protected function keyHelpBindings(): Keybindings {}        // ? opens the key help; empty offers none
    protected function actionDescription(string $action): string {} // what an action does, in the key help
    protected function mounts(): array { return []; }           // mounts held but not drawn now
    protected function bindProperty(string $property, Interactive $widget): Component {}
}
```

`onAction()` hears the actions `applicationKeybindings()` matched; `onComponentsChanged()` runs after bound widgets were written back, on the App that already holds them — the place to re-filter a list when a bound search field changed. `theme()` is asked before every frame, so a theme switch is a field on the App: `return $this->dark ? Theme::dark() : Theme::light();`. `bindProperty('list', $this->list)` binds a widget to the property it lives on, so the write-back cannot name another.

Hooks always return `UpdateResult`: `UpdateResult::ignored($this)` when the message meant nothing here, `new UpdateResult($next, $command)` otherwise, or `$this->then(...)` for a command alone. Passing on what a child answered is `$answer->mapModel(fn ($child) => $this->withChild($child))`, which keeps the child's command even when the child itself did not change. Under PHPStan level 9 the hooks answer `UpdateResult<static>`. `App` uses `Cloneable`, so a subclass may call the protected `$this->cloneWith(list: $list)` (named arguments; it returns `$this` when nothing changed) — never redeclare it, a private `cloneWith()` in a subclass is fatal. Typed `withX()` methods still give static analysers the strongest contract.

Keep stateful widgets as properties on the model, not local variables rebuilt inside `view()`. `view()` should compose the current state into a widget tree; if you recreate a `TextInput`, `Panel`, `Select`, or `Table` in `view()`, its cursor, selection, scroll, and focus state will be reset.

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

    private function withForm(Panel $form): self
    {
        return new self($form, $this->footer);
    }

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
}
```

Mount every stateful `Interactive` widget as a `Component` directly in
`view()`. The keyed node is simultaneously the rendered widget, the layout
target, and the event-routing binding—there is no separate registration list
to keep synchronized. Its ID is stable across property renames, its replacement
receives the current App value, and focusability is inferred from the wrapped
widget. Pass a readable string ID normally; use `ComponentId::named()` when the
same ID value is shared elsewhere. IDs must be unique within the mounted tree. An interactive
object held in model state but not mounted as a `Component` is passive. For
global shortcuts in apps with focusable widgets, handle them in `onKey()`
before the focused widget receives the key — see [Keys](#keys) for why those
shortcuts should be Ctrl chords.

Replacement closures must be declared `static` and may capture scalar
configuration only. `Component::bind()` rejects closures bound to `$this` or
capturing object state, preventing a cached view from writing an updated widget
back into a stale App value. Always update the supplied `$app`; prefer explicit
typed methods such as `$app->withForm($form)` when the transition has
invariants. PHPStan also infers one App type across the closure input and
return. When writing the widget back should also start work, for example
loading what a newly selected row shows, return
`new UpdateResult($app->withList($list), $command)` instead of the App.

The write-back is the only way a bound widget's state reaches the App, and it
runs when a message changed the widget. It is handed that message as a third
argument, so a host can ask a `Committing` widget (`ListView`, `Table`,
`FilterableList`) whether the message chose something — such a widget is
written back even when nothing in it changed, so picking the same row twice is
seen: `static fn (self $app, ListView $list, Message $message): self => $list->commits($message) ? $app->opened($list) : $app->withList($list)`.
A write-back may also close the component it came from — set the modal or the
picker to `null` in the App it returns.

### Keys

`KeyMsg::$key` is a `Key` case for a named key (`Key::Enter`, `Key::Escape`,
`Key::Up`, `Key::F2`) and a string only for text (`'a'`, `'é'`, `' '`).
`new KeyMsg('enter')` becomes `Key::Enter`, so `$message->key === 'esc'` never
matches anything; the shipped `KeyNameIsNotTextRule` reports it. `isText()` is
true for an unmodified text key. Bindings spell keys as patterns: `'enter'`,
`'ctrl+r'`, `'shift+tab'`.

While the focused component takes text (`TextInput`, `FilterableList`, an open
`Dropdown`, any widget implementing `TakesText`), an unmodified printable key is
text for that component alone: `onKey()`, `applicationKeybindings()`,
`quitBindings()`, mounts and global letter bindings never see it. Modified and
named keys stay shortcuts, so give app-wide commands Ctrl chords (`ctrl+n`, not
`n`); a bare letter works only while a list or a button holds the keyboard.

A pattern of several keys separated by spaces is a chord, typed one key after the
other: `'g g'`, `'g T'`, `'ctrl+x ctrl+s'`. The runtime holds the first keys back
until the chord completes, then hands the last key on with the others in
`KeyMsg::$preceding`, so a chord matches like any other pattern. A key that does
not go on with the chord releases the held keys first and is handled after them;
Esc cancels it; and if nothing follows for half a second the held keys are
handled on their own, so a widget that binds a plain `g` still gets it. While
the focused component takes text, a chord that starts with a text key is typed
instead, as a single letter is. A global chord binds only while the focused
component or a container around it answers its action: with no `TabBar` around
the keyboard, `g t` is no chord, and `g` then `t` are two keys.

```php
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;

$bindings = new Keybindings([Actions::HOME => ['g g'], Actions::END => ['G']]);

assert($bindings->hints(['top' => Actions::HOME, 'bottom' => Actions::END]) === ['gg' => 'top', 'G' => 'bottom']);
```

A widget's bindings are its `defaultKeybindings()`, then what `withKeybindings()`
replaces per action, then the App's `globalKeybindings()` on top.
`VimMode::keybindings()` adds:

| Keys | Action |
|---|---|
| `j` / `k` | cursor down / up, and scroll |
| `h` / `l` | left / right: collapse or leave and expand or enter in a tree, the cursor in a row of choices |
| `gg` / `G` | top / bottom (`Actions::HOME` / `Actions::END`) |
| `ctrl+d` / `ctrl+u` | page down / up (there is no half-page action) |
| `L` / `H`, `gt` / `gT` | next / previous tab |
| `/` | search: hand the keys to a filter's field (`Actions::SEARCH`) |

A filter (`FilterableList`, `FilterableTable`, `FilterableCheckboxGroup`, and a
`Dropdown` holding one) asks whether its bindings name a key for
`Actions::SEARCH`. While none does, its field takes the keys, as it always has.
Once one does — vim mode, or a host's `withKeybindings()` — the list or table
holds the keys: `j`/`k`, `gg`/`G` and `ctrl+d`/`ctrl+u` move the list, a
`FilterableTable` leaves them to the pane it scrolls in, the field shows no
caret, and global letters work. `/` hands the keys to the field; Esc or Enter
hands them back and keeps the query, and Esc on the list then clears it. The
footer hints say which of the two holds the keys (`/ search`, `Esc/Enter back`).
A widget with a filter field of its own follows the same rule by using the
`Widget\Filter\SearchMode` trait: `takesText()`, `searchToggledBy($key)` and
`searchHints()` come with it.

#### Key help

`?` opens a list of every key that works where the keyboard is, laid over the
screen: the hints the footer shows, every key the focused widget answers
(including the ones the footer leaves out), the containers around it, the app's
own keys and the global ones such as vim mode's, each labelled the way the footer
labels it. It is read off the live bindings, so it cannot go stale. `?` or Esc
closes it, and it scrolls with `↑`/`↓`, `j`/`k`, PageUp/PageDown, Home/End and
the wheel when it is taller than the screen. While a field takes the keys, `?` is
typed instead. An action is described by `Actions::description()`; override
`actionDescription()` to name the actions the app binds itself,
`keyHelpBindings()` to choose another key (or `Keybindings::empty()` for none),
and show it in a footer with `$this->keyHelpHints()`. A mounted model that
answers keys of its own, outside any component, implements `NamesItsKeys` and
lists them from its bindings in `ownKeys()`; they appear under the mount's name.

The keyboard starts on the first stop in tree order — inside a `TabBar`'s first
page (`tabs/0`), not on its strip — so a header control drawn above a field takes
it first; return `Cmd::focus(ComponentId::named('composer'))` from `setup()` to
start elsewhere. `Cmd::focus()` to a component not on screen is refused, and the
keyboard stays. When the component holding the keyboard leaves the tree, it goes
to the nearest ancestor that is a stop, else the first stop under it, else into
the nearest component it sat inside that is still on screen (closing the tab of
mounts that held it lands in the next tab), else the most recent stop still
there, else the first stop.

### Responsive terminal layouts

Use `Layout::horizontal()` and `Layout::vertical()` for simple, fixed
compositions; `->withGap(1)` leaves a cell between neighbours. Use `Flex` when sibling widgets should reflow as the terminal
width changes:

```php
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Widget\Layout\Flex;

$content = Flex::row()
    ->withWrap()
    ->withGap(1)
    ->add($sidebar, new Constraint(min: 22, flex: 1, basis: 28))
    ->add($main, new Constraint(min: 30, flex: 2, basis: 48))
    ->add($details, new Constraint(min: 22, flex: 1, basis: 28));
```

Every child is sized by the same `Constraint` the stacking layouts use — here for
the row's own axis. A widget asked how big it wants to be answers with a `Sizing`,
which pairs one `Constraint` per axis so neither side has to guess which was meant.
`basis` is the preferred main-axis size used when deciding where to wrap.
`flex` is the weight a child grows by; the `shrink` constructor argument is the
weight it gives space back by when the row is too narrow (there is no `shrink()`
factory). `Constraint::min(n)` is a floor, not a size: on its own it never grows,
and `min(n)->withFlex()` means "n at least, then a share of what is left".
`max(n)` carries a weight already, so it grows to fill but stops at n. Every
declared minimum is reserved first; if they cannot fit, terminal bounds take
precedence so children never receive rectangles outside their container.
Builders are immutable, so a layout remains a pure value in `view()`.

A `Flex::row()` asks for the rows its content needs, so a row of cards inside
`Layout::vertical()` takes only its height; a `Flex::column()` fills. Items
stretch across the line by default; `withCrossAlignment(CrossAlignment::Start)`
(or `Center`, `End`) keeps each at its own size.

This is intentionally a terminal-oriented subset rather than CSS Flexbox:
row/column direction, wrapping, cell gaps, basis/min/max, grow/shrink,
start/center/end/space justification and cross alignment. Browser-specific
concepts such as percentages, ordering and reverse directions are not part of
the API.

### Universal widget decoration

Wrap any widget in an immutable `Container` when spacing and presentation
belong to the composition rather than the widget itself:

```php
use Elmphant\Tui\Layout\{Constraint, Sizing, Spacing};
use Elmphant\Tui\Rendering\{BorderStyle, Color, Style};
use Elmphant\Tui\Widget\{Alignment, VerticalAlignment};
use Elmphant\Tui\Widget\Structure\Container;

$card = Container::around($content)
    ->withPadding(Spacing::xy(horizontal: 2, vertical: 1))
    ->withMargin(Spacing::only(bottom: 1))
    ->withStyle(new Style(background: Color::hex('20242C')))
    ->withBorder(BorderStyle::Rounded)
    ->withSizing(Sizing::of(Constraint::flex(), Constraint::min(5)));
```

`Container` supports padding, margin, inherited foreground/background style,
borders, an outer size for both axes, and explicitly sized/aligned content. For
example, `withContentWidth(30)->withHorizontalAlignment(Alignment::Center)`
centers a 30-column child area, while `withContentHeight(3)` and
`VerticalAlignment` do the same vertically.

It is also a transparent `WidgetContainer`: wrapping a `Component` or an entire
layout does not change component discovery, focus order, mouse hit-testing, or
message routing. Its child rectangle participates in the same cached
`LayoutPlan` used by rendering.

Spacing values are explicit terminal-cell counts from 0 through 255. Invalid
values throw immediately instead of being silently clamped.

### Low-level Model API

Use `Model` directly when you want to render into the buffer yourself or build a different high-level abstraction.

```php
interface Model
{
    public function init(): ?Cmd;                         // setup commands
    public function update(Message $message, Rect $area): UpdateResult;
    public function render(Frame $frame, RenderPass $pass): void;
}
```

A `RenderPass` is what the root draws a frame with: `$pass->context` is the
`RenderContext` every widget receives, `$pass->focus` says where the keyboard is,
and `$pass->floats` paints popups after the tree. Widgets only ever see the context.

A model that draws by hand says where its keyboard is while it draws:
`$context->withFocusHere(fn () => $field->render(...))` for one widget, and
`$context->withFocusOnChild(2, fn () => $panel->render(...))` for the third
child of a `Panel`.

**UpdateResult** is an immutable pair `(model, ?command)` returned from every `update()` call. Never mutate application state in place. For plain `Model` implementations, use the `Cloneable` trait or your own `with()` method.

**Immutability model.** Models and widgets are immutable values: `update()` returns a fresh model (or `$this` when nothing changed, thanks to `Cloneable::cloneWith()`'s no-op elision). Prefer public, typed `withX()` builders in application code. The render target — `Frame` — is mutable for performance. This matches Ratatui, Iced, and Bubbletea conventions.

## Commands

Commands are how your model requests side effects. They are data, not executed inline.

```php fragment
Cmd::quit()                         // stop the program
Cmd::tick(0.05)                     // send TickMsg every 50ms
Cmd::after(2.0, fn() => $msg)       // send one delayed message or command
Cmd::batch($cmd1, $cmd2)            // dispatch multiple commands
Cmd::combine($cmd1, null, $cmd2)    // the one, a batch, or null when all are null
Cmd::message($message)              // enqueue a Message immediately
Cmd::call(fn() => $message)          // run a short synchronous side effect
Cmd::http('https://example.com')     // non-blocking HTTP request
Cmd::listen($socket, fn($stream) => $messages) // read a stream whenever it is readable
Cmd::log('loaded', ['items' => 3])  // a line in the trace; stdout is the screen
Cmd::copy($text)                    // put text on the clipboard (OSC 52)

// Focus
Cmd::focus(ComponentId::named('search'))  // move the keyboard to a component
Cmd::focusNext() / Cmd::focusPrevious()   // as tab / shift+tab
Cmd::focusFirst() / Cmd::focusNothing()   // the first stop, or keep the keyboard for the App

// Process execution (powered by symfony/process)
Cmd::exec(['git', 'status'])        // run command, get full result
Cmd::process(['tail', '-f', 'log']) // stream output in real-time
```

`Cmd::tick()` repeats on its own, so there is no need to ask again from each `TickMsg`; asking again for an unnamed tick at the same interval keeps the one already running. Name a ticker (`Cmd::tick(1.0, EffectId::named('clock'))`) and its beats answer `$message->isFrom('clock')`, so an App with two tickers tells them apart. An interval of 0 or less throws where the command is built, so a `Cmd::tick(0)` made in `update()` arrives as an `ErrorMsg` from the update.

`Cmd::listen()` watches a socket or pipe and calls the reader with the stream each time it is readable; the reader yields messages. It ends at EOF or on `Cmd::cancel($id)` — cancel before you close the stream yourself.

Effects that may need cancellation share a typed identity:

```php
use Elmphant\Tui\Core\{Cmd, EffectId};

$download = EffectId::named('download');

Cmd::http('https://example.com/archive', id: $download);
Cmd::cancel($download); // also works for tick(), after(), exec(), and process()
```

A cancel, or a new command under the same id, also drops the results the old
effect produced that `update()` has not seen yet, so nothing from the old run
arrives after it.

An id is one name for the whole program. A part of the app that hands its child's
commands on scopes them, so two children can both call their effect `load`:

```php
use Elmphant\Tui\Core\{Cmd, EffectId};

$load = Cmd::exec(['composer', 'show'], id: EffectId::named('load'));

$dashboard = $load->scopedTo('dashboard');
$log = $load->scopedTo('log'); // runs beside the dashboard's, not instead of it
```

A cancel or a replacement then reaches `load` in that scope only. The result still
answers `$message->isFrom('load')`, because the scope belongs to the runtime, not to
the child.

`Cmd::call()` is an escape hatch for short synchronous side effects. Its callback
may return a message, another command, or `null`; thrown exceptions are delivered
as `ErrorMsg` with source `ErrorSource::Call`. It runs on the event-loop thread, so it must not
perform slow I/O or CPU-heavy work. Use `Cmd::http()`, `Cmd::exec()`, or
`Cmd::process()` for non-blocking work.

Other callbacks passed to commands are message factories. They should not mutate
the model or write directly to the terminal.

Application-defined messages implement the empty `Message` marker:

```php
use Elmphant\Tui\Core\Message;

final readonly class SearchSubmitted implements Message
{
    public function __construct(public string $query) {}
}
```

### Effects of your own

The built-in commands cover timers, subprocesses, HTTP and readable streams. An
effect outside that list — a query on an event-loop-aware database client, a
websocket, anything that finishes later — is a command of your own plus a
`CommandHandler` that runs it. The command stays data; the handler is the only
place that does anything:

```php
use Closure;
use Elmphant\Tui\Core\{Cmd, EffectId, Message, NamesItsEffect};
use Elmphant\Tui\Runtime\{CommandHandler, Program};

final readonly class QueryRows extends Cmd implements NamesItsEffect
{
    public function __construct(public string $sql, private ?EffectId $id = null) {}

    public function effectId(): ?EffectId
    {
        return $this->id;
    }
}

final class RowLoader implements CommandHandler
{
    public function handles(Cmd $command): bool
    {
        return $command instanceof QueryRows;
    }

    public function start(Cmd $command, Closure $emit, Closure $finished): ?Closure
    {
        assert($command instanceof QueryRows);
        $query = MyAsyncDatabase::run($command->sql, static function (array $rows) use ($emit, $finished): void {
            $emit(new RowsLoaded($rows));
            $finished();
        });

        return $query->cancel(...);
    }
}

Program::new($model)->withCommandHandler(new RowLoader())->start();
```

`$emit` puts a message on the same queue every other effect reports into — under
the mapping of a `Cmd::map()`ped command, so a child component's effect stays in
its own namespace — and wakes a loop that was waiting for input.

The closure `start()` answers with, if any, is how the runtime stops the effect
again: on `Cmd::cancel($id)`, when a second command takes the same
`effectId()`, and when the program shuts down. Implement `NamesItsEffect` to
take part in that; a command without a name runs until the program ends, or
until the mount that started it leaves the App.
An effect that ends on its own calls `$finished`, so the runtime lets go of it
rather than holding it until shutdown.

Handlers are asked in the order they were registered, and only for a command the
runtime has no effect of its own for — a handler cannot intercept `Cmd::quit()`
however greedily it answers `handles()`. A command no handler claims is a
`LogicException`, so a typo in `handles()` is loud rather than silent. Anything
`start()` throws arrives as `ErrorMsg` with source `ErrorSource::Command`.

A test runs no handler: `ModelHarness::emit('rows', new RowsLoaded($rows))`
delivers what `$emit` would, under the effect's scope and maps.

### Composing child widgets

Use `Cmd::map()` to keep a reusable child in its own message namespace. The
mapping applies to every message emitted by the command, including future
ticker/timer events, HTTP results, process chunks, completion messages, and
errors.

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

### Mounting a sub-model

For a whole part of an app with its own state, components and effects (a tab, a
module), mount it under a name. The framework then does the plumbing:

- Its components are known as `name/component`. Inside the sub-model you use the
  local names, also in `Cmd::focus(ComponentId::named('field'))`.
- It hears a key before its components, as an App does. The key goes to the mount
  that holds the keyboard, or to every mount on screen if none does.
- Answers to what it asks for (processes, timers, `Cmd::message()`) come back to
  it, and it is updated with the area it is drawn in.
- A model that implements `Initializes` starts with the App.

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

The last argument of `Mount::named()` is the write-back: it runs whenever the
model changed and is the only way its state reaches the App. Everything the model
emits comes back to the model; `Mount::toHost($message)` is the command that sends
a message to the App's `onMessage()` instead.

An App that shows only some of its mounts at a time, such as tabs, lists the
hidden ones in `mounts()`, so they start and still get their answers. The mounts
drawn now count without being listed. A mount that leaves the App — neither drawn
nor listed — has every effect it started stopped, named or not.

## Running Processes

Two modes for executing OS commands, both non-blocking:

### `Cmd::exec()` — Run and collect

Runs a command in the background. Sends a single `ProcessResultMsg` when it finishes with stdout, stderr, and the exit code. Collected output is capped at 8 MiB by default; exceeding the limit produces `ErrorMsg` with `EffectOutputLimitExceeded`. A binary that does not exist is not an error: it arrives as exit code 127 with empty output, so check `exitCode`.

```php fragment
use Elmphant\Tui\Message\ProcessResultMsg;

// In update():
$message instanceof KeyMsg && $message->key === 'r'
    => new UpdateResult($this, Cmd::exec(['git', 'log', '--oneline', '-10'])),

$message instanceof ProcessResultMsg
    => new UpdateResult($this->withOutput($message->stdout)),
```

### `Cmd::process()` — Stream output

Runs a command and streams output as it arrives. Sends bounded `ProcessOutputMsg` chunks (64 KiB by default, never cut inside a character), clears consumed Symfony Process buffers, and sends `ProcessFinishedMsg` when the process exits.

A pipe read can stop in the middle of a line. When each line means something — JSON
lines, a log — ask for `->withLineFraming()`: every `ProcessOutputMsg` then holds
whole lines, and a last line without a newline arrives when the child ends.

```php fragment
use Elmphant\Tui\Message\{ProcessOutputMsg, ProcessFinishedMsg, ProcessStream};

// In update():
$message instanceof KeyMsg && $message->key === 's'
    => new UpdateResult($this, Cmd::process(['npm', 'run', 'build'])),

$message instanceof ProcessOutputMsg && $message->stream === ProcessStream::Stdout
    => new UpdateResult($this->withLog($this->log . $message->output)),

$message instanceof ProcessOutputMsg && $message->stream === ProcessStream::Stderr
    => new UpdateResult($this->withErrors($this->errors . $message->output)),

$message instanceof ProcessFinishedMsg
    => new UpdateResult($this->finished($message->exitCode)),
```

### Configuration

Both `exec()` and `process()` take the same typed fluent configuration, and each
adds one option of its own. `exec()` collects the whole output, so it caps the
total:

```php
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;

Cmd::exec(['npm', 'install'])
    ->withId(EffectId::named('npm-install'))
    ->withWorkingDirectory('/app')
    ->withTimeout(120)
    ->withEnv(['NODE_ENV' => 'production'])
    ->withInput('yes')
    ->withMaxOutputBytes(4 * 1024 * 1024);
```

`process()` streams instead, so it sizes the chunk each `ProcessOutputMsg`
carries:

```php
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\EffectId;

Cmd::process(['npm', 'run', 'build'])
    ->withId(EffectId::named('npm-build'))
    ->withWorkingDirectory('/app')
    ->withTimeout(120)
    ->withEnv(['NODE_ENV' => 'production'])
    ->withInput('yes')
    ->withOutputChunkBytes(64 * 1024);
```

The same `EffectId` instance appears as `$message->effectId` on every result message, allowing precise routing and cancellation.

Cancel, a replacement under the same id, a timeout and quitting all stop the
process the command started. They do not stop processes that this process
starts itself. If you run a shell, start the real command with `exec`, so it
replaces the shell instead of running beside it:

```php
use Elmphant\Tui\Core\Cmd;

Cmd::exec(['sh', '-c', 'cd /app && exec npm install']);
```

## HTTP Requests

`Cmd::http()` runs through the same update loop as every other effect. Start the request with a command, then handle `HttpResultMsg`.

It needs `symfony/http-client` (`composer require symfony/http-client`). The
package itself requires only the client *contracts* — the interfaces — so an app
that never makes a request installs no HTTP client at all.

```php fragment
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\HttpResultMsg;

$packages = EffectId::named('packages');

// In onKey() or update():
$message instanceof KeyMsg && $message->key === 'r'
    => $this->withLoading(true)->then(
        Cmd::http('https://packagist.org/search.json?q=markdown')
            ->withId($packages)
            ->withMaxBodyBytes(4 * 1024 * 1024)
    ),

$message instanceof HttpResultMsg && $message->effectId?->equals($packages)
    => $this->withPackages(
        json_decode($message->body, true)['results'] ?? [],
    ),
```

## Widgets

Built-in widgets handle common UI patterns. All are immutable value objects.

| Widget | Description |
|--------|-------------|
| `Panel` | Scrollable container with border, title, focus management; Home/End, and `withFollowingTheEnd()` for a log — see [Scrolling](#scrolling) |
| `Header` | Top bar with title, left/center/right slots, optional divider |
| `Footer` | Bottom bar with slots; `Footer::forHints([...])` renders key hints |
| `FocusHints` | The keys whatever holds the keyboard answers, asked at render time |
| `Select` | Single-selection list with keyboard/mouse |
| `Radio` | Radio button group |
| `Checkbox` | Toggle checkbox |
| `CheckboxGroup` | Group of checkboxes |
| `TextInput` | Single-line text input with cursor, char limit, echo mode; underlined field bounds by default (`withDecoration(InputDecoration::Filled\|Underline\|None)`) |
| `Button` | Labelled press action |
| `Form` | Named fields as focus stops, with labels, required marks, inline problems and a submit button pinned to the foot — see [Forms](#forms) |
| `ChoiceField` | One of a set of values by label: a row of marks where they fit, a filterable list where they would wrap |
| `ProgressBar` | Fractional fill bar that springs towards its value over time (`withSpringFactor()`); a click sets it only after `withClickable()` |
| `ListView` | Scrollable list with custom `ListItem` rendering; wraps long item text, keeps an externally moved cursor visible |
| `Table` | Interactive table: cursor, selection, mouse, scrolling; constraint-based columns, wrapping cells (`Column::withWrap(Wrap::NoWrap)` cuts with `…` instead), optional header (`withHeader(false)`) |
| `StaticTable` | Static bordered table: draws every row, no cursor or scrolling, columns sized to content |
| `FilterableList` | Search field over a list: typing filters, arrows/Enter navigate and choose; `sourceIndex()` maps the cursor back to your items |
| `FilterableTable` | The same search field over a `StaticTable` |
| `TabBar` | Tabbed container with keyboard/mouse tab switching |
| `Split` | Two panes with a draggable divider |
| `TreeView` | Lazy tree — children load on expand (`pendingSince($before)` names what started waiting in the last update, so a key fetches each node once); the cursor is a path, so it survives reloads |
| `Modal` | Centered overlay with background dim — see [Modals](#modals) |
| `Dropdown` | Collapsed one-row control whose content (`Select`, `FilterableList`, …) floats above the layout. Enter/Space opens, Enter picks, Escape closes; typing filters only when the content filters (`Dropdown::new(FilterableList::new($items))`), and then Escape clears the filter first — `content()->query() !== ''` tells a host which. `withOpen()` opens it from the App, together with a `Cmd::focus()` to it |
| `Overlay` / `PositionedOverlay` | Stack widgets above a base layer |
| `Spinner` | Animated loading indicator (frame-clock driven, no wiring) |
| `ActivityBar` | Bouncing activity indicator (frame-clock driven, no wiring) |
| `Card` / `Box` / `Container` | Composition and decoration helpers; a `Card` body is a string or any widget |
| `Text` | Static text label |
| `Paragraph` | Multi-line text with wrapping, alignment, ANSI/Markdown |
| `Separator` | Horizontal line |
| `Spacer` | Empty space |
| `StatusBar` | Status line with key-hint rendering |
| `Scrollbar` | Vertical scroll indicator |

### Header, footer, and key hints

`Header` and `Footer` are the standard top/bottom bars for full-screen apps.

A widget names the keys it answers, so nothing keeps a label that can outlive the
binding it was copied from. `FocusHints` asks whatever holds the keyboard:

```php fragment
public function view(): Widget
{
    return Layout::vertical(
        Header::new('My App')->withDivider(),
        Component::bind('list', $this->list, static fn (self $app, ListView $list): self => $app->cloneWith(list: $list)),
        Footer::new()
            ->withLeft(StatusBar::forHints($this->quitHints()))
            ->withCenter(FocusHints::new()),
    );
}
```

With the keyboard on the list that footer reads `Ctrl+Q quit   ↑/↓ move   Enter
choose`, every label read off the bindings that actually matched. Rebind the list
and the footer follows; so does a `globalKeybindings()` override such as
`VimMode::keybindings()`.

Implement `PublishesHints` to say it in your own words — ask the widget for its
bindings instead of typing a label:

```php fragment
public function hints(): array
{
    return $this->list->effectiveKeybindings()->hints([
        'select' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN, Actions::PAGE_UP, Actions::PAGE_DOWN],
        'fill in' => Actions::SELECT,
    ]);
}
```

`Keybindings::hints()` takes `description => action`, or a list of actions where
one label covers several, and answers `label => description`. It names the key
each action leads with, writes a modifier they all share once (`Shift+←/→`), and
turns one that only some of them take into an optional:

```php
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;

$hints = Keybindings::rowNavigation()->hints([
    'select' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN, Actions::PAGE_UP, Actions::PAGE_DOWN],
    'choose' => Actions::SELECT,
]);

// ['(Shift+)↑/↓' => 'select', 'Enter' => 'choose']
```

`KeyPattern::label()` formats a single pattern: a capital letter as itself (`G`,
not `Shift+G`) and a chord as its keys are typed (`gg`, `gt/gT`).

While a chord is open, `FocusHints` swaps the hints for what can follow it, read
from `RenderContext::pendingChord()`: after `g` in vim mode, on a list inside a
`TabBar`, the footer reads
`g…     g  top   t  next tab   T  previous tab   Esc  cancel`, and goes back once the
chord completes, is cancelled or times out. On a list with no `TabBar` around it
`gt` answers nothing, so it is left out.

When the hints in a footer's left slot do not fit, the right slot gives way
first. Hand `withRight()` shorter forms after the first, longest to shortest:
the footer draws the first that leaves every hint whole, and no right slot at
all when even the shortest would not. Only then is a hint left out. A host that
hit-tests the right slot itself asks where it is drawn:
`$footer->rightSlotArea($area, $keyboard->hints())` in `onMouse()` answers the
rect of the form shown, or null once the slot has given way. The `Keyboard` an
`onMouse()` hook is handed says who holds the keyboard for that press
(`focused()`) and the hints its footer shows (`hints()`).

```php
use Elmphant\Tui\Widget\Bar\{FocusHints, Footer};
use Elmphant\Tui\Widget\Prose\Text;

Footer::new()
    ->withLeft(FocusHints::new())
    ->withRight(Text::new('Support · 1.2.0'), Text::new('Support'));
```

A bar places each slot in the width the slot's own `Sizing` declares — a bar is
not an allocator, so a flexible slot is not stretched to the row. A widget with
no width of its own (`ProgressBar`, `Separator`, and the other fill-only ones)
therefore has nothing to draw in; give it one:

```php
use Elmphant\Tui\Layout\{Constraint, Sizing};
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Feedback\ProgressBar;
use Elmphant\Tui\Widget\Structure\Container;

Footer::new()->withRight(
    Container::around(ProgressBar::new($ratio))
        ->withSizing(Sizing::of(Constraint::exact(20), Constraint::exact(1))),
);
```

### Input timing

A lone `Escape` is held for `ESCAPE_TIMEOUT` to see whether it begins a CSI
sequence. The flush runs on a timer with the loop otherwise idle, so it ticks as
well — draining and repainting. Without that the keystroke waits in the queue
until the next one arrives, and every bare `Escape` appears to take effect one
keypress late.

### Readability defaults

A few widget defaults exist because the alternative is unreadable, and are
covered by tests so they cannot regress:

- `Footer`/`StatusBar` paint `Theme::footerStyle` — body text on the selection
  background. Every built-in theme is asserted to meet WCAG AA (4.5:1) for hint
  text and 3:1 for the bold key labels.
- `ListView` marks the selected row even when the list does not have focus
  (apps often drive the cursor from a sibling search field). The selected row is
  accented and bold on the selection background, filled across every row of the
  item, and its description stays full strength — dim text on the highlight is
  invisible.
- `TextInput` shows its bounds across the full field width, underlined by
  default. Underline and fill are mutually exclusive; combining them reads as a
  heavy block and collides with selection and bar backgrounds.
- `Scrollbar` draws with block glyphs (`Scrollbar::THUMB`/`TRACK`), never
  box-drawing characters: a scrollbar beside a pane border must not read as a
  second border. `ListView` also reserves the scrollbar column plus a blank one,
  so item text is never clipped underneath the bar.
- `ListView` items wrap rather than truncate. `ListItem::height(int $width)` is
  width-aware for exactly this reason, and `ListItemLayout` is the single source
  of truth shared by height and rendering — if they disagreed, scroll offsets and
  click targets would drift from what is drawn. Item height is capped at
  `ListItemLayout::MAX_ROWS` with the final row ellipsized, so one long entry
  cannot turn a list into a paragraph.

### Animation

Animated widgets (`Spinner`, `ActivityBar`) read the monotonic frame clock
via `RenderContext::now()` and request their next frame with
`RenderContext::requestRepaint($delay)` during render. The runtime keeps
frames coming while such a widget is visible — dropping a `Spinner` into any
view animates without `Cmd::tick()`, bindings, or extra state.

### Widget Interfaces

Three levels, each extending the previous:

- **Widget** — `sizing()` + `render(buffer, rect, context)` — passive rendering only
- **Interactive** — adds `update(message, rect)` returning `UpdateResult` — responds to input
- **Focusable** — an empty marker: it can hold the keyboard. A widget never stores
  whether it does; it asks `$context->hasFocus()` while it draws

A fourth, separate contract lets a widget draw outside its own rect:

- **Floating** — `placement(anchor, viewport)` + `render(...)` — drawn after the whole
  tree, so it may cover other widgets
- **PointerFloating** — adds `target()` + `captures(action)` — takes pointer input over
  the area it drew

Two more say how a subtree takes the keyboard:

- **InputLayer** — owns input while it is on screen; everything behind it stops being
  focusable, tab-reachable and clickable. `Modal` uses this
- **FocusScope** — chrome the reader *steps into*: a filter row, a toolbar. Its
  components come off the main ring and become the whole ring once the keyboard is in
  one of them, so tab between the panes never walks controls touched once a session.
  A command (`Cmd::focus()`) takes the keyboard in, and another takes it out. Unlike an
  input layer it claims nothing while the keyboard is elsewhere

### The float layer

A widget asks for a float while it renders:

```php fragment
$context->float($frame, MyPopup::of(...), $area);   // $area is the anchor
```

The queue is drained after the whole tree has drawn, so floats always paint on top.
Rules worth knowing:

- **Never float yourself.** The float is a separate widget (`Dropdown` queues
  `DropdownPopup`); a widget that queued itself would be redrawn and requeued forever.
- **The drain is bounded.** A float may queue another float, and that one draws in the
  next round; after three rounds anything still queued is dropped.
- **Only a `PointerFloating` is recorded.** A plain `Floating` draws and is forgotten, so
  a decorative tooltip never eats a click.
- **One root pass per frame.** `FloatQueue::drawOver()` draws the tree and drains the
  queue; `App::render()` runs it for you. Running it twice in one frame throws: the second tree would paint over the first
  pass's floats while their placements still claimed the input over them.
- **Only what reached the screen can be clicked.** Every drawn `PointerFloating` is
  recorded with the rect it painted, and the runtime publishes that list after the frame
  is flushed. A frame that failed to render never becomes clickable.
- **A float too small to draw must return an empty placement**, or it masks input over
  blank cells.

Pointer events are then routed over that list. The topmost float covering the cell
decides: if its `captures()` accepts the action, the event arrives as a `FloatMouseMsg`
wrapping the original `MouseMsg`; if it declines, the plain `MouseMsg` continues to the
widget tree — never to a lower float, which the reader cannot see anyway. Which actions a
float takes is its own choice: `DropdownPopup` captures `Press`, `WheelUp` and
`WheelDown` and lets `Motion` and `Release` through, so a `Split` drag cannot latch on a
popup.

Ownership is a token (`FloatTarget`), not geometry — an `Overlay` hands two children the
same rect, so only object identity can tell whose float it is. **The owner enforces
this:** a widget receiving a `FloatMouseMsg` MUST check that the token is its own and
that its float is still open, and discard the message otherwise rather than acting on it.
That is what makes a fast double click safe — the first click picks and closes, the
second arrives still wrapped and dies on the closed owner.

**Host obligation:** a host that routes messages itself — an `App` overriding
`onMessage()`, a shell dispatching to panes, any `update()` matching
`instanceof MouseMsg` — MUST forward `FloatMouseMsg` wherever it forwards `MouseMsg`.
Broadcasting it to every pane is safe: ownership is token-checked and foreign messages
are dropped. A host that drops unknown message types silently swallows every click in a
popup: `FilterableList`, for one, forwards only `KeyMsg` today, so a `Dropdown` inside it
would never see one.

### Forms

A `Form` is a pane of named fields with a submit button pinned to its foot. Each
field is a real widget the keyboard stops at, so tab walks them and a click
lands on one, and what the form holds is asked of those widgets — the app
keeps no copy of its own:

```php
use Elmphant\Tui\Core\{ComponentId, Message};
use Elmphant\Tui\Widget\Form\{Field, Form, ShowErrors};

final readonly class CreateUser implements Message {}

$form = Form::new('Create backend user')
    ->withField(Field::text('username', 'Username', 'The login name')->required())
    ->withField(Field::text('password', 'Password')->secret())
    ->withField(Field::choice('site', 'Site', ['main' => 'Main website', 'shop' => 'Shop']))
    ->withField(Field::flag('admin', 'Admin', 'Create the user with admin rights'))
    ->withSubmit('Run', new CreateUser(), foot: '❯ backend:user:create');

$tried = $form->submitted();                          // from now on, problems show under their fields
$tried->missing();                                    // ['username']
$tried->stopOf('username', ComponentId::named('form')); // where Cmd::focus() sends the keyboard
$form->values();                                      // ['username' => '', 'password' => '', 'site' => null, 'admin' => false]
$form->withErrorsShown(ShowErrors::AfterLeaving);     // or tell a field's problem as soon as the reader leaves it
```

Bind it like any pane (`Component::bind('form', $this->form, …)`) and answer the
submit message in `onMessage()`. `withIntro()` puts what the form is about above the
fields, `withFoot()` and `withSubmitDisabled()` say what the button will do or why it
cannot, and `withCancel()` names the message an escape sends. `Field::choice()` shows a row of marks where they
fit and collapses into a filterable list where they would wrap; `Field::of()`
takes any widget that implements `HoldsAValue`.

`required()` is one check; `withRule()` adds your own. A rule gets what the field
holds and answers the problem to tell under it, or `null`; rules add up, the
first problem wins, and an empty field never reaches them:

```php
use Elmphant\Tui\Widget\Form\Field;

Field::text('due', 'Due date')
    ->withRule(static fn (string $date): ?string => strtotime($date) === false ? 'Not a date' : null);
```

`withValues(['username' => 'ada', 'admin' => true])` fills fields in by name, for
an edit form; a name the form does not have throws. Enter in a field that leaves
it alone moves to the next field, and in the last one presses submit.

### Modals

A `Modal` is an input layer: while it is on screen, everything behind it stops
taking keys and clicks, and the keyboard moves inside it. Bind it — an unbound
`Modal` around a bound component throws — and keep what it holds in the App, not
the modal itself. Escape, or a click outside it, sends `ModalDismissed`; closing
is the App's to do:

```php
use Elmphant\Tui\Application\{App, Component};
use Elmphant\Tui\Core\{Message, UpdateResult};
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Form\{Field, Form};
use Elmphant\Tui\Widget\Popup\{Modal, ModalDismissed, Overlay};
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;

final readonly class NoteSaved implements Message {}

final readonly class Notes extends App
{
    /** @param list<string> $notes */
    public function __construct(private array $notes = [], private ?Form $editor = null) {}

    public function view(): Widget
    {
        $page = Text::new($this->notes === [] ? 'Ctrl+N adds a note' : implode("\n", $this->notes));

        if ($this->editor === null) {
            return $page;
        }

        $form = Component::bind('editor', $this->editor, static fn (self $app, Form $editor): self => $app->cloneWith(editor: $editor));

        return Overlay::new($page, Component::bind(
            'modal',
            Modal::new('', $form)->withBorder(false)->withSizedToContent(),
            static fn (self $app): self => $app,
        ));
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        if ($this->editor !== null || !$message->ctrl || $message->key !== 'n') {
            return UpdateResult::ignored($this);
        }

        return new UpdateResult($this->cloneWith(editor: Form::new('New note')
            ->withField(Field::text('title', 'Title')->required())
            ->withSubmit('Save', new NoteSaved())));
    }

    protected function onMessage(Message $message, Rect $screen): UpdateResult
    {
        return match (true) {
            $message instanceof ModalDismissed => new UpdateResult($this->cloneWith(editor: null)),
            $message instanceof NoteSaved && $this->editor?->problems() === [] => new UpdateResult(
                $this->cloneWith(notes: [...$this->notes, (string)$this->editor->values()['title']], editor: null),
            ),
            $message instanceof NoteSaved => new UpdateResult($this->cloneWith(editor: $this->editor?->submitted())),
            default => UpdateResult::ignored($this),
        };
    }
}
```

When the modal closes, the keyboard goes back to where it was before.
`withBorder(false)` drops the modal's frame when the content draws its own,
`withOutsideClickDismissal(false)` keeps a half-filled form open on a stray
click, and `content()` answers what the modal holds.

### Scrolling

A `Panel` (and the `ScrollPane` under it) scrolls with the wheel, PageUp/PageDown
and Home/End, and brings a tabbed-to child into view. `scrollOffset($area)`
answers the rows scrolled past when drawn in that area. For a log or a chat,
`withFollowingTheEnd()` keeps the last row in view as children arrive, until the
reader scrolls up; scrolling back to the end follows again. Keep the pane in the
App and add to it (or rebuild its rows with `withChildren()`), so the follow
survives:

```php
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;

$log = Panel::new('Log')->withFollowingTheEnd();
$log = $log->add(Text::new('build finished'));
```

### Lists keep their place

A reload keeps the cursor and the selection on the same row, found by its key.
The key defaults to the text shown, so give rows that can share a text, or whose
text changes, a key of their own: `new SimpleListItem($title, $description, key: $id)`
(`ListItem::key()` on your own items), `Row::withKey($id)` and
`new TabPage($label, key: $id)`. When the cursor's row is gone, it moves to a
neighbour.

### Example: Settings Form

```php
use Elmphant\Tui\Widget\Choice\{Radio, Select};
use Elmphant\Tui\Widget\Feedback\ProgressBar;
use Elmphant\Tui\Widget\Prose\{Separator, Spacer, Text};
use Elmphant\Tui\Widget\Structure\Panel;

$left = Panel::new('Preferences')
    ->add(Text::accent('Appearance'))
    ->add(Spacer::new())
    ->add(Radio::new(['Dark', 'Light', 'Dracula', 'Nord'], 'Theme'))
    ->add(Separator::new())
    ->add(Text::new('Font'))
    ->add(Select::new(['JetBrains Mono', 'Fira Code', 'Cascadia']));

$right = Panel::new('System')
    ->add(Text::new('CPU'))
    ->add(ProgressBar::new()->withProgress(0.73))
    ->add(Text::new('Memory'))
    ->add(ProgressBar::new()->withProgress(0.45));
```

## Program Options

```php
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\Program;

Program::new($model)
    ->withAltScreen()       // use alternate screen buffer
    ->withMouseTracking()   // enable mouse click/scroll/drag
    ->withFps(30)           // frame rate limit (default: 60)
    ->withTheme(Theme::dracula()) // the theme to start in
    ->onError(fn(ErrorMsg $error) => null) // may return a recovery Cmd
    ->start();
```

To switch themes while running, answer `App::theme()` from a field of the App;
it is asked before every frame.

Without `withAltScreen()` the program still draws over the whole visible screen, and its last frame stays there after it exits. There is no inline mode that keeps to a few rows below the prompt.

A throw in `view()` arrives at `onError()` as `ErrorSource::Render`, like one in `render()`. An error your handler returns from normally never stops the program. Ten failures in a row that nothing recovers from — an `update()` that keeps throwing, a `render()` that never succeeds, or a handler that throws — stop it, and `start()` rethrows the last of them after the terminal is restored.

A `Program` is intentionally one-shot. Its lifecycle is `ProgramState::New → Running → Stopped`; create a fresh `Program` to run a model again. Configuration methods are valid only while the state is `New`.

The built-in interactive terminal backend currently targets POSIX TTYs. It fails explicitly when stdin is not a TTY, `proc_open()`/`stty` is unavailable, or the platform is Windows. Inject a custom `Terminal` for another backend or for tests (`SystemTerminal` is the POSIX one, `VirtualTerminal` the in-memory one).

## Tracing

A TUI cannot print diagnostics — stdout is the display — so a `Tracer` writes them
somewhere else:

```php
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\Trace\JsonlTracer;

Program::new($model)->withTracer(JsonlTracer::toFile('/tmp/tui.jsonl'))->start();
```

One JSON object per line, for grepping and totalling:

| Event | Fields |
| --- | --- |
| `input.read` | `bytes`, `queued` — how much arrived, and how much was still waiting |
| `update` | `message`, `key` as a binding would spell it, `focused`, `ring`, `ms`, `changed` |
| `focus` | `from`, `to` |
| `command` | `command` |
| `render` | `ms`, `paintMs`, `diffMs`, `bytes`, `blockedMs`, `drawn` |
| `frame.skipped` | `waitMs` — the frame budget was not yet spent |
| `log` | `message` and the context of a `Cmd::log('loaded', ['items' => 3])` — how an `update()` says what it saw, since echoing would write into the screen |
| `output` | `during` (`init`, `update`, `render`) and `text` — what the model echoed or dumped, caught before it reached the terminal and broke the screen |
| `error` | `source` (`update`, `render`, `command`, `process`, … or `fatal` for the one the program gave up on), `class`, `message`, `file`, `line`, `trace` — recorded even when the model swallows the `ErrorMsg` |

Every message a widget or command produces reaches the model through one queue,
and every effect through one dispatch, so these cover the runtime's whole flow —
but not what a widget does privately inside its own `update()`. `focus` exists
because no update reports the keyboard moving: that is the runtime's doing rather
than the model's.

`update`'s `focused` and `ring` are what a "that key does nothing" report turns
on: a ring with one entry has nowhere for tab to go, and a key traced under a
name no binding uses never arrived as the one that was pressed. `blockedMs` is
the time the terminal made the program wait for it to accept output, and
`paintMs`/`diffMs` split a frame into drawing it and sending the changed cells —
that split says whether a slow frame is your widgets or the wire.

To trace without touching the code, set `ELMPHANT_TRACE` and follow the file
from a second terminal while the app runs:

```bash
ELMPHANT_TRACE=/tmp/tui.jsonl php app.php
tail -f /tmp/tui.jsonl
```

That tracer is `JsonlTracer::live()`: it writes each event as it happens, so a
crash loses nothing. `JsonlTracer::toFile()` buffers instead, which costs no syscall per event — use
it when profiling. The program writes it out when it stops, a fatal error
included. In tests use
`Elmphant\Tui\Testing\InMemoryTracer` and assert on the flow directly:

```php fragment
$tracer = new InMemoryTracer();
Program::new($app)->withTracer($tracer)->start();

self::assertSame(0.0, $tracer->totalOf(TraceEvent::Render, 'blockedMs'));
```

## Debugging

The app owns the terminal, so debug it from a second one:

1. **Watch the trace.** Start with `ELMPHANT_TRACE=/tmp/tui.jsonl php app.php`
   and run `tail -f /tmp/tui.jsonl` beside it. Every key, update, render and
   command shows up as it happens.
2. **Say what you saw.** Return `Cmd::log('loaded', ['items' => count($rows)])`
   from an update instead of echoing; it lands in the trace as a `log` event.
   A stray `echo` or `var_dump` is caught and traced as `output` rather than
   written over the screen.
3. **Read the error.** Anything that throws is traced as `error` with its class,
   message, file and line, even when your model handles the `ErrorMsg`. When ten
   failures in a row end the program, the terminal is restored first and the
   exception is printed on a clean screen.
4. **Step through it.** Run with Xdebug (`XDEBUG_MODE=debug XDEBUG_SESSION=1 php app.php`)
   and let your IDE listen for the connection. Breakpoints pause the program;
   the terminal stays in raw mode until you continue, so keep the IDE in a
   different window.

## Static analysis for your own app

The package ships the seven PHPStan rules it enforces on itself. Include
`rules.neon` and your application is held to the same invariants — the ones a
test cannot catch because the code is correct today and wrong the day something
moves:

```neon
includes:
    - vendor/elmphant/tui/rules.neon
```

| Rule | What it catches |
| --- | --- |
| `CompleteRebuildRule` | A `new self(...)` that rebuilds with fewer arguments than the constructor takes, silently resetting the fields it left out |
| `NamedRebuildRule` | A positional rebuild — correct today, wrong the day a constructor parameter moves |
| `WidgetDoesNotPublishAnAxisPolicyRule` | A `Widget` handing out a bare `Constraint`, which does not say which axis it means; widgets answer with a `Sizing` |
| `FocusIsAskedWhileDrawingRule` | A widget reading its own focus flag while rendering, which is one message behind by the time the frame is drawn |
| `ReachableChildrenRule` | A widget rendering a child it never exposes through `WidgetContainer`, leaving anything bound inside it unreachable to focus and discovery |
| `KeyNameIsNotTextRule` | `$message->key === 'esc'` and the like: a named key arrives as a `Key` case, so the comparison never matches |
| `SplitIsBoundRule` | A `Split` an App draws outside every `Component::bind()`, which never hears a divider drag |

`ReachableChildrenRule` takes an allowlist for the places you have decided otherwise:

```neon
parameters:
    elmphant:
        reachableChildrenAllowList:
            - App\Widget\Decoration
```

## Messages

All messages flow through `update()` via the unified `Elmphant\Tui\Message` namespace:

| Message | When |
|---------|------|
| `KeyMsg` | Any key press. Properties: `key` (a `Key` case or text), `ctrl`, `alt`, `shift`; `isText()` |
| `MouseMsg` | Mouse click/scroll/drag. Methods: `isClick()`, `inRect()` |
| `PasteMsg` | Bracketed paste. Property: `content` |
| `TickMsg` | Periodic timer fired (from `Cmd::tick()`). Properties: `timestamp`, `effectId`; `isFrom('clock')` |
| `ResizeMsg` | Terminal resized. Properties: `width`, `height` |
| `HttpResultMsg` | `Cmd::http()` completed. Properties: `statusCode`, `body`, `headers`, `effectId` |
| `ProcessResultMsg` | `Cmd::exec()` completed |
| `ProcessOutputMsg` | `Cmd::process()` output chunk |
| `ProcessFinishedMsg` | `Cmd::process()` exited |

## Testing

Use `ModelHarness` for most application tests. It drives the same
`Model::update()` and `Model::render()` contracts as the runtime, but performs
no terminal I/O and never executes commands:

```php fragment
use Elmphant\Tui\Testing\ModelHarness;

$app = ModelHarness::for(new Counter(), width: 40, height: 5)->press('k');

self::assertSame(
    'Count: 1',
    $app->renderScreen()->textAt(0, 0, 8),
);
self::assertNull($app->lastCommand());

$app->press('x');
self::assertNotNull($app->lastCommand()); // described, not executed

// Input goes in the way a reader gives it, in the key spelling Keybindings use:
$app->press('tab', 'shift+tab', 'ctrl+r', 'enter');
$app->type('cache:flush');
$app->click('Run');          // where "Run" is drawn now: press and release
$app->click('search', offset: 7);   // seven columns to the right of it
$app->wheel('Output', 3);    // three notches down; -3 is up
$app->paste("two\nlines");   // a bracketed paste
$app->drag(30, 5, 40, 5);    // press at one cell, move, release at another

// Effects are described, never run: the test sees what was asked for and answers it.
$app->issued(ExecCmd::class);                     // batches, maps and scopes taken apart
$app->answer('load', stdout: '{"items": []}');    // ProcessResultMsg for Cmd::exec()
$app->stream('tail', "a line\n");                 // ProcessOutputMsg for Cmd::process()
$app->finish('tail', exitCode: 0);                // ProcessFinishedMsg
$app->respond('page', 200, '<html>');             // HttpResultMsg for Cmd::http()
$app->tick('clock', at: 12.5);                    // one beat of a running Cmd::tick()
$app->fail('load', 'timed out');                  // ErrorMsg from that effect
$app->emit('rows', new RowsLoaded([]));           // what an effect of your own hands its $emit
$app->runCalls();                                 // run the Cmd::call()s issued so far
```

An answer arrives the way `Program` delivers it: with the `EffectId` the effect ran
under, scope included, and through every `Cmd::map()` it was handed through, so a
parent that addresses its children's results gets them addressed. Answering an
effect the model never started, or one that has already ended, throws: the test
learns the model did not ask for what the test assumed. When the same name runs
in two scopes, name the one meant: `EffectId::named('load')->within('dashboard')`.
A test that calls `update()` itself takes the command it got apart the same way:
`Issued::in($result->command, ExecCmd::class)`.

A test that calls `update()` itself builds the same messages with `UserInput`:
`UserInput::key('shift+tab')`, `UserInput::typed('ok')`, `UserInput::click($column, $row)`,
`UserInput::drag($column, $row)` (a motion with the button held), `UserInput::release($column, $row)`
and `UserInput::paste('text')`.

Call `initialize()` to capture the model's initial command, `resize()` to test
responsive behavior, `model()` to assert typed application state, and
`renderFrame()` when cell styles or cursor data matter. PHPStan and Psalm infer
the concrete model type through the harness.

A tree containing bound components cannot be drawn without saying where the
keyboard is: the drawing refuses it rather than produce a frame no terminal
would show — every field unfocused, and a popup belonging to the focused one
missing. `ModelHarness` and `Program` always say. A test that renders a tree by
hand says so too, and `FocusPath::nowhere()` is how it says "nobody":

```php
use Elmphant\Tui\Core\{ComponentId, FocusState};
use Elmphant\Tui\Rendering\Context\{FocusPath, RenderPass};
use Elmphant\Tui\Rendering\Theme;

$pass = RenderPass::new(Theme::dark(), focus: FocusPath::nowhere());   // a layout or wording test
$app->render($frame, $pass);

$pass = RenderPass::new(Theme::dark());                                 // or name the holder
$pass->focus->focusOn(new FocusState(ComponentId::named('search')));
```

Not deciding is a different state from deciding nobody holds it: `FocusState::nowhere()`
is an answer the runtime never fills in, which is what lets an app keep the keyboard
for itself with `Cmd::focusNothing()`.

A widget is tested without an app around it: `WidgetHarness` hosts it, gives it the
keyboard, and offers the same input, screen and effect methods as `ModelHarness`.
The fields of a `Panel` are stops `tab` walks, popups open, and the result of an
effect the widget started comes back to it:

```php
use Elmphant\Tui\Testing\WidgetHarness;
use Elmphant\Tui\Widget\Field\TextInput;

$field = WidgetHarness::for(TextInput::new('name'), width: 20, height: 1)->type('Ada');

$field->widget()->value();       // 'Ada', the widget as the last update left it
$field->screen()->rowOf('Ada');  // 0
```

It draws floats too, so an open `Dropdown` shows its list in `screen()`.

A `ScreenBuffer` answers where text is and how a cell looks, so a test never
counts columns or indexes `Frame` arrays by hand:

```php
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Testing\ScreenBuffer;

$frame = new Frame(20, 2);
$frame->putString(4, 1, 'Run');
$screen = ScreenBuffer::fromFrame($frame);

$screen->find('Run');     // ['row' => 1, 'column' => 4], in display columns
$screen->find('Run', 1);  // the second match, or null
$screen->find('Run', within: new Rect(10, 0, 10, 2));   // only matches starting in that area
$screen->locate('Run');   // like find(), but throws '"Run" is not on screen'
$screen->rowOf('Run');    // 1
$screen->lineAt(1);       // one row as text, the full width
$screen->plainText();     // every row without trailing blanks, joined by newlines
$screen->textAt(4, 1, 3);  // 'Run': column, then row, as the frame was written
$screen->styleAt(4, 1);   // a Style: foreground, background, attributes
```

Use `VirtualTerminal` only for runtime integration tests that need ANSI output,
input bytes, resizing, or terminal capabilities. `ScreenBuffer::fromFrame()`
provides Unicode-safe screen assertions without parsing an ANSI stream, while
`GoldenScreen` is useful for larger snapshots. The `TerminalAssertions` trait
adds `assertScreenContains()`, `assertTextAt()`, `assertCursorAt()` and
`assertCursorHidden()` over a `ScreenBuffer` — never name a test after one of
them, or the test overrides the helper its own body calls and recurses until the
process is killed.

`Program::run()` and `Program::start()` return `Model` natively and preserve the
concrete model type for static analysis, so callers can inspect final state
after a program exits.

## Rendering

Widget-based apps normally render through `view()`. If you implement `Model` directly, draw into a `Frame` — a 2D grid of cells. The framework diffs it against the previous frame and only outputs changes.

For `App` models, routing and rendering share a cached `LayoutPlan` whenever
geometry was already resolved for that immutable App and terminal area.
Render-only paths stay direct, avoiding plan allocation when routing does not
need rectangles. Scrollable `Panel` and `TabBar` renderers only position
visible rows on that direct path, so large content collections do not allocate
one rectangle per off-screen row. Their immutable height measurements are
weakly cached by container identity and viewport, with automatic invalidation
when either changes. Wrapped content is finalized at the actual child width
after reserving the scrollbar column, so scroll bounds and rendered lines stay
in sync. The `*PerfTest.php` files hold the cold/warm large-container plus
narrow, medium, and wide end-to-end performance budgets, so `composer test`
keeps them honest.

```php fragment
public function render(Frame $frame, RenderPass $pass): void
{
    // Text with color
    $frame->putString(0, 0, 'Hello', Color::hex('FF06B7')->value);

    // With background and attributes
    $frame->putString(0, 1, 'Bold text', Color::ansi(7)->value, Color::ansi(0)->value, Attr::BOLD);

    // Fill a rectangle
    $frame->fillRect(0, 3, 40, 1, Color::ansi(7)->value, Color::ansi(4)->value);

    // Trusted, application-authored SGR works too
    $frame->putAnsi(0, 5, "\e[1;31mred bold\e[0m normal");
}
```

`putString()` and every widget text property are the safe boundary for ordinary
text. They discard terminal control sequences, C0/DEL controls (except tab and
line breaks), raw C1 bytes, and UTF-8-encoded C1 controls. This makes log lines,
process output, HTTP responses, and user input inert by default.

`putAnsi()` is deliberately a trusted-code API for application-authored SGR.
Never pass external or user-controlled text to it. If trusted and untrusted
fragments must be combined, sanitize the untrusted fragment first with
`TerminalText::plain()`, or render the fragments separately with `putString()`.
Use `TerminalText::singleLine()` for terminal-protocol payloads such as titles;
it also removes tabs and line breaks.

## License

MIT
