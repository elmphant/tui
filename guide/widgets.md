# Widgets

[← README](../README.md)

Every widget is an immutable value. Configure it with `withX()` methods, keep the
interactive ones on your App, and bind them (see [Building an app](apps.md)).

## The list

Every widget is in `Elmphant\Tui\Widget\`, in the namespace the second column
names: `use Elmphant\Tui\Widget\Structure\Panel;`.

**Structure**

| Widget | In | What it is |
| --- | --- | --- |
| `Panel` | `Structure` | A scrolling box of widgets, with border and title — see [Scrolling](#scrolling) |
| `TabBar` | `Structure` | Tabs, switched by keyboard or mouse. `withBorder(false)` puts the tabs on one row with the page right below |
| `Split` | `Structure` | Two panes with a divider you can drag |
| `Modal` | `Popup` | A box over the screen that dims what is behind it — see [Modals](#modals) |
| `Overlay` / `PositionedOverlay` | `Popup` | One widget on top of another |
| `Card` / `Box` / `Container` | `Structure` | Borders, padding and margins around any widget — see [Layout](layout.md#decoration) |
| `Themed` | `Structure` | Draws one part of the screen with a changed theme: `Themed::around($list, fn (Theme $theme) => $theme->withSelectedStyle($style))` |

**Text**

| Widget | In | What it is |
| --- | --- | --- |
| `Text` | `Prose` | One line of text (`Text::new($text)`). What does not fit is cut off, so take a `Paragraph` for text that may be long |
| `Paragraph` | `Prose` | Wrapped text, named by what you hand it: `Paragraph::fromText()`, `fromLines()`, `fromMarkdown()`, `fromAnsi()` |
| `Separator` / `Spacer` | `Prose` | A line, or empty space |

**Input**

| Widget | In | What it is |
| --- | --- | --- |
| `TextInput` | `Field` | A one-line text field. Underlined by default (`withDecoration(InputDecoration::Filled)` fills it instead). `withSubmit()` makes Enter commit it, for the bind closure, and `withSubmit($message)` also sends a message |
| `TextArea` | `Field` | A text editor of many lines: Enter starts a line, Backspace joins two, Up and Down keep the column, and it scrolls with the cursor. Wide characters count two columns. `value()` joins the lines with `\n`, and `cursor()` answers the line and the column |
| `Button` | `Field` | A button, three rows with a border. `withBorder(false)` makes it one row, `[ Save ]`, and so does a slot of fewer rows |
| `Checkbox` / `CheckboxGroup` | `Choice` | Yes/no choices |
| `Radio` / `Select` | `Choice` | One choice out of a list. Take a `Radio` for a few options that all show, each with a mark, where one is always chosen (a setting with a default), unless `withClearable()` lets the reader put it back down. Take a `Select` for a longer list that scrolls in the rows it gets and starts with nothing chosen. Put either in a `Dropdown` to keep it on one row |
| `Dropdown` | `Popup` | One row that opens a list over the screen. It filters when its content filters: `Dropdown::new(FilterableList::new($items))`. To load new options, hand it `withContent($dropdown->content()->withItems($items))`, so an open list stays open with its query |
| `Form` | `Form` | Named fields with labels, checks and a submit button — see [Forms](#forms) |

**Lists and tables**

| Widget | In | What it is |
| --- | --- | --- |
| `ListView` | `Listing` | A scrolling list. Long items wrap, and leading spaces indent a title on every row it wraps to. A record of yours can be the item: implement `ListItem` with `use ListItemBehavior`, then edit the row under the cursor with `withCursorItem()` and `withoutCursorItem()`. Its rows are plain text: for colour in a row, such as a log level, take a `Table`, whose cells take `Span::token()` |
| `Table` | `Table` | Rows and columns with a cursor, selection, mouse and scrolling. Cells wrap. `withColumnGap(0)` packs columns, `withHeader(false)` hides the header |
| `StaticTable` | `Table` | A bordered table that draws every row, with no cursor. For small data |
| `FilterableList` | `Filter` | A search field over a `ListView`. Typing filters, and arrows and Enter pick |
| `FilterableTable` | `Filter` | A search field over a `Table`. `withColumns()` changes the titles, such as a sort marker, and keeps the query and the cursor |
| `FilterableStaticTable` | `Filter` | A search field over a `StaticTable` |
| `TreeView` | `Tree` | A tree whose children load when opened — see [Trees that load as they open](#trees-that-load-as-they-open) |

**Bars and feedback**

| Widget | In | What it is |
| --- | --- | --- |
| `Header` / `Footer` | `Bar` | Top and bottom bars with left, center and right slots. The centre gives way to the sides |
| `FocusHints` | `Bar` | The keys that work right now — see [Keys](keys-and-focus.md#footer-hints) |
| `StatusBar` | `Bar` | A line of key hints |
| `Scrollbar` | `Bar` | A vertical scroll indicator |
| `Alert` | `Feedback` | One coloured row: `Alert::warning($text)`, `error()`, `success()`, `info()` |
| `ProgressBar` | `Feedback` | A bar that fills up smoothly: `ProgressBar::new(0.4)` |
| `Spinner` / `ActivityBar` | `Feedback` | Loading animations with a label: `Spinner::new('loading…')`. They animate by themselves, no timer needed |

## Links

A `Span::link()` is text the reader can open with a click (or Cmd/Ctrl+click), in
terminals that support OSC 8 links: iTerm2, kitty, WezTerm, VS Code, Windows
Terminal and others. Other terminals show the text alone. A Markdown link in
`Paragraph::fromMarkdown()` becomes one too.

```php
use Elmphant\Tui\Rendering\{Line, Span};
use Elmphant\Tui\Widget\Prose\Paragraph;

