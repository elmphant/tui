<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Filter;

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
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Field\InputDecoration;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focus\DrawsFocus;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\Table\StaticTable;

final readonly class FilterableStaticTable implements Focusable, MeasureHeight, KeybindingsAware, PublishesHints, TakesText, BindsForTheMoment
{
    use Cloneable;
    use DrawsFocus;
    use KeybindingsAwareBehavior;
    use SearchMode;

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    private function __construct(
        private array $headers,
        private array $rows,
        private TextInput $field,
        private ?Matcher $matcher = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $searching = false,
    ) {
    }

    public static function defaultKeybindings(): Keybindings
    {
        return FilterBar::keybindings()->append(TextInput::defaultKeybindings());
    }

    public function hints(): array
    {
        return $this->searchHints(
            $this->query() === '' ? [] : ['clear filter' => $this->searchIsBound() ? Actions::CANCEL : [Actions::CLEAR_LINE, Actions::CANCEL]],
        );
    }

    /**
     * Keep the host's existing field so rebuilding does not reset the caret to the end.
     */
    public function withField(TextInput $field): self
    {
        return $this->cloneWith(field: $field);
    }

    public function field(): TextInput
    {
        return $this->field;
    }

    public function query(): string
    {
        return $this->field->value();
    }

    /**
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public static function new(array $headers, array $rows): self
    {
        return new self($headers, $rows, FilterBar::field(''));
    }

    public function withQuery(string $query): self
    {
        return $query === $this->query() ? $this : $this->cloneWith(field: $this->field->withValue($query));
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$message instanceof KeyMsg && !$message instanceof PasteMsg) {
            return new UpdateResult($this);
        }

        $toggled = $message instanceof KeyMsg ? $this->searchToggledBy($message) : null;
        if ($toggled !== null) {
            return new UpdateResult($toggled);
        }

        if ($message instanceof KeyMsg && $this->effectiveKeybindings()->matches($message, Actions::CANCEL)) {
            return new UpdateResult($this->withQuery(''));
        }

        if ($message instanceof KeyMsg && !$this->takesText()) {
            return new UpdateResult($this);
        }

        $result = (new ChildDispatcher($this->globalKeybindings))->dispatchOne($this->field(), $message, $this->fieldArea($area));

        return new UpdateResult($this->withField($result->child), $result->command);
    }

    public function withMatcher(Matcher $matcher): self
    {
        return $this->cloneWith(matcher: $matcher);
    }

    public function summary(): string
    {
        return $this->summaryOf($this->matchingRows());
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::min(FilterBar::HEIGHT + 1));
    }

    public function measureHeight(int $width): int
    {
        return FilterBar::HEIGHT + $this->table()->measureHeight($width);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        // Share one filtered result so the bar's count always matches the displayed table.
        $matching = $this->matchingRows();
        $table = $this->tableOf($matching);
        $clip = $frame->clip();

        if ($area->y >= $clip->y && $area->y < $clip->bottom()) {
            $this->renderBar(
                $frame,
                FilterBar::barArea($area, $this->barWidth($area->width, $table)),
                $context,
                $this->summaryOf($matching),
            );
        }

        $table->render($frame, FilterBar::bodyArea($area), $context);
    }

    /**
     * @return list<list<string>>
     */
    public function matchingRows(): array
    {
        if (trim($this->query()) === '') {
            return $this->rows;
        }

        $searched = array_map(static fn (array $row): string => implode(' ', $row), $this->rows);

        return array_map(
            fn (int $index): array => $this->rows[$index],
            FilterBar::matching($this->matcher, $this->query(), $searched),
        );
    }

    private function fieldFocused(bool $focused): TextInput
    {
        return $this->field->withDecoration($focused ? InputDecoration::Underline : InputDecoration::None);
    }

    private function renderBar(Frame $frame, Rect $area, RenderContext $context, string $summary): void
    {
        $bar = fn () => FilterBar::render(
            $frame,
            $area,
            $context,
            $this->fieldFocused($this->drawsFocused($context)),
            $summary,
        );

        $this->fieldHoldsTheKeys($context) ? $bar() : $context->withoutFocus($bar);
    }

    /**
     * @param list<list<string>> $matching
     */
    private function summaryOf(array $matching): string
    {
        return count($matching) . ' of ' . count($this->rows);
    }

    /**
     * Match the table width, but leave enough room for the bar's controls.
     */
    private function fieldArea(Rect $area): Rect
    {
        return FilterBar::barArea($area, $this->barWidth($area->width, $this->table()));
    }

    private function barWidth(int $available, StaticTable $table): int
    {
        return max(FilterBar::MIN_WIDTH, min($available, $table->drawnWidth($available)));
    }

    private function table(): StaticTable
    {
        return $this->tableOf($this->matchingRows());
    }

    /**
     * @param list<list<string>> $matching
     */
    private function tableOf(array $matching): StaticTable
    {
        return StaticTable::new($this->headers, $matching)
            ->withWidthsFrom($this->rows)
            ->withMinWidth(FilterBar::MIN_WIDTH);
    }
}
