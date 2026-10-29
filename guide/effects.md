# Commands and effects

[← README](../README.md)

An app never does slow work itself. It returns a **command** that describes the
work. The framework runs it in the background and sends the result back as a
message.

## The commands

```php fragment
Cmd::quit()                         // stop the program
Cmd::tick(0.05)                     // send a TickMsg every 50 ms
Cmd::after(2.0, fn() => $msg)       // send one message later
Cmd::batch($cmd1, $cmd2)            // run several commands
Cmd::combine($cmd1, null, $cmd2)    // like batch, but skips nulls (and is null if all are)
Cmd::message($message)              // send a message right away
Cmd::call(fn() => $message)          // run a short piece of code
Cmd::http('https://example.com')     // an HTTP request
Cmd::listen($socket, fn($stream) => $messages) // read a stream whenever it has data
Cmd::log('loaded', ['items' => 3])  // write a line to the trace (stdout is the screen)
Cmd::copy($text)                    // copy text to the clipboard

// Focus
Cmd::focus('search')                      // move the keyboard to a widget
Cmd::focusNext() / Cmd::focusPrevious()   // like tab / shift+tab
Cmd::focusFirst() / Cmd::focusNothing()   // the first stop, or keep the keyboard for the App

// Processes (uses symfony/process)
Cmd::exec(['git', 'status'])        // run, then get the whole result
Cmd::process(['tail', '-f', 'log']) // get the output while it runs
```

- `Cmd::tick()` repeats by itself. Ask once, not on every tick.
- `Cmd::call()` runs on the main loop. Keep it short; use `exec()`, `process()`
  or `http()` for anything slow. If it throws, you get an `ErrorMsg`.
- `Cmd::listen()` stops at the end of the stream or on `Cmd::cancel()`. Cancel
  it before you close the stream yourself.

## Naming and cancelling

Give a command an `EffectId` to tell its results apart and to stop it:

```php
use Elmphant\Tui\Core\{Cmd, EffectId};

$download = EffectId::named('download');

Cmd::http('https://example.com/archive', id: $download);
Cmd::cancel($download); // also works for tick(), after(), exec(), and process()
```

The result then answers `$message->isFrom('download')`. Starting a new command
under the same name replaces the old one, and no late result of the old one
arrives after that. A cancel stops the command; what it had already sent still
arrives, but nothing more.

Two parts of an app can use the same name without clashing, when the parent
scopes its children's commands:

```php
use Elmphant\Tui\Core\{Cmd, EffectId};

$load = Cmd::exec(['composer', 'show'], id: EffectId::named('load'));

$dashboard = $load->scopedTo('dashboard');
$log = $load->scopedTo('log'); // runs beside the dashboard's, not instead of it
```

A [mounted sub-model](apps.md#mounting-a-sub-model) is scoped for you.

## Running processes

**`Cmd::exec()`** runs a command and sends one `ProcessResultMsg` when it ends,
with `stdout`, `stderr` and `exitCode`. Output is capped at 8 MiB. A program
that does not exist is not an error: it ends with exit code 127.

```php fragment
use Elmphant\Tui\Message\ProcessResultMsg;

// In update():
$message instanceof KeyMsg && $message->key === 'r'
    => new UpdateResult($this, Cmd::exec(['git', 'log', '--oneline', '-10'])),

$message instanceof ProcessResultMsg
    => new UpdateResult($this->withOutput($message->stdout)),
```

**`Cmd::process()`** sends the output in pieces while the command runs
(`ProcessOutputMsg`), then a `ProcessFinishedMsg`. A piece can end in the middle
of a line; call `->withLineFraming()` to get whole lines only. On a timeout you
get the output so far, an `ErrorMsg`, and then the `ProcessFinishedMsg`.

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

Both take the same options:

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

Cancelling, a timeout and quitting stop the process you started, but not
processes it started itself. If you go through a shell, start the real command
with `exec` so it replaces the shell:

```php
use Elmphant\Tui\Core\Cmd;

Cmd::exec(['sh', '-c', 'cd /app && exec npm install']);
```

## HTTP requests

`Cmd::http()` needs `composer require symfony/http-client`. The result is an
`HttpResultMsg` with `statusCode`, `body` and `headers`. A 404 or 500 is a
result too, not an error.

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

## The messages you get

| Message | When |
|---------|------|
| `KeyMsg` | A key press: `key`, `ctrl`, `alt`, `shift`, `isText()` |
| `MouseMsg` | A click, scroll or drag: `isClick()`, `inRect()` |
| `PasteMsg` | Pasted text: `content` |
| `TickMsg` | A beat of `Cmd::tick()`: `isFrom('clock')` |
| `ResizeMsg` | The terminal changed size: `width`, `height` |
| `HttpResultMsg` | `Cmd::http()` finished |
| `ProcessResultMsg` | `Cmd::exec()` finished |
| `ProcessOutputMsg` | `Cmd::process()` printed something |
| `ProcessFinishedMsg` | `Cmd::process()` ended |
| `ErrorMsg` | A command failed |

## Effects of your own

For anything the built-in commands don't cover — an async database query, a
websocket — write a command class (data only) and a `CommandHandler` that runs it:

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

- `$emit` sends a message to your app.
- `$finished` tells the framework the effect is done.
- The closure `start()` returns stops the effect. It runs on `Cmd::cancel()`, on
  a replacement under the same name, and when the program ends.
- A command that no handler accepts throws, so a typo is loud. If `start()`
  throws, you get an `ErrorMsg`.
- A handler can never take over the built-in commands.

In a test no handler runs; `ModelHarness::emit('rows', new RowsLoaded($rows))`
delivers what `$emit` would. See [Testing](testing.md).
