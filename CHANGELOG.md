# Changelog

Until 1.0, a minor version may break the API. Each break is listed here.

## Unreleased

- `Terminal` has a new method, `copy()`. A custom terminal must implement it.
  `Cmd::copy()` now reaches the clipboard inside tmux with tmux's default
  settings.
- The key help filters as you type, and Enter runs the key under the cursor.
  `NamesItsKeys::ownKeys()` returns `KeyHint`s: build them with
  `Keybindings::keyHints()` instead of `hints()`. `DescribesKeys::keyHelp()`
  returns `KeyHint`s and the port has a new method, `keyHelpKeys()`.

## 0.1.0 (2026-11-09)

The first release.
