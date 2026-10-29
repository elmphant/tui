# Layout

[← README](../README.md)

## Stacking

`Layout::vertical()` stacks widgets top to bottom, `Layout::horizontal()` puts
them side by side. `->withGap(1)` leaves one cell between them.

Each widget says how much room it wants. A text line wants one row; a list
takes what is left. You can change that with a `Constraint`:

| Constraint | Meaning |
| --- | --- |
| `Constraint::exact(10)` | exactly 10 cells |
| `Constraint::min(5)` | at least 5 cells, and no more (it does not grow) |
| `Constraint::min(5)->withFlex()` | at least 5, then a share of what is left |
| `Constraint::max(30)` | grows to fill, but stops at 30 |
| `Constraint::flex(2)` | a share of what is left, twice as big as `flex(1)` |

Every minimum is given out first. The rest is shared by the flex weights.

A widget answers how big it wants to be with a `Sizing`: one `Constraint` for
the columns and one for the rows, `Sizing::of($columns, $rows)`.

## Reflowing with `Flex`

`Flex` lays widgets out in a row that wraps onto the next line when the
terminal gets narrow:

```php
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Widget\Layout\Flex;

$content = Flex::row()
    ->withWrap()
    ->withGap(1)
    ->add($sidebar, new Constraint(min: 22, flex: 1, basis: 28))
    ->add($main, new Constraint(min: 30, flex: 2, basis: 48))
    ->add($details, new Constraint(min: 22, flex: 1, basis: 28));
```

- `basis` is the size a widget would like. It decides where the row wraps.
- `flex` is how fast it grows; the `shrink` argument is how fast it gives room
  back when the row is too narrow.
- A `Flex::row()` takes only the rows its content needs; a `Flex::column()`
  fills its space.
- Items stretch to the line's height. `withCrossAlignment(CrossAlignment::Start)`
  (or `Center`, `End`) keeps each at its own size.

It is a small part of CSS Flexbox, made for terminals: no percentages, no
ordering, no reverse directions.

## Decoration

`Container` adds padding, margin, colours and a border around any widget:

```php
use Elmphant\Tui\Layout\{Constraint, Sizing, Spacing};
use Elmphant\Tui\Rendering\{BorderStyle, Color, Style};
use Elmphant\Tui\Widget\{Alignment, VerticalAlignment};
use Elmphant\Tui\Widget\Structure\Container;

$card = Container::around($content)
    ->withPadding(Spacing::xy(horizontal: 2, vertical: 1))
    ->withMargin(Spacing::only(bottom: 1))
    ->withStyle(new Style(background: Color::hex('20242C')))
    ->withBorder(BorderStyle::Rounded)
    ->withSizing(Sizing::of(Constraint::flex(), Constraint::min(5)));
```

`withContentWidth(30)->withHorizontalAlignment(Alignment::Center)` centres a
30-column area; `withContentHeight(3)` and `VerticalAlignment` do the same
vertically. Wrapping a widget in a `Container` changes nothing about focus,
clicks or keys.

Spacing is counted in terminal cells, from 0 to 255. A value outside that range
throws.
