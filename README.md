# Elmphant TUI

**Terminal user interfaces in PHP, built with The Elm Architecture.**

Inspired by Go's [Bubbletea](https://github.com/charmbracelet/bubbletea) and
Rust's [Ratatui](https://github.com/ratatui/ratatui). The name mixes the **PHP
elephant** and **Elm**.

<img src="media/showcase.gif" width="880"
     alt="A full-screen app with a profile form, a task table, a file list, a tabbed settings panel and a package list. The reader fills in the form, ticks checkboxes, scrolls the table and the lists, switches tabs and opens the key help.">

More recordings — a kanban board, dropdowns, forms, and apps that draw below the
prompt — are in the [showcase](guide/showcase.md).

- **Simple model.** Your app is an immutable value. A key press gives you a new
  value, and the framework draws it.
- **Widgets included.** Lists, tables, forms, tabs, trees, modals, dropdowns,
  search fields, progress bars and more, with keyboard and mouse support.
- **Text you can copy.** Drag over the text in a panel to copy it, even while
  the app reads the mouse. See [Copying text](guide/widgets.md#copying-text).
- **No blocking.** Processes, HTTP requests and timers run in the background and
  report back as messages.
- **Full screen or inline.** An app can take the whole screen, or ask its
  questions below the shell prompt and leave the answers in the scrollback. See
  [Drawing inline](guide/running.md#drawing-inline).
- **Easy to test.** Press keys and read the screen in a plain PHPUnit test, with
  no terminal.

PHP 8.2+ · MIT License

## Status

0.1.0 is the first release. The runtime and the widgets are covered by tests
and run in Linux and macOS terminals. Until 1.0 a minor version may change the
API, and each change is listed in the [changelog](CHANGELOG.md). Apps do not
run on Windows outside WSL.

## Install

```bash
composer require elmphant/tui
```

You need PHP 8.2+, `ext-ctype` and `ext-mbstring`. Apps run in POSIX terminals
(Linux, macOS, WSL). Tests run anywhere.

Until 1.0, a minor version may break the API. Require a minor version, for
example `^0.1`, and read the [changelog](CHANGELOG.md) before you update.

Some features need an extra package. You only install it if you use the feature:

```bash
composer require symfony/http-client   # Cmd::http()
composer require league/commonmark     # Paragraph::fromMarkdown()
composer require amphp/http-client:^5  # HTTP inside Cmd::fiber(), with symfony/http-client
```

`Cmd::fork()` keeps the screen moving during blocking work only when PHP has the
`pcntl` extension. Without it, the work runs in the program and the screen
holds still until it is done.

## A counter

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Elmphant\Tui\Application\{App, AppActions};
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;

final readonly class Counter extends App
{
    public function __construct(private int $count = 0) {}

    public function view(): Widget
    {
        return Text::new("Count: {$this->count}  (? lists the keys)");
    }

    protected function actions(): AppActions
    {
        return parent::actions()
            ->with('up', ['k', 'up'], static fn (self $app): self => new self($app->count + 1), 'count up')
            ->with('down', ['j', 'down'], static fn (self $app): self => new self($app->count - 1), 'count down')
            ->withQuitKeys('x', 'ctrl+q');
    }
}

Program::run(new Counter());
```

Save it as `counter.php` and run `php counter.php`. Each key is declared once,
with what it does and what it is called. `?` opens a list of every key that
works right now, read from those declarations.

## How it works

<picture>
  <source media="(max-width: 700px)" srcset="diagrams/overview-narrow.svg">
  <img width="720" src="diagrams/overview.svg"
       alt="The framework turns keys from the terminal into messages for your update, which returns a new immutable model and a command. view draws that model's widgets, and Screen sends only the changes to the terminal. In the background the framework runs each command as a process or HTTP request and hands its result to update as the next message.">
</picture>

1. Something happens: a key, a click, a timer, a finished process. It arrives as
   a **message**.
2. Your app answers with a **new model**, and maybe a **command** — a
   description of work to do, such as "run `git status`".
3. The framework draws `view()` of the new model and runs the command. When the
   command finishes, its result is the next message.

`update()` and `view()` never do the work themselves. That is why an app is easy
to test: a test checks which command was asked for, and nothing runs.
[How it works](guide/how-it-works.md) follows one key press through the framework.

## Guides

| Guide | What you learn |
| --- | --- |
| [Showcase](guide/showcase.md) | Recordings of real apps: a kanban board, dropdowns, forms, and inline apps |
| [Building an app](guide/apps.md) | The `App` class, its hooks, and how interactive widgets keep their state |
| [Widgets](guide/widgets.md) | Every built-in widget, plus forms, modals, tables and scrolling |
| [Layout](guide/layout.md) | Placing widgets side by side or stacked, and making them reflow |
| [Styling](guide/styling.md) | Themes, colours in text, what each widget reads, and styling one part of the screen |
| [Keys and focus](guide/keys-and-focus.md) | Shortcuts, typing into fields, vim mode, the key help, footer hints, and where the keyboard goes |
| [Commands and effects](guide/effects.md) | Timers, processes, HTTP, cancelling, and effects of your own |
| [Testing](guide/testing.md) | Driving an app or one widget from a test, and checking the screen |
| [Running and debugging](guide/running.md) | Program options, drawing inline, terminals, errors, tracing, debugging, and the PHPStan rules |
| [Custom widgets](guide/custom-widgets.md) | Writing your own widget, popups, and drawing cells by hand |
| [How it works](guide/how-it-works.md) | What the framework does between a key press and the screen, in four diagrams |

## License

MIT
