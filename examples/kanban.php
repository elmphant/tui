<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Application\AppActions;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\Gesture;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Direction;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Message\MouseAction;
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Rendering\Block;
use Elmphant\Tui\Rendering\BorderType;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Runtime\ScreenMode;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Bar\FocusHints;
use Elmphant\Tui\Widget\Bar\Footer;
use Elmphant\Tui\Widget\Focus\CapturesThePointer;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\Layout\Layout;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Structure\Card;
use Elmphant\Tui\Widget\Widget;

final readonly class Task
{
    public function __construct(public string $title, public string $description)
    {
    }
}

final readonly class Column
{
    /**
     * @param list<Task> $tasks
     */
    public function __construct(public string $title, public array $tasks)
    {
    }

    public function withAddedTask(Task $task): self
    {
        return new self(title: $this->title, tasks: [...$this->tasks, $task]);
    }

    public function withoutTaskAt(int $index): self
    {
        $tasks = $this->tasks;
        array_splice($tasks, $index, 1);

        return new self(title: $this->title, tasks: $tasks);
    }
}

final readonly class Drag
{
    public function __construct(public int $column, public int $task, public int $x, public int $y)
    {
    }

    public function movedTo(int $x, int $y): self
    {
        return new self(column: $this->column, task: $this->task, x: $x, y: $y);
    }
}

final readonly class ColumnLayout
{
    /**
     * @param list<Rect> $cards
     */
    public function __construct(
        public Rect $frame,
        public Rect $content,
        public array $cards,
        public int $firstVisible,
        public int $visibleCount,
    ) {
    }

    public function cardAt(int $x, int $y): ?int
    {
        if (!$this->content->contains($x, $y)) {
            return null;
        }
        foreach ($this->cards as $index => $card) {
            if ($card->contains($x, $y)) {
                return $index;
            }
        }

        return null;
    }

    public function hidesCardsAbove(): bool
    {
        return $this->firstVisible > 0;
    }

    public function hidesCardsBelow(): bool
    {
        return $this->firstVisible + $this->visibleCount < count($this->cards);
    }
}