$help = Paragraph::fromLines([new Line([Span::raw('Read '), Span::link('the docs', 'https://example.com/docs')])]);
```

In a test, `$screen->styleAt($column, $row)->link` says which URL a cell opens.

## Filtered lists and tables

A `FilterableList` matches the query against each item's `filterValue()`, which
`ListItemBehavior` answers with `title()`. Return more from it to make other
fields searchable, an author's name beside the subject. `items()` answers every
item you handed in, matching or not, and `cursorItem()` the one under the cursor. While the reader types, the cursor jumps to
the best match. `withItems()` and `withQuery()` from your code keep it on its
item instead. For a list that follows new items, such as a log, put the cursor
on the last match with `withCursorAtEnd()` after `withItems()`. A `FilterableTable` searches the text of a
row's cells, and `Row::withFilterValue($text)` searches other text instead, such
as a description the table does not show.

The filter widgets tell you what the reader picked in terms of the rows you
handed in, not the filtered ones: `cursorSourceIndex()`, and on a table `cursorRow()`
and `selectedRow()`. Bound, a click or Enter answers `Chosen`, carrying the item
or the row. `withNoMatchText()` sets what shows when nothing matches the
query, and `withNoDataText()` what shows when there is nothing to filter.

## Building a table

A `Table` takes its columns, each with a title and a width, and its rows. A
cell is text or a `Span`, so a status can take its colour from the theme:

```php
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\Table;

