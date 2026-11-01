# Running and debugging

[← README](../README.md)

## Program options

`Program::run($app)` is enough to start. For more control:

```php
use Elmphant\Tui\Message\ErrorMsg;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\Program;

Program::new($model)
    ->withAltScreen()       // use the alternate screen; the shell comes back on exit
    ->withMouseTracking()   // clicks, wheel and drags
    ->withFps(30)           // frame rate limit (default: 60)
    ->withTheme(Theme::dracula()) // the theme to start in
    ->onError(fn(ErrorMsg $error) => null) // may return a Cmd to recover
    ->start();
```

- Without `withAltScreen()` the app still uses the whole screen, and its last
  frame stays after it exits. To draw below the prompt instead, see
  [Drawing inline](#drawing-inline).
- Themes: `dark`, `light`, `dracula`, `solarizedDark`, `solarizedLight`,
  `rosePine`, `atomOneDark`, `gruvboxDark`, `gruvboxLight`. To switch while
  running, return one from `App::theme()`.
- `start()` returns the final model, so you can read what the reader chose.
- A `Program` runs once. Create a new one to run again.
- The terminal backend needs a POSIX TTY. It fails clearly on Windows, or when
  stdin is not a terminal.

## Drawing inline

`withInline()` draws the app below the shell prompt instead of over the whole
screen, the way an installer asks its questions. What the shell printed before
stays above it. This asks two questions, one program each, and leaves the
answers in the scrollback:

```php
use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Widget;

final readonly class Question extends App
{
    public TextInput $field;

    public function __construct(public string $question, ?TextInput $field = null)
    {
        $this->field = $field ?? TextInput::new();
    }

    public function view(): Widget
    {
        return Layout::vertical(
            Paragraph::fromText($this->question),
            Component::bind('answer', $this->field, static fn (self $app, TextInput $field): self => new self($app->question, $field)),
        );
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        if ($message->key !== Key::Enter) {
            return UpdateResult::ignored($this);
        }

        return new UpdateResult($this, Cmd::batch(
            Cmd::println(sprintf('✓ %s  %s', $this->question, $this->field->value())),
            Cmd::quit(),
        ));
    }
}

$answers = [];
foreach (['Project name', 'Database host'] as $question) {
    $answers[$question] = Program::new(new Question($question))->withInline()->start()->field->value();
}
```

- The app takes as many rows as its content needs, never more than the
  terminal has. An `App` measures its root widget. A `Model` of your own
  implements `SizesItsRegion` to say how many rows it wants, or gets the whole
  height.
- `Cmd::println()` prints lines above the app, into the scrollback. They are
  plain text: escape sequences are dropped, and a line wider than the terminal
  is cut. Outside an inline program it only writes to the trace.
- The last frame stays after the program ends, unless a `Cmd::println()` was
  the last thing it did. That is why the answers above leave one line each.
- To keep all the steps in one app, hold the answers in it and print each one
  with `Cmd::println()` as the reader moves on.
- The caret is drawn as a reversed cell. The terminal's own cursor stays hidden
  at the start of the app, where a terminal that rewraps its lines (tmux, iTerm2)
  cannot lose it.
- `withInline()` cannot be combined with `withAltScreen()` or
  `withMouseTracking()`: `start()` throws a `LogicException`.

## Errors

An exception in `update()`, `view()` or a command reaches `onError()` as an
`ErrorMsg`, and the program keeps going. Without `onError()`, the model gets the
`ErrorMsg` instead. An exception thrown by `onError()` itself is traced too.
Once the terminal is restored, the program prints how many errors went
unhandled, and the last one. After ten failures in a row that
nothing recovers from, the program stops: it restores the terminal first, then
`start()` throws the last error.

## Tracing

stdout is the screen, so an app can't print debug output. Write a trace instead.
Set `ELMPHANT_TRACE` and follow the file from a second terminal:

```bash
ELMPHANT_TRACE=/tmp/tui.jsonl php app.php
tail -f /tmp/tui.jsonl
```

Or set it in code:

```php
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\Trace\JsonlTracer;

Program::new($model)->withTracer(JsonlTracer::toFile('/tmp/tui.jsonl'))->start();
```

`JsonlTracer::toFile()` is faster: it writes once a thousand events or a megabyte
have piled up, at the first event a second or more after its last write, and when
the program stops. An app that goes quiet keeps its last events until then.
`JsonlTracer::live()` (what `ELMPHANT_TRACE` uses) writes every line at once.

Each line is one JSON object:

| Event | What it tells you |
| --- | --- |
| `input.read` | bytes read from the terminal |
| `update` | each message: `message`, `key`, `focused` (who has the keyboard), `ring` (the tab stops), `ms`, `changed` |
| `focus` | the keyboard moved: `from`, `to` |
| `command` | a command started |
| `render` | a frame: `ms`, `paintMs` (your widgets), `diffMs` (sending cells), `blockedMs` (waiting for the terminal) |
| `frame.skipped` | a frame was skipped to keep the frame rate |
| `log` | a `Cmd::log()` from your app |
| `output` | a stray `echo` or `var_dump`, caught before it broke the screen |
| `error` | an exception, with class, message, file and line |

"That key does nothing" is usually answered by `update`: look at `key` (was it
the key you think?) and `focused` (who got it?). A printable key is written as
`text`, never as the character, so a trace cannot give away a password.

## Debugging

The app owns its terminal, so debug from a second one:

1. **Watch the trace** with `tail -f`, as above.
2. **Log instead of echo.** Return `Cmd::log('loaded', ['items' => count($rows)])`
   from an update.
3. **Read the error.** Every exception is in the trace, even if your app
   handles it.
4. **Step through it** with Xdebug: `XDEBUG_MODE=debug XDEBUG_SESSION=1 php app.php`.
   While paused, the terminal stays in raw mode, so keep your IDE in another
   window.

## PHPStan rules for your app

The package ships seven PHPStan rules. They catch mistakes that work today and
break later. Include them:

```neon
includes:
    - vendor/elmphant/tui/rules.neon
```

| Rule | What it catches |
| --- | --- |
| `CompleteRebuildRule` | `new self(...)` with fewer arguments than the constructor takes, which resets the rest |
| `NamedRebuildRule` | `new self(...)` with positional arguments, which break when parameters move |
| `WidgetDoesNotPublishAnAxisPolicyRule` | A widget returning a bare `Constraint` instead of a `Sizing` |
| `FocusIsAskedWhileDrawingRule` | A widget storing whether it has focus, instead of asking while it draws |
| `ReachableChildrenRule` | A widget drawing a child it does not expose, so nothing inside can get the keyboard |
| `KeyNameIsNotTextRule` | `$message->key === 'esc'`, which never matches |
| `SplitIsBoundRule` | A `Split` that is not bound, so dragging its divider does nothing |

To allow an exception to `ReachableChildrenRule`:

```neon
parameters:
    elmphant:
        reachableChildrenAllowList:
            - App\Widget\Decoration
```
