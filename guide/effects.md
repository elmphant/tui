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
Cmd::batch($cmd1, null, $cmd2)      // run several commands, skipping nulls (null if all are)
Cmd::message($message)              // send a message right away
Cmd::call(fn() => $message)          // run a short piece of code
Cmd::fiber(fn() => $message)         // run code that waits, without freezing the screen
Cmd::http('https://example.com')     // an HTTP request
Cmd::listen($socket, fn($stream) => $messages) // read a stream whenever it has data
Cmd::log('loaded', ['items' => 3])  // write a line to the trace (stdout is the screen)
Cmd::copy($text)                    // copy text to the clipboard
Cmd::toast('Saved')                 // show a note for a few seconds
Cmd::println('✓ Saved')             // print a line above an inline program

// Focus
Cmd::focus('search')                      // move the keyboard to a widget
Cmd::focusNext() / Cmd::focusPrevious()   // like tab / shift+tab
Cmd::focusFirst() / Cmd::focusNowhere()   // the first stop, or keep the keyboard for the App

// Processes (uses symfony/process)
Cmd::exec(['git', 'status'])        // run, then get the whole result
Cmd::stream(['tail', '-f', 'log'])  // get the output while it runs
```

- `Cmd::tick()` repeats by itself. Ask once, not on every tick.
- `Cmd::call()` runs on the main loop. Keep it short, and use `exec()`, `process()`
  or `http()` for anything slow. If it throws, you get an `ErrorMsg`.
- `Cmd::listen()` stops at the end of the stream or on `Cmd::cancel()`. Cancel
  it before you close the stream yourself.
- `Cmd::println()` prints only in an inline program, and the lines stay in the
  scrollback. See [Drawing inline](running.md#drawing-inline).

## Fibers

`Cmd::fiber()` runs a closure in a fiber of its own. While the closure waits on
I/O that suspends the fiber, the program keeps drawing, reading keys and running
other effects. The closure returns a message or `null`, like a `Cmd::call()`
that cannot return a command. If it throws, you get an `ErrorMsg` with
`ErrorSource::Fiber`. Name it with an `EffectId` to cancel or replace it. A
fiber cannot be stopped from outside, so a cancelled one runs on and its answer
is dropped.

Only I/O that suspends the fiber keeps the screen alive. Symfony's
`AmpHttpClient` does, once you install `amphp/http-client:^5` (this package does
not):

```php fragment
use Symfony\Component\HttpClient\AmpHttpClient;

$client = new AmpHttpClient();

return new UpdateResult($this, Cmd::fiber(
    static fn (): Message => new Loaded($client->request('GET', $url)->getContent()),
    EffectId::named('load'),
));
```

Code that blocks instead (`curl`, `file_get_contents()`, a long loop) freezes
the screen inside a fiber just as it does in `Cmd::call()`. Never wait on Amp in
`update()` or in a `Cmd::call()`: the update pauses, and the program skips every
turn until it resumes.

## Toasts

A toast is a short note drawn over the screen. The runtime shows it and takes
it away, so your model stores nothing:

```php
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Cmd\ToastPosition;
use Elmphant\Tui\Core\Cmd\ToastSeverity;

$command = Cmd::toast('Saved 3 pages')
    ->withTitle('Done')
    ->withSeverity(ToastSeverity::Success)  // Info (default), Success, Warning, Error
    ->withSeconds(3)                        // 6 by default
    ->withPosition(ToastPosition::BottomRight) // TopRight by default
    ->withWidth(30)                         // 40 by default; the height fits the text
    ->withKey('save');                      // a later toast with this key replaces it
```

Several toasts in one corner stack, the newest nearest the corner. A click on
a toast closes it, and the click does not reach your app. The border and title
take the theme's colour for the severity. `ModelHarness` draws toasts too. Its
clock stands still until a test calls `advance($seconds)`.

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
arrives after that. A cancel stops the command. What it had already sent still
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

An action starts it, and the result arrives in `onMessage()`. Check the exit
code: a failing command answers too, with its reason in `stderr`.

```php fragment
use Elmphant\Tui\Application\{AppActions, InputContext};
use Elmphant\Tui\Core\{Cmd, EffectId, Message, UpdateResult};
use Elmphant\Tui\Message\ProcessResultMsg;

