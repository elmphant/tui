<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Filter;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\BindsForTheMoment;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Input\TakesText;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Field\InputDecoration;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\Table;
use WeakMap;

final readonly class FilterableTable implements Focusable, KeybindingsAware, Committing, PublishesHints, TakesText, BindsForTheMoment
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;
    use SearchMode;

    private const HEADER_AND_A_ROW = 2;

    private const TABLE_ACTIONS = [
        Actions::CURSOR_UP,
        Actions::CURSOR_DOWN,
        Actions::PAGE_UP,
        Actions::PAGE_DOWN,
        Actions::HOME,
        Actions::END,
        Actions::SELECT,
    ];

    /**
     * @param list<Row> $rows
     */
    private function __construct(
        private array $rows,
        private TextInput $field,
        private Table $table,
        private ?Matcher $matcher = null,
        private string $noMatchText = 'No matches',
        private string $noRowsText = '',
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $searching = false,
    ) {
    }

    /**
     * @param list<Column> $columns
     * @param list<Row>    $rows
     */
    public static function new(array $columns, array $rows): self
    {
        return new self(
            $rows,
            TextInput::new()->withPlaceholder(FilterBar::PLACEHOLDER)->withDecoration(InputDecoration::None),
            Table::new($columns, $rows),
        );
    }

    /**
     * @param list<Row> $rows
     */
    public function withRows(array $rows): self
    {
        return $this->cloneWith(rows: $rows)->reFiltered();
    }

    public function withMatcher(Matcher $matcher): self
    {
        return $this->cloneWith(matcher: $matcher)->reFiltered();
    }

    public function withPlaceholder(string $placeholder): self
    {
        return $this->cloneWith(field: $this->field->withPlaceholder($placeholder));
    }

    public function withNoMatchText(string $noMatchText): self
    {
        return $this->cloneWith(noMatchText: $noMatchText);
    }

    public function withNoRowsText(string $noRowsText): self
    {
        return $this->cloneWith(noRowsText: $noRowsText);
    }

    public function query(): string
    {
        return $this->field->value();
    }

    public function withQuery(string $query): self
    {
        return $query === $this->query() ? $this : $this->cloneWith(field: $this->field->withValue($query))->reFiltered();
    }

    public function summary(): string
    {
        return count($this->matchingIndexes()) . ' of ' . count($this->rows);
    }

    public function sourceIndex(): ?int
    {
        return $this->matchingIndexes()[$this->table->cursor()] ?? null;
    }

    public function cursorRow(): ?Row
    {
        $index = $this->sourceIndex();

        return $index === null ? null : $this->rows[$index];
    }

    /**
     * @return list<Row>
     */
    public function matchingRows(): array
    {
        return array_map(fn (int $index): Row => $this->rows[$index], $this->matchingIndexes());
    }

    public function selectedRow(): ?Row
    {
        return $this->table->selectedTypedRow();
    }

    public function hints(): array
    {
        return $this->searchHints([
            'move' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN],
            'choose' => Actions::SELECT,
            ...($this->query() === '' ? [] : ['clear filter' => $this->searchIsBound() ? Actions::CANCEL : [Actions::CLEAR_LINE, Actions::CANCEL]]),
        ]);
    }

    public function commits(Message $message): bool
    {
        return $message instanceof KeyMsg
            && !$this->isSearching()
            && $this->effectiveKeybindings()->matches($message, Actions::SELECT)
            && $this->cursorRow()?->selectable === true;
    }

    public static function defaultKeybindings(): Keybindings
    {
        return (new Keybindings([
            Actions::CURSOR_UP => ['up'],
            Actions::CURSOR_DOWN => ['down'],
            Actions::PAGE_UP => ['shift+up', 'page_up'],
            Actions::PAGE_DOWN => ['shift+down', 'page_down'],
            Actions::HOME => [],
            Actions::END => [],
            Actions::SELECT => ['enter'],
        ]))->merge(FilterBar::keybindings())
            ->append(TextInput::defaultKeybindings());
    }

    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex(),
            Constraint::min(FilterBar::HEIGHT + FilterBar::GAP_BELOW + self::HEADER_AND_A_ROW)->withFlex(),
        );
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($message instanceof MouseMsg) {
            return $this->withMouse($message, $area);
        }

        if (!$message instanceof KeyMsg && !$message instanceof PasteMsg) {
            return new UpdateResult($this);
        }

        if ($message instanceof PasteMsg) {
            return $this->typedIntoTheField($message, $area);
        }

        $toggled = $this->searchToggledBy($message);
        if ($toggled !== null) {
            return new UpdateResult($toggled);
        }

        if ($this->effectiveKeybindings()->matches($message, Actions::CANCEL)) {
            return new UpdateResult($this->withQuery(''));
        }

        if ($this->belongsToTheTable($message)) {
            $result = (new ChildDispatcher($this->globalKeybindings))->dispatchOne($this->table, $message, $this->tableArea($area));

            return new UpdateResult($this->cloneWith(table: $result->child), $result->command);
        }

        return $this->takesText() ? $this->typedIntoTheField($message, $area) : new UpdateResult($this);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $field = fn () => FilterBar::render($frame, FilterBar::barArea($area), $context, $this->fieldDrawnBy($context), $this->summary());
        $this->fieldHoldsTheKeys($context) ? $field() : $context->withoutFocus($field);

        if ($this->matchingIndexes() === []) {
            Paragraph::fromLines([new Line([Span::token($this->rows === [] ? $this->noRowsText : $this->noMatchText, StyleToken::Dim)])])
                ->render($frame, $this->tableArea($area), $context);

            return;
        }

        $this->table->render($frame, $this->tableArea($area), $context);
    }

    /**
     * @return UpdateResult<static>
     */
    private function typedIntoTheField(KeyMsg|PasteMsg $message, Rect $area): UpdateResult
    {
        $result = (new ChildDispatcher($this->globalKeybindings))->dispatchOne($this->field, $message, FilterBar::barArea($area));
        $typed = $this->cloneWith(field: $result->child);

        return new UpdateResult($result->child->value() === $this->query() ? $typed : $typed->reFiltered(), $result->command);
    }

    /**
     * @return UpdateResult<self>
     */
    private function withMouse(MouseMsg $message, Rect $area): UpdateResult
    {
        $dispatcher = new ChildDispatcher($this->globalKeybindings);
        $field = $dispatcher->dispatchOne($this->field, $message, FilterBar::barArea($area));
        $table = $dispatcher->dispatchOne($this->table, $message, $this->tableArea($area));

        return new UpdateResult(
            $this->cloneWith(field: $field->child, table: $table->child),
            Cmd::combine($field->command, $table->command),
        );
    }

    private function fieldDrawnBy(RenderContext $context): TextInput
    {
        return $this->field->withDecoration(
            $this->drawsFocused($context) ? InputDecoration::Underline : InputDecoration::None,
        );
    }

    private function belongsToTheTable(KeyMsg $message): bool
    {
        $bindings = $this->effectiveKeybindings();

        foreach (self::TABLE_ACTIONS as $action) {
            if ($bindings->matches($message, $action)) {
                return true;
            }
        }

        return false;
    }

    private function reFiltered(): self
    {
        return $this->cloneWith(table: $this->table->withRows($this->matchingRows()));
    }

    /**
     * @infection-ignore-all a memo: the answer is the same without it, only slower
     *
     * @return list<int>
     */
    private function matchingIndexes(): array
    {
        static $answers = null;
        $answers ??= new WeakMap();
        /** @var WeakMap<self, list<int>> $answers */

        return $answers[$this] ??= $this->matchingIndexesWorkedOut();
    }

    /**
     * @return list<int>
     */
    private function matchingIndexesWorkedOut(): array
    {
        return FilterBar::matching($this->matcher, $this->query(), array_map(
            static fn (Row $row): string => implode(' ', array_map(
                static fn (string|Span $cell): string => $cell instanceof Span ? $cell->content : $cell,
                $row->cells,
            )),
            $this->rows,
        ));
    }

    private function tableArea(Rect $area): Rect
    {
        return FilterBar::bodyArea($area, FilterBar::GAP_BELOW);
    }
}