$table = Table::new(
    [Column::new('Method', Constraint::exact(7)), Column::new('URL', Constraint::flex()), Column::new('Status', Constraint::exact(6))],
    [
        Row::new('GET', '/users', Span::token('200', StyleToken::Success))->withKey('1'),
        Row::new('POST', '/users', Span::token('401', StyleToken::Error))->withKey('2'),
    ],
);
```

`Table::fromCells($columns, [['GET', '/users', '200']])` takes plain text.
`FilterableTable::new()` takes the same columns and rows.

To edit a row, put a `TextInput` for each column below the table. `on(Chosen::class, …)`
on the table fills them from `$chosen->choice` (the `Row`), `withSubmit()` on the last
field answers `Submitted`, and its handler builds the rows again with the edited one,
under the same key, so the cursor stays on it.

## Lists keep their place

When you hand a list new items, the cursor and the selection stay on the same
item, found by its **key**. The key is the text shown, unless you give one:
`new SimpleListItem($title, $description, key: $id)`, `Row::withKey($id)`,
`new TabPage($label, key: $id)`. Give a key to rows whose text can repeat or
change. When the cursor's item is gone, the cursor moves to a neighbour.

## Trees that load as they open

A `TreeView` shows `TreeNode`s. `SimpleTreeNode($key, $label)` is a leaf, and
`children: null` makes a branch whose children are not loaded yet: the tree draws
it with a `⋯` row once it is open. Right opens a branch, Left closes it, and Enter
opens or closes it too. Enter or a click on a leaf answers `Chosen` with the
`TreeNode`, and `cursorNode()` names the node under the cursor.

To load a branch when the reader opens it, bind the tree with `Component::bind()`,
whose closure sees the tree from before and after the key:
`$tree->pendingSince($app->tree)` lists the branches that were just opened and
are still unloaded. Start a command for each, and hand the tree the loaded nodes
with `withRoots()`, which keeps the cursor and what is open:

```php
use Elmphant\Tui\Application\{App, Component, InputContext};
use Elmphant\Tui\Core\{Cmd, EffectId, Message, UpdateResult};
use Elmphant\Tui\Message\{Chosen, ProcessResultMsg};
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Tree\{SimpleTreeNode, TreeNode, TreePath, TreeView};
use Elmphant\Tui\Widget\Widget;

final readonly class Files extends App
{
    /**
     * @param array<string, list<string>> $listings each loaded folder's entries, a folder's name ending in "/"
     */
    public function __construct(private string $root, private TreeView $tree, private array $listings = [], private string $chosen = '') {}

    public static function in(string $root): self
    {
        return new self($root, TreeView::new([new SimpleTreeNode('.', $root, children: null)])->expand(new TreePath('.')));
    }

    public function view(): Widget
    {
        return Layout::column(
            Component::bind('tree', $this->tree, static fn (self $app, TreeView $tree): self|UpdateResult => $app->opened($tree))
                ->on(Chosen::class, static fn (self $app, Chosen $chosen): self => $app->cloneWith(chosen: $chosen->choice instanceof TreeNode ? $chosen->choice->key() : '')),
            Text::new("chosen: {$this->chosen}"),
        );
    }

    protected function setup(): ?Cmd
    {
        return $this->listingOf(new TreePath('.'));
    }

    protected function onMessage(Message $message, InputContext $input): self|UpdateResult
    {
        if (!$message instanceof ProcessResultMsg || !str_starts_with($message->effectId->value ?? '', 'ls:')) {
            return $this;
        }

        $listings = [...$this->listings, substr($message->effectId->value ?? '', 3) => array_values(array_filter(explode("\n", $message->stdout)))];

        return $this->cloneWith(listings: $listings, tree: $this->tree->withRoots([$this->folder('.', $this->root, $listings)]));
    }

    private function opened(TreeView $tree): self|UpdateResult
    {
        $loads = array_map($this->listingOf(...), $tree->pendingSince($this->tree));

        return $loads === [] ? $this->cloneWith(tree: $tree) : new UpdateResult($this->cloneWith(tree: $tree), Cmd::batch(...$loads));
    }

    private function listingOf(TreePath $path): Cmd
    {
        $folder = implode('/', $path->keys);

        return Cmd::exec(['ls', '-1Ap', "{$this->root}/{$folder}"], id: EffectId::named("ls:{$folder}"));
    }

    /**
     * @param array<string, list<string>> $listings
     */
    private function folder(string $path, string $name, array $listings): TreeNode
    {
        $entries = $listings[$path] ?? null;
        $children = $entries === null ? null : array_map(
            fn (string $entry): TreeNode => str_ends_with($entry, '/')
                ? $this->folder($path . '/' . rtrim($entry, '/'), rtrim($entry, '/'), $listings)
                : new SimpleTreeNode($entry, $entry),
            $entries,
        );

        return new SimpleTreeNode(basename($path), $name, children: $children, expandable: true);
    }
}
```

In a test, answer each listing by its name: `$harness->answer('ls:.', stdout: "src/\nREADME.md\n")`,
then `press('down', 'right')` opens `src` and asks for `ls:./src`.

## Forms

A `Form` is a pane of named fields with a submit button at the bottom. Each
field is a real widget, so tab and the mouse reach it. The form asks the fields
for their values, so you keep no copy:

```php
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Widget\Form\{Field, Form, ShowErrors};

