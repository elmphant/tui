# Custom widgets

[← README](../README.md)

## The three interfaces

| Interface | Adds | For |
| --- | --- | --- |
| `Widget` | `sizing()` and `render($frame, $area, $context)` | anything that only draws |
| `Interactive` | `update($message, $area)`, returning an `UpdateResult` | anything that reacts to input |
| `Focusable` | nothing — a marker | anything that can hold the keyboard |

A widget doesn't know where it is on screen: it is handed its `Rect` in both
`update()` and `render()`.

A widget never stores whether it has the keyboard. It asks while it draws:
`$context->hasFocus()`.

`sizing()` returns a `Sizing`, one `Constraint` per axis
(see [Layout](layout.md)).

## Wrapping one widget

A widget that is drawn as one other widget, with something added, uses
`DecoratesOneChild`. You write `wrapped()`; sizing, drawing and the child list
come from it:

```php
use Elmphant\Tui\Widget\DecoratesOneChild;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;

final readonly class NotesPane implements WidgetContainer
{
    use DecoratesOneChild;

    public function __construct(private string $notes)
    {
    }

    protected function wrapped(): Widget
    {
        return Panel::new('Notes')->add(Text::new($this->notes));
    }
}
```

If the wrapper passes input on to the widget inside a `Panel`, hand it
`$panel->contentArea($area)`, the area the panel draws it in. Then clicks land
on the rows the reader sees.

## Animation

A widget that animates reads the time from `$context->now()` and asks for the
next frame with `$context->requestRepaint($delay)` while it draws. That is all:
no timer, no binding. `Spinner` works this way.

## Drawing cells

A `Frame` is a grid of cells. A widget, or a `Model` that draws by hand, writes
into it. The framework compares it with the last frame and sends only what
changed.

```php fragment
public function render(Frame $frame, RenderPass $pass): void
{
    // Text with color
    $frame->putStyled(0, 0, 'Hello', new Style(foreground: Color::hex('FF06B7')));

    // With background and attributes
    $frame->putStyled(0, 1, 'Bold text', (new Style(Color::ansi(AnsiColor::White), Color::ansi(AnsiColor::Black)))->bold());

    // Fill a rectangle
    $frame->fillStyled(new Rect(0, 3, 40, 1), new Style(Color::ansi(AnsiColor::White), Color::ansi(AnsiColor::Blue)));

    // A theme style, so the colours follow the theme
    $frame->putStyled(0, 4, 'Selected', $pass->context->theme->selectedStyle);

    // Your own ANSI colour codes work too
    $frame->putAnsi(0, 5, "\e[1;31mred bold\e[0m normal");
}
```

**Safe text.** `putStyled()` and every widget's text remove terminal control
codes. Log lines, process output and user input can't mess up the screen.

`putAnsi()` keeps colour codes, so **only pass text you wrote yourself**. Clean
anything from outside with `TerminalText::plain()` first.

## Popups (the float layer)

A popup must draw over its neighbours, outside its own area. A widget asks for
one while it draws:

```php fragment
$context->float($frame, MyPopup::of(...), $area);   // $area is the anchor
```

Popups are drawn after the whole screen, so they are always on top.

- The popup is a **separate** widget that implements `Floating`. Never float
  yourself.
- A `Floating` only draws. A `PointerFloating` also takes clicks over its area;
  `captures($action)` says which (a press, the wheel).
- If there is no room to draw, return an empty placement, or the popup blocks
  clicks on empty cells.
- A click on a popup arrives at its owner as a `FloatMouseMsg`. The owner must
  check the token is its own and that the popup is still open, and ignore it
  otherwise.
- **If you route messages yourself**, pass `FloatMouseMsg` on wherever you pass
  `MouseMsg`. Otherwise clicks in popups get lost.

## Where the keyboard can go

- `InputLayer`: while on screen, everything behind it ignores keys and clicks.
  `Modal` is one.
- `FocusScope`: chrome such as a toolbar, which tab skips until the keyboard is
  inside it.
