# Showcase

[← README](../README.md)

What apps built with Elmphant TUI look like. Every recording is a real app
running in a terminal, driven by the keyboard.

- [Widgets on one screen](#widgets-on-one-screen)
- [A kanban board](#a-kanban-board)
- [Dropdowns](#dropdowns)
- [A form](#a-form)
- [A form below the prompt](#a-form-below-the-prompt)
- [Questions one at a time](#questions-one-at-a-time)

## Widgets on one screen

Text fields, checkboxes, a table with section rows, a list, tabs, a package
list that arrives in the background, and the key help that `?` opens. Tab walks
from widget to widget.

<img src="../media/showcase.gif" width="880"
     alt="A full-screen app with a profile form, a task table, a file list, a tabbed settings panel and a package list. The reader fills in the form, ticks checkboxes, scrolls the table and the lists, switches tabs and opens the key help.">

Read more: [Widgets](widgets.md) · [Layout](layout.md) · [Keys and focus](keys-and-focus.md)

## A kanban board

A widget drawn by hand. Arrow keys move between cards, Enter moves a card to the
next column, and cards can be added and deleted. Dragging a card with the mouse
works too.

<img src="../media/kanban.gif" width="880"
     alt="A kanban board with the columns To Do, In Progress and Done. Cards move from column to column, one card is deleted and a new one is added.">

Read more: [Custom widgets](custom-widgets.md) · [Building an app](apps.md)

## Dropdowns

A `Dropdown` holds a `Select`, a `Radio`, a `CheckboxGroup` or a `FilterableList`
and floats its list over the screen. Ticking a checkbox keeps the list open,
choosing an option closes it, and typing narrows a filterable list.

<img src="../media/select.gif" width="720"
     alt="A pizza order form with four dropdowns. The reader picks a size, a crust and three toppings, then types moon to find a delivery address.">

Read more: [Widgets](widgets.md)

## A form

A `Form` of named fields. Submitting with a required field empty shows the
problem under it and sends the keyboard there.

<img src="../media/form.gif" width="720"
     alt="A form to create an account. Submitting it empty shows that the username is required. The reader fills in the fields, picks a site, ticks a flag and submits, and the values are shown below the form.">

Read more: [Forms](widgets.md#forms)

## A form below the prompt

The same kind of form, drawn inline: it takes only the rows it needs below the
shell prompt, and what it leaves stays in the scrollback.

<img src="../media/inline-form.gif" width="720"
     alt="A database form drawn below the shell prompt. The reader fills in a host and a name, picks a driver, searches for a time zone and saves. The values are printed and the prompt returns.">

Read more: [Drawing inline](running.md#drawing-inline)

## Questions one at a time

An inline app that asks one question, prints the answer above itself with
`Cmd::println()`, and asks the next.

<img src="../media/inline-steps.gif" width="720"
     alt="A setup assistant below the shell prompt asks for a project name, a database and a host, one after the other. Each answer is printed as a checked line, and the prompt returns.">

Read more: [Drawing inline](running.md#drawing-inline) · [Commands and effects](effects.md)
