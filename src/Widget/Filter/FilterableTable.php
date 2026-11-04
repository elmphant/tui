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
use Elmphant\Tui\Message\Chosen;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Support\Places;
use Elmphant\Tui\Widget\Committing;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Field\InputDecoration;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Table\Column;
use Elmphant\Tui\Widget\Table\Row;
use Elmphant\Tui\Widget\Table\Table;

final readonly class FilterableTable implements Focusable, KeybindingsAware, Committing, PublishesHints, TakesText, BindsForTheMoment
{
    use Cloneable;
    use DrawsFocus;
    use FiltersItsContent;
    use KeybindingsAwareBehavior;
    use SearchMode;

    private const HEADER_AND_A_ROW = 2;

    private FilterMatches $matching;

    /**
     * @param list<Row> $rows
     */
    private function __construct(
        private array $rows,
        private TextInput $field,
        private Table $table,
        private ?Matcher $matcher = null,
        private string $noMatchText = 'No matches',
        private string $noDataText = '',
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $searching = false,
        ?FilterMatches $matching = null,
        private ?int $selectedSource = null,
    ) {
        $this->matching = FilterMatches::kept($matching, $rows, self::haystackOf(...), $matcher, $field->value());
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
        return $this->cloneWith(
            rows: $rows,
            selectedSource: Places::placeOf(
                $this->selectedRow()?->key(),
                array_map(static fn (Row $row): string => $row->key(), $rows),
                $this->selectedSource ?? 0,
            ),
        )->reFiltered();
    }

    public function summary(): string
    {
        return count($this->matchingIndexes()) . ' of ' . count($this->rows);
    }

    public function cursorRow(): ?Row
    {
        $index = $this->cursorSourceIndex();

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
        return $this->selectedSource === null ? null : $this->rows[$this->selectedSource] ?? null;
    }

    public function outcomeOf(Message $message, Rect $area): ?Message
    {
        if ($message instanceof MouseMsg) {
            return $this->table->outcomeOf($message, $this->contentArea($area));
        }

        $row = $this->cursorRow();

        return $message instanceof KeyMsg
            && $row?->selectable === true
            && !$this->isSearching()
            && $this->effectiveKeybindings()->matches($message, Actions::SELECT)
            ? new Chosen($row, $message)
            : null;
    }

    public function sizing(): Sizing
    {
        return Sizing::of(
            Constraint::flex(),
            Constraint::min(FilterBar::HEIGHT + FilterBar::GAP_BELOW + self::HEADER_AND_A_ROW)->withFlex(),
        );
    }

    /**
     * @param list<Row> $rows
     *
     * @return list<string>
     */
    private static function haystackOf(array $rows): array
    {
        return array_map(
            static fn (Row $row): string => implode(' ', $row->texts()),
            $rows,
        );
    }

    private function reFiltered(): self
    {
        $chosen = array_search($this->selectedSource, $this->matchingIndexes(), true);

        return $this->cloneWith(table: $this->table->withRows($this->matchingRows())->withSelectedIndex($chosen === false ? null : $chosen));
    }

    private function atTheTop(): self
    {
        return $this->cloneWith(table: $this->table->withCursor(0));
    }

    private function contentCursor(): int
    {
        return $this->table->cursor();
    }

    /**
     * @return UpdateResult<self>
     */
    private function contentAnswered(KeyMsg $message, Rect $area): UpdateResult
    {
        $result = (new ChildDispatcher($this->globalKeybindings))->dispatchOne($this->table, $message, $this->contentArea($area));

        return new UpdateResult($this->withTableAnswered($result->child), $result->command);
    }

    /**
     * @return UpdateResult<self>
     */
    private function withMouse(MouseMsg $message, Rect $area): UpdateResult
    {
        $dispatcher = new ChildDispatcher($this->globalKeybindings);
        $field = $dispatcher->dispatchOne($this->field, $message, FilterBar::barArea($area));
        $table = $dispatcher->dispatchOne($this->table, $message, $this->contentArea($area));

        return new UpdateResult(
            $this->cloneWith(field: $field->child)->withTableAnswered($table->child),
            Cmd::batch($field->command, $table->command),
        );
    }

    private function withTableAnswered(Table $table): self
    {
        $chosen = $table->selectedIndex();

        return $this->cloneWith(
            table: $table,
            selectedSource: $chosen === null ? $this->selectedSource : $this->matchingIndexes()[$chosen] ?? $this->selectedSource,
        );
    }

    private function barSummary(): string
    {
        return $this->summary();
    }

    private function nothingToShow(): string
    {
        return $this->rows === [] ? $this->noDataText : $this->noMatchText;
    }

    private function renderContent(Frame $frame, Rect $body, RenderContext $context): void
    {
        $this->table->render($frame, $body, $context);
    }
}
