# Testing

[← README](../README.md)

Tests need no terminal. Commands are never run: the test sees what the app asked
for and answers it.

## Testing an app

`ModelHarness` drives your app like a reader would:

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

// Input, written the way bindings write keys:
$app->press('tab', 'shift+tab', 'ctrl+r', 'enter');
$app->type('cache:flush');
$app->click('Run');          // click where "Run" is on screen
$app->click('search', offset: 7);   // seven columns to the right of it
$app->wheel('Output', 3);    // three notches down; -3 is up
$app->paste("two\nlines");   // pasted text
$app->drag(30, 5, 40, 5);    // press at one cell, move, release at another

// Answer the commands the app asked for:
$app->issued(ExecCmd::class);                     // which commands were asked for
$app->answer('load', stdout: '{"items": []}');    // ProcessResultMsg for Cmd::exec()
$app->stream('tail', "a line\n");                 // ProcessOutputMsg for Cmd::process()
$app->finish('tail', exitCode: 0);                // ProcessFinishedMsg
$app->respond('page', 200, '<html>');             // HttpResultMsg for Cmd::http()
$app->tick('clock', at: 12.5);                    // one beat of a Cmd::tick()
$app->fail('load', 'timed out');                  // ErrorMsg from that command
$app->emit('rows', new RowsLoaded([]));           // what your own handler would $emit
$app->runCalls();                                 // run the Cmd::call()s so far
$app->runFibers();                                // run the Cmd::fiber()s still waiting
$app->advance(1.5);                               // spinners turn, toasts expire, a held chord key goes on alone
```

Answering a command the app never started throws, so a wrong guess in the test
shows up at once. So does `type()` while no component holds the keyboard and a
field on screen was never passed to `Component::bind()`.

More: `initialize()` runs `setup()`, `resize()` changes the screen size,
`model()` returns the app for typed checks, `renderFrame()` gives the cells
when colours matter, and `printed()` lists every line `Cmd::println()` printed.

## Testing one widget

`WidgetHarness` hosts a single widget, gives it the keyboard, and has the same
input and screen methods:

```php
use Elmphant\Tui\Testing\WidgetHarness;
use Elmphant\Tui\Widget\Field\TextInput;

$field = WidgetHarness::for(TextInput::new()->withPlaceholder('name'), width: 20, height: 1)->type('Ada');

$field->widget()->value();       // 'Ada', the widget after the last key
$field->screen()->rowOf('Ada');  // 0
```

Popups are drawn too, so an open `Dropdown` shows its list in `screen()`.

## Checking the screen

`ScreenBuffer` finds text and reads styles, so a test never counts columns:

```php
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Testing\ScreenBuffer;

$frame = new Frame(20, 2);
$frame->putStyled(4, 1, 'Run', new Style());
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

The `TerminalAssertions` trait adds `assertScreenContains()`, `assertTextAt()`,
`assertCursorAt()` and `assertCursorHidden()`. Don't name a test method the same
as one of these: it would replace the helper and call itself forever.

## Calling `update()` yourself

`UserInput` builds the messages: `UserInput::key('shift+tab')`,
`UserInput::typed('ok')`, `UserInput::click($column, $row)`,
`UserInput::drag($column, $row)`, `UserInput::release($column, $row)` and
`UserInput::paste('text')`. `Issued::in($result->command, ExecCmd::class)` takes
apart the command you got back.

## Drawing by hand in a test

A tree with bound widgets can only be drawn once you say where the keyboard is.
The harnesses do this for you. When you draw by hand, say "nobody" or name the
widget:

```php
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Rendering\Context\{FocusPath, RenderPass};
use Elmphant\Tui\Rendering\Theme;

$pass = RenderPass::new(Theme::dark(), focus: FocusPath::nowhere());   // a layout or wording test
$app->render($frame, $pass);

$pass = RenderPass::new(Theme::dark(), focus: FocusPath::on(ComponentId::named('search')));   // or name the holder
```

## Tracing in a test

`InMemoryTracer` records what the program did, for checks on the whole flow:

```php fragment
$tracer = new InMemoryTracer();
Program::new($app)->withTracer($tracer)->start();

self::assertSame(0.0, $tracer->totalOf(TraceEvent::Render, 'blockedMs'));
```

For ANSI output, raw input bytes or terminal features, use `VirtualTerminal`.
For larger snapshots, `GoldenScreen::assertMatchesSnapshot($name, $harness->renderScreen(), $dir)`
compares the screen with a file. The first run writes the file.
