# Keys and focus

[← README](../README.md)

## Reading a key

`KeyMsg::$key` is a `Key` case for a named key (`Key::Enter`, `Key::Escape`,
`Key::Up`) and a string only for text (`'a'`, `'é'`, `' '`). So
`$message->key === 'esc'` never matches. Compare with `Key::Escape`. The shipped
PHPStan rule `KeyNameIsNotTextRule` catches this mistake.

Bindings write keys as text patterns: `'enter'`, `'ctrl+r'`, `'shift+tab'`.

## Typing beats shortcuts

While a text field has the keyboard, a plain letter is **typed**. Your `onKey()`,
the letter keys of `actions()` and `withAction()`, and global letter shortcuts
never see it. Ctrl and Alt chords and named keys (Enter, arrows) still work as
shortcuts.

So give app-wide commands a Ctrl chord (`ctrl+n`, not `n`). Keys `actions()`
declares are checked before the field's, so a chord you declare is taken from
the field. A
`TextInput` already uses `ctrl+u` (clear), `ctrl+backspace`/`ctrl+delete`
(delete a word) and `ctrl+left`/`ctrl+right` or `alt+b`/`alt+f` (move by a word).

## Key sequences

Keys separated by spaces are typed one after another: `'g g'`, `'ctrl+x ctrl+s'`.
The framework waits for the rest of the sequence. Escape cancels it, and after
half a second the keys are handled on their own, so a widget that binds a plain
`g` still gets it. While the sequence is open, the footer shows what can follow.

```php
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;

$bindings = new Keybindings([Actions::HOME => ['g g'], Actions::END => ['G']]);

assert($bindings->hints(['top' => Actions::HOME, 'bottom' => Actions::END]) === ['gg' => 'top', 'G' => 'bottom']);
```

## Changing keys

A widget's keys come from three places, the later ones winning:

1. the widget's `defaultKeybindings()`,
2. `withKeybindings()` on that widget,
3. your App's `globalKeybindings()`.

### Vim mode

Return `VimMode::keybindings()` from `globalKeybindings()` to add the keys below.
To let the reader switch it, keep a flag on the App, return the vim keys only
while it is set, and declare the switch as an action:
`parent::actions()->with('vim', ['ctrl+v'], static fn (self $app): self => $app->cloneWith(vim: !$app->vim), 'vim mode')`.

| Keys | Action |
|---|---|
| `j` / `k` | down / up |
| `h` / `l` | left / right (in a tree: close / open) |
| `gg` / `G` | top / bottom |
| `ctrl+d` / `ctrl+u` | page down / up |
| `L` / `H`, `gt` / `gT` | next / previous tab |
| `/` | search |

## Search fields

A filter widget (`FilterableList`, `FilterableTable`, …) is a search field over
a list, and works in one of two ways:

- **Nothing binds `Actions::SEARCH`** (the default): typing always goes to the
  field, and the arrows, Page Up/Down and Enter go to the list.
- **Something binds it**, such as vim mode, or
  `new Keybindings([Actions::SEARCH => ['/']])` from `globalKeybindings()`: the
  list holds the keys, so letters can be shortcuts. `/` jumps into the field,
  Escape or Enter jumps back out and keeps the query, and Escape on the list
  clears the query.

The footer always says which part has the keys. A `withAction()` key on the
filter comes before the filter's own meaning of that key, in the field too. So
`withAction('close', ['esc'], …)` closes your detail pane instead of leaving the
field or clearing the query.

## Key help

`?` (or Ctrl+K, which also works while a field takes `?` as text) opens a list
of every key that works right now: the focused widget's keys, those of the
panes around it, and the app's own. It is read from the real bindings, so it
is always correct. It closes with `?`, Ctrl+K or Escape, and scrolls when long.

- An action's label is its fourth argument, in `actions()` and in
  `withAction()`. The footer uses the same words.
- Override `keyHelpKeybindings()` to use other keys, or return
  `Keybindings::empty()` to turn it off.
