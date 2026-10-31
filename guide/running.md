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
  frame stays after it exits.
- Themes: `dark`, `light`, `dracula`, `solarizedDark`, `solarizedLight`,
  `rosePine`, `atomOneDark`, `gruvboxDark`, `gruvboxLight`. To switch while
  running, return one from `App::theme()`.
- `start()` returns the final model, so you can read what the reader chose.
- A `Program` runs once. Create a new one to run again.
- The terminal backend needs a POSIX TTY. It fails clearly on Windows, or when
  stdin is not a terminal.

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

`JsonlTracer::toFile()` writes when the program stops, which is faster;
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
