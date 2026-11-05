# Styling

[← README](../README.md)

Colours come from a theme. A widget asks the theme while it draws, so switching
the theme repaints everything with no change to your widgets.

## Choosing a theme

The presets are `dark`, `light`, `dracula`, `solarizedDark`, `solarizedLight`,
`rosePine`, `atomOneDark`, `gruvboxDark` and `gruvboxLight`. Set one when you
start the program:

```php
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\Program;

$program = Program::new($app)->withTheme(Theme::gruvboxDark());
```

To switch while the app runs, return a theme from `App::theme()`. It is asked
every frame. `ModelHarness` asks it too, but never sees `withTheme()`, so an app
with tests is better off returning its theme from `App::theme()`.

Every preset is tested: the text on a filled row (the selection, the cursor
row, a field, the footer) reaches a contrast of 4.5:1. A palette of your own
gets no such check, so test it the same way.

## Changing a theme

Each part of a theme has a `withX()` method. A theme is a value, so you build
yours from a preset:

```php
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;

$theme = Theme::dark()
    ->withAccent(new Style(foreground: Color::hex('#ff8800')))
    ->withCanvas(Color::hex('#101418'));
```

`withCanvas()` paints a page background behind every ink and leaves the filled
rows their own background.

## What each widget reads

| Theme part | Read by |
| --- | --- |
| `selectedStyle` | the selected row of `ListView`, `Table`, `TreeView` |
| `cursorStyle` | the cursor row of `ListView`, `Table`, `TreeView`, `Dropdown`, and `Checkbox` |
| `inputStyle` | `TextInput` |
| `headerStyle` | `Header`, the header row of `Table` |
| `footerStyle` | `Footer`, `StatusBar` |
| `title` | the titles of `Panel`, `Box`, `Modal`, `TabBar`, `Radio`, `CheckboxGroup` |
| `borderStyle` / `focusedBorderStyle` | borders, and the border of the part that holds the keyboard |
| `fillStyle` / `emptyStyle` | the two parts of `ProgressBar` |
| `base`, `accent`, `dim` | plain text, highlights and quieter text everywhere |
| `error`, `success`, `warning`, `info` | `Alert`, and text that says how something went |

## One part of the screen

`Themed` draws one widget with a changed theme. The change is a function of the
theme in force, so a theme switch still reaches it:

```php
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Widget\Structure\Themed;

$danger = Themed::around(
    $content,
    static fn (Theme $theme): Theme => $theme->withSelectedStyle(new Style(foreground: Color::hex('#ffffff'), background: Color::hex('#aa0000'))),
);
```

Popups the widget opens, such as a `Dropdown`'s list, use the changed theme
too. A bound component inside `Themed` works as it does anywhere else.

## Colours in text

A `Span` takes its colour from a theme token or from a literal style:

```php
use Elmphant\Tui\Rendering\Color;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\Style;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Widget\Prose\Text;

$status = Text::line(
    Span::token('Deployed', StyleToken::Success),
    Span::token(' to ', StyleToken::Dim),
    Span::styled('production', new Style(foreground: Color::hex('#ff8800'))),
);
```

Use a token (`Base`, `Accent`, `Dim`, `Error`, `Success`, `Warning`, `Info`,
`Title`) for colours that should follow the theme. Use a literal style for a
brand colour that must stay the same in every theme. `bold()`, `italic()` and the
other modifiers work on both: `Span::token('Deployed', StyleToken::Success)->bold()`
keeps the theme's colour and adds bold.

## Readable on a filled row

Text drawn on a filled row must not punch a hole in the row, and must stay
readable on it. `readableOn()` takes the row's background and lifts the text
colour until it reaches 4.5:1. Pass 3.0 for text that should stay quieter:

```php
use Elmphant\Tui\Rendering\Theme;

$theme = Theme::dark();
$hint = $theme->dim->readableOn($theme->footerStyle, 3.0);
```

## Borders, padding and backgrounds

`Container` puts a border, padding, margin and a background around any widget —
see [Layout](layout.md#decoration). `Panel`, `Box`, `Card`, `Modal`, `TabBar`,
`Header`, `Footer`, `Paragraph`, `TextInput`, `ProgressBar` and `Spinner` also
take styles of their own through their `withX()` methods.

## Terminals with fewer colours

The program reads `COLORTERM` and `TERM` and turns each colour into the nearest
one the terminal can show. With `NO_COLOR` set, it draws without colour.
`Program::withColorProfile()` sets the profile by hand.