protected function actions(): AppActions
{
    return parent::actions()->with('log', ['ctrl+r'], static fn (self $app): UpdateResult => new UpdateResult(
        $app,
        Cmd::exec(['git', 'log', '--oneline', '-10'], id: EffectId::named('log')),
    ), 'reload');
}

protected function onMessage(Message $message, InputContext $input): self|UpdateResult
{
    if (!$message instanceof ProcessResultMsg || !$message->isFrom('log')) {
        return $this;
    }

    return $message->exitCode === 0 ? $this->withOutput($message->stdout) : $this->withError($message->stderr);
}
```

**`Cmd::stream()`** sends the output in pieces while the command runs
(`ProcessOutputMsg`), then a `ProcessFinishedMsg`. A piece can end in the middle
of a line. Call `->withLineFraming()` to get whole lines only. On a timeout you
get the output so far, an `ErrorMsg`, and then the `ProcessFinishedMsg`.

```php fragment
use Elmphant\Tui\Message\{ProcessOutputMsg, ProcessFinishedMsg, ProcessStream};

// Started by an action: new UpdateResult($app, Cmd::stream(['npm', 'run', 'build']))
// In onMessage(), inside a match (true):
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

Cmd::stream(['npm', 'run', 'build'])
    ->withId(EffectId::named('npm-build'))
    ->withWorkingDirectory('/app')
    ->withTimeout(120)
    ->withEnv(['NODE_ENV' => 'production'])
    ->withInput('yes')
    ->withOutputChunkBytes(64 * 1024);
```

Cancelling, a timeout and quitting stop the process you started and every
process it started in turn, such as the commands a shell runs. A process gets
50 ms to end on its own before it is killed.

## HTTP requests

`Cmd::http()` needs `composer require symfony/http-client`. It takes the URL, an
`HttpMethod` (`HttpMethod::Get` unless you say otherwise) and an id. Headers, a
body and client options such as a `timeout` go through `withHeaders()`,
`withBody()` and `withOptions()`. The result is an `HttpResultMsg` with
`statusCode`, `body` and `headers`. A 404 or 500 is a result too, not an error.

```php fragment
use Elmphant\Tui\Core\EffectId;
use Elmphant\Tui\Message\HttpResultMsg;

$packages = EffectId::named('packages');

// Started by an action:
new UpdateResult(
    $app->withLoading(true),
    Cmd::http('https://packagist.org/search.json?q=markdown')
        ->withId($packages)
        ->withMaxBodyBytes(4 * 1024 * 1024)
);

// In onMessage(), inside a match (true):
$message instanceof HttpResultMsg && $message->effectId?->equals($packages)
    => $this->withPackages(
        json_decode($message->body, true)['results'] ?? [],
    ),
```

## The messages you get

| Message | When |
|---------|------|
| `KeyMsg` | A key press: `key`, `ctrl`, `alt`, `shift`, `isText()` |
| `MouseMsg` | A click, scroll or drag: `isClick()`, `isIn()` |
| `PasteMsg` | Pasted text: `content` |
| `TickMsg` | A beat of `Cmd::tick()`: `isFrom('clock')` |
| `ResizeMsg` | The terminal changed size: `width`, `height` |
| `HttpResultMsg` | `Cmd::http()` finished |
| `ProcessResultMsg` | `Cmd::exec()` finished |
| `ProcessOutputMsg` | `Cmd::stream()` printed something |
| `ProcessFinishedMsg` | `Cmd::stream()` ended |
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

In a test no handler runs. `ModelHarness::emit('rows', new RowsLoaded($rows))`
delivers what `$emit` would. See [Testing](testing.md).