$form = Form::new('Create backend user')
    ->withField(Field::text('username', 'Username', 'The login name')->withRequired())
    ->withField(Field::secret('password', 'Password'))
    ->withField(Field::choice('site', 'Site', ['main' => 'Main website', 'shop' => 'Shop']))
    ->withField(Field::flag('admin', 'Admin', 'Create the user with admin rights'))
    ->withFoot('❯ backend:user:create')
    ->withSubmit('Run');

$tried = $form->withSubmitAttempted();                         // from now on, problems show under their fields
$tried->missing();                                    // ['username']
$tried->stopOf('username', ComponentId::named('form')); // where Cmd::focus() sends the keyboard
$form->values();                                      // ['username' => '', 'password' => '', 'site' => null, 'admin' => false]
$form->withErrorsShown(ShowErrors::AfterLeaving);     // or show a field's problem as soon as the reader leaves it
```

Bind it and react to the submit:
`$this->bind('form')->on(Pressed::class, static fn (self $app): self => $app->created($app->form->values()))`.
The handler runs with the form already stored, so it reads the values there.

- Enter moves to the next field, and in the last field presses submit. Both
  that and a click on the button answer `Pressed`.
- `withSubmit('Run', $message)` also sends a message, for a form inside a
  mounted model that must reach its host through `Mount::toHost()`. A `Button`
  works the same: `Button::new('Save')` answers `Pressed` when bound.
- `withValues(['username' => 'ada'])` fills fields by name, for an edit form.
- `withIntro()` adds text above the fields. `withFoot()` and
  `withSubmitDisabled()` say what the button will do, or why it can't.
- `withCancel()` makes Escape answer `Dismissed`, unless a field uses the key
  itself. `withCancel($message)` also sends a message.
- `Field::secret()` is a text field that hides what is typed.
- `Field::choice()` shows a row of options where they fit and a filterable list
  where they don't. `Field::of()` takes any widget that implements `HoldsAValue`.
  `value()` is the one question a form asks every field. The choice widgets also
  answer it in their own words, `selectedValue()`, `checkedValues()` and
  `isChecked()`, and the two always agree. Use those in your own code, where
  the type says more.

`withRequired()` is one check. `addRule()` adds your own: it gets the value and
returns a problem to show, or `null`. The first problem wins, and an empty field
is not checked:

```php
use Elmphant\Tui\Widget\Form\Field;

Field::text('due', 'Due date')
    ->addRule(static fn (string $date): ?string => strtotime($date) === false ? 'Not a date' : null);
```

## Modals

While a `Modal` is on screen, everything behind it ignores keys and clicks, and
the keyboard moves into what it holds. Keep the modal on the App and bind it once:
what its content does reaches the modal's handlers, and so does the modal's own
`Dismissed` on Escape or a click outside:

```php
use Elmphant\Tui\Application\{App, AppActions};
use Elmphant\Tui\Message\{Dismissed, Submitted};
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Popup\{Modal, Overlay};
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;

final readonly class Notes extends App
{
    /** @param list<string> $notes */
    public function __construct(private array $notes = [], private ?Modal $dialog = null) {}

    public function view(): Widget
    {
        $page = Text::new($this->notes === [] ? 'Ctrl+N adds a note' : implode("\n", $this->notes));

        return $this->dialog === null ? $page : Overlay::new($page, $this->bind('dialog')
            ->on(Submitted::class, static fn (self $app, Submitted $submitted): self => new self([...$app->notes, $submitted->value]))
            ->on(Dismissed::class, static fn (self $app): self => new self($app->notes)));
    }

    protected function actions(): AppActions
    {
        return parent::actions()->with('new', ['ctrl+n'], static fn (self $app): self => new self(
            $app->notes,
            Modal::new('New note', TextInput::new()->withSubmit())->withSizedToContent(),
        ), 'new note');
    }
}
```

A `Form` inside a modal works the same way: its fields become stops of their own
inside the dialog.

When the modal closes, the keyboard goes back to where it was.
`withBorder(false)` drops the frame when the content has its own, and
`withOutsideClickDismissal(false)` keeps a half-filled form open after a stray
click.

## Scrolling

A `Panel` scrolls with the wheel, PageUp/PageDown and Home/End, scrolls a
field into view when tab reaches it, and keeps the cursor row of a list or table
inside it in view. For a log or a chat,
`withFollowingTheEnd()` keeps the last row in view as rows arrive, until the
reader scrolls up. Keep the panel on the App and add to it, so it remembers
where it is:

```php
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;

