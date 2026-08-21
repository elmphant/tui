# Elmphant TUI

**The Elm Architecture for terminal UIs in PHP.** Inspired by Go's [Bubbletea](https://github.com/charmbracelet/bubbletea) and Rust's [Ratatui](https://github.com/ratatui/ratatui).

The name is a play on the **PHP elephant** and the **ELM architecture** used under the hood.

PHP 8.2+ | MIT License

## Quick Start

```bash
composer require elmphant/tui
```

Elmphant requires PHP 8.2+, `ext-mbstring`, and `ext-intl`. Interactive
programs target POSIX terminals; models and widgets can be tested on any
platform without opening a terminal.

## Hello World

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Elmphant\Tui\Core\{App, Cmd, UpdateResult};
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Widget\{Text, Widget};

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
            'k' => UpdateResult::changed($this->withCount($this->count + 1)),
            'j' => UpdateResult::changed($this->withCount($this->count - 1)),
            'x' => $this->withCmd(Cmd::quit()),
            default => UpdateResult::keep($this),
        };
    }

    public function view(): Widget
    {
        return Text::new("Count: {$this->count}  j/k change, q or x quit");
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

Most applications should extend `App`. It gives you widget-based rendering, screen-aware mouse handling, reserved Ctrl-Q/Ctrl-C quit shortcuts (both live in `applicationKeybindings()` — override it to rebind or intercept them, e.g. for a confirm-before-quit dialog), default `q` quit for simple non-focusable apps (only while no focusable component is mounted — override `quitBindings()` to change), an `onResize()` hook, and focus delegation. In `onKey()`, returning a changed model or any command consumes the key; `UpdateResult::keep($this)` passes it to the focused component.

```php
abstract readonly class App
{
    abstract public function view(): Widget;                  // pure UI description

    protected function setup(): ?Cmd { return null; }         // initial commands
    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult { return UpdateResult::keep($this); }
    protected function onMouse(MouseMsg $message, Rect $screen): UpdateResult { return UpdateResult::keep($this); }
    protected function onTick(TickMsg $message, Rect $screen): UpdateResult { return UpdateResult::keep($this); }
    protected function onMessage(Message $message, Rect $screen): UpdateResult { return UpdateResult::keep($this); }
}
```

Hooks always return `UpdateResult`: use `UpdateResult::keep($this)` when nothing changes, `UpdateResult::changed(...)` for a state transition, or `$this->withCmd(...)` when a transition also needs a command. `App` deliberately has no reflection-based `with()` method. The optional `Cloneable` trait remains available for compact constructor-compatible value objects, but explicit typed methods give static analysers the strongest contract.

Keep stateful widgets as properties on the model, not local variables rebuilt inside `view()`. `view()` should compose the current state into a widget tree; if you recreate a `TextInput`, `Panel`, `Select`, or `Table` in `view()`, its cursor, selection, scroll, and focus state will be reset.

```php
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
before the focused widget receives the key.

Replacement closures must be declared `static` and may capture scalar
configuration only. `Component::bind()` rejects closures bound to `$this` or
capturing object state, preventing a cached view from writing an updated widget
back into a stale App value. Always update the supplied `$app`; prefer explicit
typed methods such as `$app->withForm($form)` when the transition has
invariants. PHPStan also infers one App type across the closure input and
return.

### Responsive terminal layouts

Use `Layout::horizontal()` and `Layout::vertical()` for simple, fixed
compositions. Use `Flex` when sibling widgets should reflow as the terminal
width changes:

```php
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Flex;

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
`flex` and `shrink` are relative weights; `min` and `max` bound normal
resizing. If declared minimums cannot fit, terminal bounds take precedence so
children never receive rectangles outside their container. Builders are
immutable, so a layout remains a pure value in `view()`.

This is intentionally a terminal-oriented subset rather than CSS Flexbox:
row/column direction, wrapping, cell gaps, basis/min/max, grow/shrink, and
start/center/end/space justification. Browser-specific concepts such as
percentages, ordering, reverse directions, and a full alignment model are not
part of the API.

### Universal widget decoration

Wrap any widget in an immutable `Container` when spacing and presentation
belong to the composition rather than the widget itself:

```php
use Elmphant\Tui\Layout\{Constraint, Sizing, Spacing};
use Elmphant\Tui\Rendering\{BorderStyle, Color, Style};
use Elmphant\Tui\Widget\{Alignment, Container, VerticalAlignment};

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
    public function render(Frame $frame, RenderContext $context): void;
}
```

**UpdateResult** is an immutable pair `(model, ?command)` returned from every `update()` call. Never mutate application state in place. For plain `Model` implementations, use the `Cloneable` trait or your own `with()` method.

**Immutability model.** Models and widgets are immutable values: `update()` returns a fresh model (or `$this` when nothing changed, thanks to `Cloneable::cloneWith()`'s no-op elision). Prefer public, typed `withX()` builders in application code. The render target — `Frame` — is mutable for performance. This matches Ratatui, Iced, and Bubbletea conventions.

## Commands

Commands are how your model requests side effects. They are data, not executed inline.

```php
Cmd::quit()                         // stop the program
Cmd::tick(0.05)                     // send TickMsg every 50ms
Cmd::after(2.0, fn() => $msg)       // send one delayed message or command
Cmd::batch($cmd1, $cmd2)            // dispatch multiple commands
Cmd::message($message)              // enqueue a Message immediately
Cmd::call(fn() => $message)          // run a short synchronous side effect
Cmd::http('https://example.com')     // non-blocking HTTP request

// Process execution (powered by symfony/process)
Cmd::exec(['git', 'status'])        // run command, get full result
Cmd::process(['tail', '-f', 'log']) // stream output in real-time
```

Effects that may need cancellation share a typed identity:

```php
$download = EffectId::named('download');

Cmd::http('https://example.com/archive')->withId($download);
Cmd::cancel($download); // also works for tick(), after(), exec(), and process()
```

`Cmd::call()` is an escape hatch for short synchronous side effects. Its callback
may return a message, another command, or `null`; thrown exceptions are delivered
as `ErrorMsg` with source `call`. It runs on the event-loop thread, so it must not
perform slow I/O or CPU-heavy work. Use `Cmd::http()`, `Cmd::exec()`, or
`Cmd::process()` for non-blocking work.

Other callbacks passed to commands are message factories. They should not mutate
the model or write directly to the terminal.

Application-defined messages implement the empty `Message` marker:

```php
use Elmphant\Tui\Message\Message;

final readonly class SearchSubmitted implements Message
{
    public function __construct(public string $query) {}
}
```

### Composing child widgets

Use `Cmd::map()` to keep a reusable child in its own message namespace. The
mapping applies to every message emitted by the command, including future
ticker/timer events, HTTP results, process chunks, completion messages, and
errors.

```php
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

## Running Processes

Two modes for executing OS commands, both non-blocking:

### `Cmd::exec()` — Run and collect

Runs a command in the background. Sends a single `ProcessResultMsg` when it finishes with stdout, stderr, and the exit code. Collected output is capped at 8 MiB by default; exceeding the limit produces `ErrorMsg` with `EffectOutputLimitExceeded`.

```php
use Elmphant\Tui\Message\ProcessResultMsg;

// In update():
$message instanceof KeyMsg && $message->key === 'r'
    => new UpdateResult($this, Cmd::exec(['git', 'log', '--oneline', '-10'])),

$message instanceof ProcessResultMsg
    => new UpdateResult($this->withOutput($message->stdout)),
```

### `Cmd::process()` — Stream output

Runs a command and streams output as it arrives. Sends bounded `ProcessOutputMsg` chunks (64 KiB by default), clears consumed Symfony Process buffers, and sends `ProcessFinishedMsg` when the process exits.

```php
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

The same `EffectId` instance appears as `$message->processId`, allowing precise routing and cancellation.

## HTTP Requests

`Cmd::http()` runs through the same update loop as every other effect. Start the request with a command, then handle `HttpResultMsg`.

```php
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\HttpResultMsg;

$packages = EffectId::named('packages');

// In onKey() or update():
$message instanceof KeyMsg && $message->key === 'r'
    => $this->withLoading(true)->withCmd(
        Cmd::http('https://packagist.org/search.json?q=markdown')
            ->withId($packages)
            ->withMaxBodyBytes(4 * 1024 * 1024)
    ),

$message instanceof HttpResultMsg && $message->id?->equals($packages)
    => $this->withPackages(
        json_decode($message->body, true)['results'] ?? [],
    ),
```

## Widgets

Built-in widgets handle common UI patterns. All are immutable value objects.

| Widget | Description |
|--------|-------------|
| `Panel` | Scrollable container with border, title, focus management |
| `Header` | Top bar with title, left/center/right slots, optional divider |
| `Footer` | Bottom bar with slots; `Footer::hints([...])` renders key hints |
| `FocusHints` | The keys whatever holds the keyboard answers, asked at render time |
| `Select` | Single-selection list with keyboard/mouse |
| `Radio` | Radio button group |
| `Checkbox` | Toggle checkbox |
| `CheckboxGroup` | Group of checkboxes |
| `TextInput` | Single-line text input with cursor, char limit, echo mode; underlined field bounds by default (`withDecoration(InputDecoration::Filled\|Underline\|None)`) |
| `ProgressBar` | Fractional fill bar with spring animation |
| `ListView` | Scrollable list with custom `ListItem` rendering; wraps long item text, keeps an externally moved cursor visible |
| `Table` | Interactive table: cursor, selection, mouse, scrolling; constraint-based columns, wrapping cells, optional header (`withHeader(false)`) |
| `StaticTable` | Static bordered table: draws every row, no cursor or scrolling, columns sized to content |
| `TabBar` | Tabbed container with keyboard/mouse tab switching |
| `Modal` | Centered overlay with background dim |
| `Dropdown` | Collapsed one-row control with a filterable option list that floats above the layout. Enter/Space opens, typing filters, Enter picks, Escape clears a typed filter before it closes; `hasFilterText()` lets a host label that key |
| `Overlay` / `PositionedOverlay` | Stack widgets above a base layer |
| `Spinner` | Animated loading indicator (frame-clock driven, no wiring) |
| `ActivityBar` | Bouncing activity indicator (frame-clock driven, no wiring) |
| `Card` / `Box` / `Row` / `Column` / `Container` | Composition and decoration helpers |
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

```php
public function view(): Widget
{
    return Layout::vertical(
        Header::new('My App')->withDivider(),
        Component::bind('list', $this->list, static fn (self $app, ListView $list): self => $app->cloneWith(list: $list)),
        Footer::new()
            ->left(StatusBar::hints($this->quitHints()))
            ->center(FocusHints::new()),
    );
}
```

With the keyboard on the list that footer reads `Ctrl+Q quit   ↑/↓ move   Enter
choose`, every label read off the bindings that actually matched. Rebind the list
and the footer follows; so does a `globalKeybindings()` override such as
`VimMode::keybindings()`.

Implement `PublishesHints` to say it in your own words — ask the widget for its
bindings instead of typing a label:

```php
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

`KeyPattern::label()` formats a single pattern.

A bar places each slot in the width the slot's own `Sizing` declares — a bar is
not an allocator, so a flexible slot is not stretched to the row. A widget with
no width of its own (`ProgressBar`, `Separator`, and the other fill-only ones)
therefore has nothing to draw in; give it one:

```php
Footer::new()->right(
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

### Tracing what the runtime does

A TUI cannot print its own diagnostics — stdout is the display. Attach a tracer
and the runtime writes what it did to a file instead:

```php
use Elmphant\Tui\Runtime\Trace\JsonlTracer;

Program::new($app)
    ->withTracer(JsonlTracer::toFile('tui-trace.jsonl'))
    ->start();
```

One JSON object per line, so a trace can be grepped or totalled:

| Event | Fields |
| --- | --- |
| `input.read` | `bytes`, `queued` — how much arrived, and how much was still waiting |
| `update` | `message`, `ms`, `changed` |
| `command` | `command` |
| `render` | `ms`, `paintMs`, `diffMs`, `bytes`, `blockedMs`, `drawn` |
| `frame.skipped` | `waitMs` — the frame budget was not yet spent |

Every message a widget or command produces reaches the model through one queue,
and every effect through one dispatch, so these cover the runtime's whole flow —
but not what a widget does privately inside its own `update()`.

`blockedMs` is the time the terminal made the program wait for it to accept
output; `paintMs` and `diffMs` split a frame into drawing it and sending the
changed cells. That split is what tells you whether a slow frame is your widgets
or the wire.

Events are buffered and written when the program stops, so tracing does not cost
a syscall per event. In tests use `Elmphant\Tui\Testing\InMemoryTracer` and
assert on the flow directly:

```php
$tracer = new InMemoryTracer();
Program::new($app)->withTracer($tracer)->start();

self::assertSame(0.0, $tracer->totalOf(TraceEvent::Render, 'blockedMs'));
```

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
- **Focusable** — adds `isFocused()` + `withFocus(bool)` — can receive keyboard focus

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

```php
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
- **One root pass per frame.** `renderRoot()` draws the tree and drains the queue.
  Calling it twice in one frame throws: the second tree would paint over the first
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

### Example: Settings Form

```php
$left = Panel::new('Preferences')
    ->add(Text::styled('Appearance', Color::hex('FF06B7')->value, Attr::BOLD))
    ->add(Spacer::new())
    ->add(Radio::new('Theme', ['Dark', 'Light', 'Dracula', 'Nord']))
    ->add(Separator::new())
    ->add(Text::new('Font'))
    ->add(Select::new(['JetBrains Mono', 'Fira Code', 'Cascadia']))
    ->withFocus(true);

$right = Panel::new('System')
    ->add(Text::new('CPU'))
    ->add(ProgressBar::new()->withProgress(0.73))
    ->add(Text::new('Memory'))
    ->add(ProgressBar::new()->withProgress(0.45));
```

## Program Options

```php
Program::new($model)
    ->altScreen()       // use alternate screen buffer
    ->mouseTracking()   // enable mouse click/scroll/drag
    ->fps(30)           // frame rate limit (default: 60)
    ->onError(fn(ErrorMsg $error) => null) // may return a recovery Cmd
    ->start();
```

A `Program` is intentionally one-shot. Its lifecycle is `ProgramState::New → Running → Stopped`; create a fresh `Program` to run a model again. Configuration methods are valid only while the state is `New`.

The built-in interactive terminal backend currently targets POSIX TTYs. It fails explicitly when stdin is not a TTY, `proc_open()`/`stty` is unavailable, or the platform is Windows. Inject a custom `TerminalInterface` for another backend or for tests.

## Tracing

A TUI cannot print diagnostics — stdout is the display — so a `Tracer` writes them
somewhere else:

```php
Program::new($model)->withTracer(JsonlTracer::toFile('/tmp/tui.jsonl'))->start();
```

One JSON object per line, for grepping and totalling. `update` records the message,
the key as a binding would spell it, **which component held the keyboard, and every
stop it could have moved to**; `focus` records the keyboard moving, `from` and `to`,
which no update reports because it is the runtime's doing rather than the model's;
`render` records what a frame cost, split between painting and diffing; `input.read`,
`command` and `frame.skipped` cover the rest.

Those last two update fields are what a "that key does nothing" report turns on: a
ring with one entry has nowhere for tab to go, and a key traced under a name no
binding uses never arrived as the one that was pressed.

The trace is held in memory until the program stops, so a run killed outright writes
nothing — close it from a shutdown hook if you need a trace of a crash. In tests use
`InMemoryTracer` and read `fieldsOf(TraceEvent::Update)` instead of a file.

## Messages

All messages flow through `update()` via the unified `Elmphant\Tui\Message` namespace:

| Message | When |
|---------|------|
| `KeyMsg` | Any key press. Properties: `key`, `ctrl`, `alt`, `shift` |
| `MouseMsg` | Mouse click/scroll/drag. Methods: `isClick()`, `inRect()` |
| `TickMsg` | Periodic timer fired (from `Cmd::tick()`) |
| `ResizeMsg` | Terminal resized. Properties: `width`, `height` |
| `HttpResultMsg` | `Cmd::http()` completed. Properties: `statusCode`, `body`, `headers`, `id` |
| `ProcessResultMsg` | `Cmd::exec()` completed |
| `ProcessOutputMsg` | `Cmd::process()` output chunk |
| `ProcessFinishedMsg` | `Cmd::process()` exited |

## Testing

Use `ModelHarness` for most application tests. It drives the same
`Model::update()` and `Model::render()` contracts as the runtime, but performs
no terminal I/O and never executes commands:

```php
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Testing\ModelHarness;

$app = ModelHarness::for(new Counter(), width: 40, height: 5)
    ->dispatch(new KeyMsg('k'));

self::assertSame(
    'Count: 1',
    $app->renderScreen()->getTextAt(row: 0, col: 0, length: 8),
);
self::assertNull($app->lastCommand());

$app->dispatch(new KeyMsg('x'));
self::assertNotNull($app->lastCommand()); // described, not executed
```

Call `initialize()` to capture the model's initial command, `resize()` to test
responsive behavior, `model()` to assert typed application state, and
`renderFrame()` when cell styles or cursor data matter. PHPStan and Psalm infer
the concrete model type through the harness.

A tree containing bound components cannot be drawn without saying where the
keyboard is: `RenderContext` refuses it rather than produce a frame no terminal
would show — every field unfocused, and a popup belonging to the focused one
missing. `ModelHarness` and `Program` always say. A test that renders a tree by
hand says so too, and `RenderContext::unfocused($theme)` is how it says "nobody":

```php
$context = RenderContext::unfocused(Theme::dark());       // a layout or wording test
$context->renderRoot($app->view(), $frame, $area);

$context = RenderContext::unfocused(Theme::dark());       // or name the holder
$context->focusOn(new FocusState(ComponentId::named('search')));
```

Not deciding is a different state from deciding nobody holds it: `FocusState::nowhere()`
is an answer the runtime never fills in, which is what lets an app keep the keyboard
for itself with `Cmd::focusNothing()`.

Use `VirtualTerminal` only for runtime integration tests that need ANSI output,
input bytes, resizing, or terminal capabilities. `ScreenBuffer::fromFrame()`
provides Unicode-safe screen assertions without parsing an ANSI stream, while
`GoldenScreen` is useful for larger snapshots.

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

```php
public function render(Frame $frame, RenderContext $context): void
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