- `FocusHints` ends with the key that opens it: `? keys`, or `Ctrl+K keys`
  while a field takes `?` as text. When the footer is too narrow, the other
  hints give way and this one stays.
- A mounted sub-model that answers its own keys implements `NamesItsKeys`, so
  they appear too.

## Footer hints

Never type a hint like `"Enter choose"` by hand: it goes stale when a key
changes. `FocusHints` asks whatever has the keyboard which keys it answers:

```php fragment
public function view(): Widget
{
    return Layout::column(
        Header::new('My App')->withRule(),
        $this->bind('list'),
        Footer::new()->withLeft(FocusHints::new()),
    );
}
```

With the keyboard on the list, that footer reads `Ctrl+Q quit   ↑/↓ move
Enter choose   ? keys`. Rebind a key, or switch on vim mode, and the footer follows.
The app's own `actions()` come first. A key that a focused field would type as
text is left out while it does, since it does nothing then.
Keys given with `$this->bind('list')->withAction(…)` are named only while that
component holds the keyboard, by the label given as its fourth argument.

Your own widget names its keys by implementing `PublishesHints`. Ask its
bindings for the labels, never type them:

```php fragment
public function hints(): array
{
    return $this->list->effectiveKeybindings()->hints([
        'select' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN, Actions::PAGE_UP, Actions::PAGE_DOWN],
        'fill in' => Actions::SELECT,
    ]);
}
```

To show one key the way the footer does, in your own text, ask
`KeyPattern::fromString('ctrl+q')->label()` for `Ctrl+Q`.

`hints()` takes `description => action` (or a list of actions under one label)
and returns `label => description`. It merges keys into short labels:

```php
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Keybindings;

$hints = Keybindings::rowNavigation()->hints([
    'select' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN, Actions::PAGE_UP, Actions::PAGE_DOWN],
    'choose' => Actions::SELECT,
]);

// ['(Shift+)↑/↓' => 'select', 'Enter' => 'choose']
```

When the hints don't fit, the footer's right slot gives way first. Give
`withRight()` shorter versions, longest first. The footer uses the first one
that fits, and drops the slot if none does:

```php
use Elmphant\Tui\Widget\Bar\{FocusHints, Footer};
use Elmphant\Tui\Widget\Prose\Text;

Footer::new()
    ->withLeft(FocusHints::new())
    ->withRight(Text::new('Support · 1.2.0'), Text::new('Support'));
```

A `Header` does the same: its right slot gives way to the left one, and
`Header::withRight()` takes shorter versions too.

A bar gives each slot only the width the slot asks for. A widget that just fills
whatever it gets (`ProgressBar`, `Separator`) needs a width:

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

## Where the keyboard goes

- **At start**, the keyboard is on the first bound widget, top to bottom. Return
  `Cmd::focus('composer')` from `setup()` to start elsewhere.
- **Tab and Shift+Tab** walk the bound widgets. Each field or button inside a
  bound `Panel`, `TabBar`, `Form` or `Modal` is a stop of its own, also when it
  sits in a `Layout`, `Flex`, `Box`, `Container` or `Card` there: a dialog of
  `Layout::column(Text::new('Delete?'), Button::new('Delete'))` needs no bind
  for its button.
- **A click** moves the keyboard to what was clicked.
- **`Cmd::focus('id')`** moves it from code. A widget that is not on screen is
  refused, and the keyboard stays where it was. To aim at a button inside a
  bound dialog, wrap it in `Component::named('cancel', $button)`: it keeps that
  name, and the dialog still hears it and stores its answer.
- **When the focused widget disappears** (a modal closes, a tab closes), the
  keyboard goes back to the nearest sensible place — usually where it was before.
- **A key the focused widget ignores** goes to the panes around it, so PageDown
  in a field still scrolls the form.

A toolbar or filter row that a reader steps into only now and then can be
wrapped in a `FocusScope`: tab then skips it, and `Cmd::focus()` takes the
keyboard in and out.