$log = Panel::new('Log')->withFollowingTheEnd();
$log = $log->add(Text::new('build finished'));
```

A `Panel` lights its border while the keyboard is inside it, on a bound widget
in it. The panel itself needs no binding for that, so `Panel::new('To Do')->add($boundList)`
shows which column is active.

## Copying text

While an app reads the mouse, the terminal can't select text itself. So a bound
`Panel` lets the reader select its text with the mouse instead: press, drag and
let go. The selected cells show reversed, and letting go copies their text with
`Cmd::copy()`. Dragging past the top or bottom edge scrolls the panel, and rows
scrolled out of view are copied too. A click without a drag clears the selection.

A panel only hears the mouse when it is bound, so bind every panel a reader
should copy from, and keep it on the App:

```php
use Elmphant\Tui\Application\{App, Component};
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;

final readonly class Report extends App
{
    public function __construct(private Panel $output = new Panel('Output')) {}

    public function view(): Widget
    {
        return Component::bind('output', $this->output, static fn (self $app, Panel $output): self => new self($output));
    }
}
```

A bound panel is a tab stop. For a card that holds nothing to operate, add
`->withNavigation(Navigation::Never)`: tab and clicks pass it by, and the
reader can still copy from it.

- A press in the padding or below the last row starts at the nearest text, so
  the reader doesn't have to hit the text exactly.
- A press on a field in the panel still reaches the field.
- A line a `Paragraph` wrapped copies as one line again. Other widgets copy as
  they are drawn, a table with its borders.
- New rows don't move the selection. A press stops "follow the end" until the
  reader scrolls back down.
- A host that builds the panel anew each time carries the selection over with
  `marked()` and `withMarked()`, the same way it carries `scrollOffset()`.

`Cmd::copy()` uses OSC 52, so the terminal must allow it: kitty, WezTerm,
Ghostty and Alacritty do. iTerm2 needs "Applications in terminal may access
clipboard". GNOME Terminal and macOS Terminal.app don't support it. Inside tmux, add `set -g set-clipboard on`. No terminal says whether
the copy worked.

## Example: two panels

```php
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Widget\Choice\{Radio, Select};
use Elmphant\Tui\Widget\Feedback\ProgressBar;
use Elmphant\Tui\Widget\Prose\{Separator, Spacer, Text};
use Elmphant\Tui\Widget\Structure\Panel;

$left = Panel::new('Preferences')
    ->add(Text::token('Appearance', StyleToken::Accent))
    ->add(Spacer::new())
    ->add(Radio::new(['Dark', 'Light', 'Dracula', 'Nord'], 'Theme'))
    ->add(Separator::new())
    ->add(Text::new('Font'))
    ->add(Select::new(['JetBrains Mono', 'Fira Code', 'Cascadia']));

$right = Panel::new('System')
    ->add(Text::new('CPU'))
    ->add(ProgressBar::new()->withProgress(0.73))
    ->add(Text::new('Memory'))
    ->add(ProgressBar::new()->withProgress(0.45));
```

## Built to be readable

Some defaults exist because the alternative is hard to read. Tests keep them in
place:

- Every built-in theme has enough contrast (WCAG AA, 4.5:1) for text on filled
  rows such as the footer and the selected row.
- A list shows its selected row even when another widget has the keyboard.
- A text field shows its full width, underlined.
- A scrollbar uses block characters, so it never looks like a second border.
- Long list items wrap instead of being cut off, up to a few rows.
