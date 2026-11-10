# Changelog

Until 1.0, a minor version may break the API. Each break is listed here.

## Unreleased

### Added

- `Toggle`, a yes/no choice on one row, and `Field::toggle()`.

### Changed

- A `ProgressBar` draws the percent that sits on its fill without bold, so a terminal that draws bold black as grey keeps it readable.
- A `StatusBar` starts its text one column in, where its hints start, so it lines up with a `Footer`.
- The key help names the keys of a joined hint on one row, in the words of the footer: `choose ←/→` and `move ↑/↓`, where it had `left ←`, `right →`, `down ↓` and `up ↑`.
- `Paragraph::fromMarkdown()` leaves a blank line between blocks (paragraphs, headings, lists, quotes and rules), as `fromText()` and Markdown renderers do. The items of a list stay on rows next to each other.
- `Panel` and `Box` pad their content one column from the left and right border by default, as a `Card` does, so every app with a pane shifts its content one column in. `withPadding(Spacing::none())` puts it back against the border.
- A bound `Split` publishes its panes as stops, so Tab reaches them and the runtime routes to them. `Split::withMarkedPane()` and its keybindings are gone.

### Fixed

- A `Paragraph` or other content-sized child inside a `Container` keeps its rows in a column, where it got none.
- A `RadioRow` or `CheckboxRow` whose options wrap gets the rows they need in a column, where it was cut to one row.

## 0.1.0 (2026-11-10)

The first release.
