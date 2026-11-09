# Custom widgets

[← README](../README.md)

## The three interfaces

| Interface | Adds | For |
| --- | --- | --- |
| `Widget` | `sizing()` and `render($frame, $area, $context)` | anything that only draws |
| `Interactive` | `update($message, $area)`, returning an `UpdateResult` | anything that reacts to input |
| `Focusable` | nothing — a marker | anything that can hold the keyboard |

A widget doesn't know where it is on screen: it is handed its `Rect` in both
`update()` and `render()`. To lay out parts of its own, it cuts that `Rect` up:
`$area->inset($spacing)` leaves a margin, and
`$area->split(Direction::Horizontal, 3, gap: 1)` gives three equal columns,
shared the way a `Layout::row()` shares the room.

A widget never stores whether it has the keyboard. It asks while it draws:
`$context->hasFocus()`.

A widget draws another widget through the context, never by calling its
`render()`: `$context->draw($child, $frame, $area)`, or
`$context->drawChild($index, $child, $frame, $area)` for a child the keyboard
can reach, so the child is drawn as focused only when the keyboard is in it.

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

## The mouse

A bound widget hears a click, the wheel or a drag only inside its own area. To
follow a drag past its edge, or to close when the reader clicks somewhere else,
it implements `CapturesThePointer` and answers true while that matters:

```php
use Elmphant\Tui\Widget\Focus\CapturesThePointer;

final readonly class Slider implements CapturesThePointer
{
    public function __construct(private bool $dragging = false)
    {
    }

    public function capturesThePointer(): bool
    {
        return $this->dragging;   // or $this->open, as a dropdown does
    }
}
```

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

## A widget the reader uses

A row of choices that answers `Chosen` for Enter or a click, the way a list
does. Bound with `$this->bind('sizes')->on(Chosen::class, …)`, it needs no
code in the app beyond the handler:

```php
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\Chosen;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\Focus\Navigates;
use Elmphant\Tui\Widget\Focus\Navigation;
use Elmphant\Tui\Widget\PublishesHints;

final readonly class Chips implements Navigates, Committing, PublishesHints
{
    /**
     * @param list<string> $labels
     */
    public function __construct(private array $labels, private int $cursor = 0)
    {
    }

    public function outcomeOf(Message $message, Rect $area): ?Message
    {
        $chosen = match (true) {
            $message instanceof KeyMsg && self::keys()->matches($message, 'choose') => $this->cursor,
            $message instanceof MouseMsg && $message->isClick() => $this->indexAt($message->x - $area->x),
            default => null,
        };

        return $chosen === null ? null : new Chosen($this->labels[$chosen], $message);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $cursor = match (true) {
            $message instanceof KeyMsg && self::keys()->matches($message, 'left') => max(0, $this->cursor - 1),
            $message instanceof KeyMsg && self::keys()->matches($message, 'right') => min(count($this->labels) - 1, $this->cursor + 1),
            $message instanceof MouseMsg && $message->isClick() => $this->indexAt($message->x - $area->x) ?? $this->cursor,
            default => $this->cursor,
        };

        return new UpdateResult(new self($this->labels, $cursor));
    }

    public function navigation(): Navigation
    {
        return Navigation::Regular;
    }

    public function hints(): array
    {
        return self::keys()->hints(['move' => ['left', 'right'], 'choose' => 'choose']);
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::exact(1));
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $x = $area->x;
        foreach ($this->labels as $index => $label) {
            $style = $index === $this->cursor && $context->hasFocus() ? $context->theme->cursorStyle : $context->theme->base;
            $x += $frame->putStyled($x, $area->y, " {$label} ", $style);
        }
    }

    private static function keys(): Keybindings
    {
        return new Keybindings(['left' => ['left'], 'right' => ['right'], 'choose' => ['enter']]);
    }

    private function indexAt(int $column): ?int
    {
        foreach ($this->labels as $index => $label) {
            $column -= mb_strlen($label) + 2;
            if ($column < 0) {
                return $index;
            }
        }

        return null;
    }
}
```

What each piece does:

- **`Committing::outcomeOf()`** says what the reader did. The app hears it with
  `on()`, and the widget is written back even when it did not change. Keep the
  outcome out of the widget's state: a stored "clicked" flag would be gone the
  next time `view()` builds the widget.
- **`update()`** keeps the cursor, the state only the widget needs.
- **`Navigates`** says how the keyboard reaches it: `Regular` (tab stops on
  it), `Pointer` (a click lands on it, tab walks past, as for a tab strip) or
  `Never`.
- **`PublishesHints`** names its keys in the footer and the key help. Derive
  the labels from the bindings with `Keybindings::hints()`.
- **`TakesText`**, for a field: while `takesText()` answers true, plain letters
  reach the widget before any shortcut, and the key help opens with Ctrl+K
  instead of `?`.
- The mouse arrives only inside the widget's area (see [The mouse](#the-mouse)).

## Where the keyboard can go

- `InputLayer`: while on screen, everything behind it ignores keys and clicks.
  `Modal` is one.
- `FocusScope`: chrome such as a toolbar, which tab skips until the keyboard is
  inside it.
