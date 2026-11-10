# Changelog

Until 1.0, a minor version may break the API. Each break is listed here.

## Unreleased

### Added

- `Toggle`, a yes/no choice on one row, and `Field::toggle()`.

### Changed

- A `ProgressBar` draws the percent that sits on its fill without bold, so a terminal that draws bold black as grey keeps it readable.
- A `StatusBar` starts its text one column in, where its hints start, so it lines up with a `Footer`.
- The key help names the keys of a joined hint on one row, in the words of the footer: `choose ←/→` and `move ↑/↓`, where it had `left ←`, `right →`, `down ↓` and `up ↑`.

## 0.1.0 (2026-11-10)

The first release.