final readonly class Board implements Focusable, PublishesHints, CapturesThePointer
{
    use Cloneable;

    private const CARD_HEIGHT = 4;

    /**
     * @param list<Column> $columns
     */
    private function __construct(
        private array $columns,
        private int $cursorColumn = 0,
        private int $cursorTask = 0,
        private ?Drag $drag = null,
    ) {
    }

    /**
     * @param list<Column> $columns
     */
    public static function of(array $columns): self
    {
        return new self($columns);
    }

    public function withAddedTask(Task $task): self
    {
        $columns = $this->columns;
        $columns[$this->cursorColumn] = $columns[$this->cursorColumn]->withAddedTask($task);

        return $this->cloneWith(columns: $columns, cursorTask: count($columns[$this->cursorColumn]->tasks) - 1, drag: null);
    }

    public function withoutCursorTask(): self
    {
        if ($this->columns[$this->cursorColumn]->tasks === []) {
            return $this;
        }
        $columns = $this->columns;
        $columns[$this->cursorColumn] = $columns[$this->cursorColumn]->withoutTaskAt($this->cursorTask);

        return $this->cloneWith(columns: $columns, drag: null)->withCursorAt($this->cursorColumn, $this->cursorTask);
    }

    public function withCursorTaskAdvanced(): self
    {
        if ($this->columns[$this->cursorColumn]->tasks === []) {
            return $this;
        }

        return $this->withTaskMoved($this->cursorColumn, $this->cursorTask, ($this->cursorColumn + 1) % count($this->columns));
    }

    public function sizing(): Sizing
    {
        return Sizing::of(columns: Constraint::flex(), rows: Constraint::flex());
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $theme = $context->theme;
        $dropTarget = $this->drag === null ? null : $this->columnAt($area, $this->drag->x, $this->drag->y);

        foreach ($this->layout($area) as $columnIndex => $layout) {
            $column = $this->columns[$columnIndex];
            $isCursorColumn = $this->drag === null && $context->hasFocus() && $columnIndex === $this->cursorColumn;
            $isHighlighted = $isCursorColumn || $columnIndex === $dropTarget;

            Block::new()
                ->withBorderType(BorderType::Rounded)
                ->withBorderStyle(match (true) {
                    $columnIndex === $dropTarget => $theme->accent->bold(),
                    $isCursorColumn => $theme->focusedBorderStyle,
                    default => $theme->borderStyle,
                })
                ->withTitle($column->title . ' (' . count($column->tasks) . ')')
                ->withTitleStyle($isHighlighted ? $theme->accent->bold() : $theme->title)
                ->render($frame, $layout->frame);

            $frame->clipped($layout->content, function () use ($frame, $context, $theme, $column, $columnIndex, $layout, $isCursorColumn): void {
                foreach ($column->tasks as $taskIndex => $task) {
                    $isCursor = $isCursorColumn && $taskIndex === $this->cursorTask;
                    $context->draw(
                        Card::new($task->title, $task->description)
                            ->withBorderStyle($isCursor ? $theme->accent : $theme->borderStyle)
                            ->withTitleStyle($isCursor ? $theme->accent->bold() : $theme->base->bold())
                            ->withDimmed($this->drag?->column === $columnIndex && $this->drag->task === $taskIndex),
                        $frame,
                        $layout->cards[$taskIndex],
                    );
                }
            });

            $markerColumn = $layout->frame->right() - 2;
            if ($layout->hidesCardsAbove()) {
                $frame->putStyled($markerColumn, $layout->content->y, '↑', $theme->accent);
            }
            if ($layout->hidesCardsBelow()) {
                $frame->putStyled($markerColumn, $layout->content->bottom() - 1, '↓', $theme->accent);
            }
        }

        $this->drawDraggedCard($frame, $area, $context);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        return new UpdateResult(match (true) {
            $message instanceof KeyMsg => $this->afterKey($message),
            $message instanceof MouseMsg => $this->afterMouse($message, $area),
            default => $this,
        });
    }

    public function hints(): array
    {
        return $this->drag === null
            ? self::keys()->hints(['column' => [Actions::CURSOR_LEFT, Actions::CURSOR_RIGHT], 'card' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN], 'move card' => Gesture::Drag])
            : self::keys()->hints(['cancel drag' => Actions::CANCEL]);
    }

    public function capturesThePointer(): bool
    {
        return $this->drag !== null;
    }

    private static function keys(): Keybindings
    {
        return new Keybindings([
            Actions::CURSOR_LEFT => ['left', 'h'],
            Actions::CURSOR_RIGHT => ['right', 'l'],
            Actions::CURSOR_UP => ['up', 'k'],
            Actions::CURSOR_DOWN => ['down', 'j'],
            Actions::CANCEL => ['esc'],
        ]);
    }

    private function columnAt(Rect $area, int $x, int $y): ?int
    {
        foreach ($this->layout($area) as $index => $layout) {
            if ($layout->frame->contains($x, $y)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return list<ColumnLayout>
     */
    private function layout(Rect $area): array
    {
        $frames = $area->split(Direction::Horizontal, count($this->columns));
        $layouts = [];

        foreach ($this->columns as $index => $column) {
            $frame = $frames[$index];
            $content = $frame->inset(Spacing::only(top: 2, right: 2, bottom: 1, left: 2));
            $visibleCount = intdiv($content->height, self::CARD_HEIGHT);
            $anchor = $index === $this->cursorColumn ? $this->cursorTask : 0;
            $firstVisible = max(0, min(count($column->tasks) - $visibleCount, $anchor - intdiv($visibleCount - 1, 2)));

            $cards = [];
            foreach (array_keys($column->tasks) as $taskIndex) {
                $cards[] = new Rect($content->x, $content->y + ($taskIndex - $firstVisible) * self::CARD_HEIGHT, $content->width, self::CARD_HEIGHT);
            }
            $layouts[] = new ColumnLayout($frame, $content, $cards, $firstVisible, $visibleCount);
        }

        return $layouts;
    }

    private function drawDraggedCard(Frame $frame, Rect $area, RenderContext $context): void
    {
        $task = $this->drag === null ? null : $this->columns[$this->drag->column]->tasks[$this->drag->task] ?? null;
        if ($this->drag === null || $task === null) {
            return;
        }
        $width = min(36, max(16, $area->width - 4));
        $x = max($area->x, min($this->drag->x - intdiv($width, 2), $area->right() - $width));
        $y = max($area->y, min($this->drag->y - 1, $area->bottom() - self::CARD_HEIGHT));

        $context->draw(
            Card::new($task->title, $task->description)
                ->withBorderStyle($context->theme->accent->bold())
                ->withTitleStyle($context->theme->accent->bold())
                ->withContentStyle($context->theme->base),
            $frame,
            new Rect($x, $y, $width, self::CARD_HEIGHT),
        );
    }

    private function afterKey(KeyMsg $message): self
    {
        $keys = self::keys();

        return match (true) {
            $this->drag !== null => $keys->matches($message, Actions::CANCEL) ? $this->cloneWith(drag: null) : $this,
            $keys->matches($message, Actions::CURSOR_LEFT) => $this->withCursorAt($this->cursorColumn - 1, $this->cursorTask),
            $keys->matches($message, Actions::CURSOR_RIGHT) => $this->withCursorAt($this->cursorColumn + 1, $this->cursorTask),
            $keys->matches($message, Actions::CURSOR_UP) => $this->withCursorAt($this->cursorColumn, $this->cursorTask - 1),
            $keys->matches($message, Actions::CURSOR_DOWN) => $this->withCursorAt($this->cursorColumn, $this->cursorTask + 1),
            default => $this,
        };
    }

    private function withCursorAt(int $column, int $task): self
    {
        $column = max(0, min(count($this->columns) - 1, $column));
        $task = max(0, min(count($this->columns[$column]->tasks) - 1, $task));

        return $this->cloneWith(cursorColumn: $column, cursorTask: $task);
    }

    private function afterMouse(MouseMsg $message, Rect $area): self
    {
        return match (true) {
            Gesture::Click->matches($message) => $this->pressedAt($message, $area),
            Gesture::Drag->matches($message) && $this->drag !== null => $this->cloneWith(drag: $this->drag->movedTo($message->x, $message->y)),
            $message->action === MouseAction::Release && $this->drag !== null => $this->droppedAt($message, $area),
            $message->action === MouseAction::WheelUp => $this->wheeledAt($message, $area, -$message->wheelSteps()),
            $message->action === MouseAction::WheelDown => $this->wheeledAt($message, $area, $message->wheelSteps()),
            default => $this,
        };
    }

    private function pressedAt(MouseMsg $message, Rect $area): self
    {
        $column = $this->columnAt($area, $message->x, $message->y);
        if ($column === null) {
            return $this;
        }
        $task = $this->layout($area)[$column]->cardAt($message->x, $message->y);
        if ($task === null) {
            return $this->withCursorAt($column, 0);
        }

        return $this->withCursorAt($column, $task)->cloneWith(drag: new Drag($column, $task, $message->x, $message->y));
    }

    private function droppedAt(MouseMsg $message, Rect $area): self
    {
        $target = $this->columnAt($area, $message->x, $message->y);
        if ($this->drag === null || $target === null || $target === $this->drag->column) {
            return $this->cloneWith(drag: null);
        }

        return $this->withTaskMoved($this->drag->column, $this->drag->task, $target);
    }

    private function withTaskMoved(int $from, int $task, int $to): self
    {
        $moved = $this->columns[$from]->tasks[$task] ?? null;
        if ($moved === null) {
            return $this->cloneWith(drag: null);
        }
        $columns = $this->columns;
        $columns[$from] = $columns[$from]->withoutTaskAt($task);
        $columns[$to] = $columns[$to]->withAddedTask($moved);

        return $this->cloneWith(columns: $columns, cursorColumn: $to, cursorTask: count($columns[$to]->tasks) - 1, drag: null);
    }

    private function wheeledAt(MouseMsg $message, Rect $area, int $steps): self
    {
        $column = $this->columnAt($area, $message->x, $message->y);

        return $column === null ? $this : $this->withCursorAt($column, $this->cursorTask + $steps);
    }
}

final readonly class Kanban extends App
{
    public function __construct(private Board $board)
    {
    }

    public static function new(): self
    {
        return new self(Board::of([
            new Column('To Do', [
                new Task('Make Kanban board', 'But in the terminal?'),
                new Task('Eat pizza', 'Whole pie.'),
                new Task('Fix the tests', 'Mutation score to 100%'),
                new Task('Write docs', 'A README and a tutorial.'),
                new Task('Refactor', 'And clean up the mess.'),
                new Task('Ship it', 'Tag a release.'),
            ]),
            new Column('In Progress', [
                new Task('Drag and drop', 'With real mouse support'),
                new Task('Get milk', 'Whole, not skim.'),
            ]),
            new Column('Done', [
                new Task('Wait patiently', 'For the next release.'),
            ]),
        ]));
    }

    public function view(): Widget
    {
        return Layout::column(
            $this->bind('board')
                ->withAction('advance', ['enter'], static fn (self $app): self => $app->cloneWith(board: $app->board->withCursorTaskAdvanced()), 'move on')
                ->withAction('add', ['a'], static fn (self $app): self => $app->cloneWith(board: $app->board->withAddedTask(new Task('New card', 'Drag me or press Enter to advance.'))), 'add')
                ->withAction('delete', ['d'], static fn (self $app): self => $app->cloneWith(board: $app->board->withoutCursorTask()), 'delete'),
            Footer::new()->withLeft(FocusHints::new()),
        );
    }

    protected function actions(): AppActions
    {
        return parent::actions()->withQuitKeys('q', 'ctrl+q', 'ctrl+c');
    }
}

Program::new(Kanban::new())->withScreenMode(ScreenMode::alternate(mouse: true))->start();
