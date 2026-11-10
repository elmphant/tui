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
- `DecoratesOneChild` requires the class that uses it to implement `Widget` too. From level 2, PHPStan reports a wrapper that forgot it, which no view could return.
- An id given to `bind()`, `mount()`, `Component::bind()`, `Component::named()` or `Mount::named()` may not hold a `/`, and throws. The framework writes a `/` between a container and its child, so such an id was taken for a child and addressed the wrong component. A `/` in an `at:` key is written `%2F` in the id.

### Fixed

- A `Paragraph` or other content-sized child inside a `Container` keeps its rows in a column, where it got none.
- A `RadioRow` or `CheckboxRow` whose options wrap gets the rows they need in a column, where it was cut to one row.
- The footer and the key help name a `withAction()` key by its label inside a mount too, where they showed the action name.
- `ModelHarness` delivers the messages a model sends in the order they were sent, as `Program` does, and nothing after a `Cmd::quit()`. It delivered a message sent while handling another before the rest of the batch, and kept going after a quit.
- Ctrl+C, SIGTERM, SIGHUP and the end of stdin stop the program at once, ahead of the messages already queued. They waited for the whole backlog.
- A mouse report with coordinates past 65535 lands on cell 65535, where it gave a position near `PHP_INT_MAX`.
- `Frame::pushClip()` with a rect larger than the frame no longer lets `toggleAttribute()` or `underlay()` write past its edge.
- A key outside ASCII, such as `é`, `ü` or `€`, can be bound. `KeyPattern` refused it.

## 0.1.0 (2026-11-10)

The first release.
