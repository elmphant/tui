<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Form;

use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Line;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\ClipsItsChildren;
use Elmphant\Tui\Widget\Field\Button;
use Elmphant\Tui\Widget\Focus\FollowsTheKeyboard;
use Elmphant\Tui\Widget\Focus\GraftsItsChildren;
use Elmphant\Tui\Widget\Focus\Navigates;
use Elmphant\Tui\Widget\Focus\Navigation;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\HoldsAValue;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\Wrap;
use LogicException;

/**
 * Named fields in a pane that scrolls, each a real widget the keyboard stops at,
 * with a submit button pinned to the foot. What the form holds is asked of the
 * fields, so it cannot fall behind what is on screen.
 *
 * Every field takes three children — its label, its widget, the line its problem
 * is told on — and the submit button comes after them, so a field's stop is
 * where its widget sits among them.
 */
final readonly class Form implements ClipsItsChildren, FollowsTheKeyboard, GraftsItsChildren, MeasureHeight, Navigates
{
    use Cloneable;

    private const CHILDREN_PER_FIELD = 3;
    private const WIDGET_OF_FIELD = 1;

    /**
     * The bar marking the field the keyboard is in, and the space after it.
     */
    private const GUTTER = 2;
    private const BAR = '▎';
    private const BLOCKED_CAPTION = 'fill in what is required';

    /**
     * @param list<Field>  $fields
     * @param list<string> $left   fields the keyboard has been in and left
     */
    private function __construct(
        private Panel $pane,
        private array $fields = [],
        private ?Button $submit = null,
        private string $foot = '',
        private bool $attempted = false,
        private ShowErrors $errorsShown = ShowErrors::AfterSubmit,
        private array $left = [],
        private ?string $keyboardIn = null,
        private ?Widget $intro = null,
        private ?string $disabledBecause = null,
    ) {
    }

    public static function new(string $title = ''): self
    {
        return new self(Panel::new($title));
    }

    /**
     * A field named like one the form holds takes its place.
     */
    public function withField(Field $field): self
    {
        $fields = $this->fields;
        $at = $this->indexOf($field->name);
        $fields[$at ?? \count($fields)] = $field;

        return $this->cloneWith(fields: array_values($fields));
    }

    /**
     * What the form is about, above its fields and scrolling with them.
     */
    public function withIntro(?Widget $intro): self
    {
        return $this->cloneWith(intro: $intro);
    }

    public function withSubmit(string $label, Message $message, string $foot = ''): self
    {
        return $this->cloneWith(submit: Button::new($label, $message)->withDisabled($this->disabledBecause !== null), foot: $foot);
    }

    /**
     * The line beside the submit button: what pressing it will do.
     */
    public function withFoot(string $foot): self
    {
        return $this->cloneWith(foot: $foot);
    }

    /**
     * A submit that cannot be pressed, and the reason, which the rule above the foot
     * gives in place of what is missing.
     */
    public function withSubmitDisabled(?string $because): self
    {
        return $this->cloneWith(submit: $this->submit?->withDisabled($because !== null), disabledBecause: $because);
    }

    public function withErrorsShown(ShowErrors $shown): self
    {
        return $this->cloneWith(errorsShown: $shown);
    }

    /**
     * The form after a submit was tried: from now on every field says what is
     * wrong with it.
     */
    public function submitted(): self
    {
        return $this->cloneWith(attempted: true);
    }

    public function field(string $name): ?Field
    {
        $at = $this->indexOf($name);

        return $at === null ? null : $this->fields[$at];
    }

    /**
     * @return array<string, string|bool|list<string>|null>
     */
    public function values(): array
    {
        $values = [];
        foreach ($this->fields as $field) {
            $values[$field->name] = $field->widget->value();
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    public function missing(): array
    {
        return array_values(array_map(
            static fn (Field $field): string => $field->name,
            array_filter($this->fields, static fn (Field $field): bool => $field->required && $field->isEmpty()),
        ));
    }

    /**
     * What is wrong with each field, whether or not it is shown yet.
     *
     * @return array<string, string>
     */
    public function problems(): array
    {
        $problems = [];
        foreach ($this->fields as $field) {
            $problem = $field->problem();
            if ($problem !== null) {
                $problems[$field->name] = $problem;
            }
        }

        return $problems;
    }

    /**
     * The stop a field's widget is, inside the component this form is bound as —
     * where a host sends the keyboard to fix what is missing.
     */
    public function stopOf(string $name, ComponentId $form): ComponentId
    {
        $at = $this->indexOf($name) ?? throw new LogicException(sprintf('The form has no field named "%s"', $name));

        return ComponentId::named($form->value . '/' . $this->widgetChild($at));
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function measureHeight(int $width): int
    {
        return $this->drawn()->measureHeight($width);
    }

    /**
     * The rows that still show the foot and one row of what scrolls above it.
     */
    public function leastHeight(int $width): int
    {
        return $this->drawn()->withChildren([Text::new(' ')])->measureHeight($width);
    }

    public function navigation(): Navigation
    {
        return Navigation::Never;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $drawn = $this->drawn();

        return $drawn->update($message, $area)
            ->mapModel(fn (Panel $pane): self => $pane === $drawn ? $this : $this->cloneWith(pane: $pane));
    }

    /**
     * @return list<Widget>
     */
    public function children(): iterable
    {
        return [...$this->drawn()->children(), ...($this->submit === null ? [] : [$this->submit])];
    }

    public function withChildAt(int $index, Widget $child): static
    {
        if ($index === $this->submitChild() && $child instanceof Button) {
            return $this->cloneWith(submit: $child);
        }

        $at = $this->fieldOf($index);
        if ($at === null || $index !== $this->widgetChild($at)) {
            return $this;
        }
        $field = $this->fields[$at];

        if (!$child instanceof Focusable || !$child instanceof HoldsAValue) {
            throw new LogicException(sprintf('A field of a form is written back as a widget holding a value, got %s', $child::class));
        }

        return $this->withField($field->withWidget($child));
    }

    /**
     * Where the keyboard is now; null when it left the form. The field it was in
     * before counts as left, which is when AfterLeaving starts telling its problem.
     */
    public function withKeyboardAt(?int $child): static
    {
        $at = $child === null ? null : $this->fieldOf($child);
        $in = $at === null ? null : $this->fields[$at]->name;
        $left = $this->keyboardIn !== null && $this->keyboardIn !== $in && !\in_array($this->keyboardIn, $this->left, true)
            ? [...$this->left, $this->keyboardIn]
            : $this->left;

        return $this->cloneWith(
            pane: $this->pane->withKeyboardAt($child !== null && $child < $this->submitChild() ? $child : null),
            keyboardIn: $in,
            left: $left,
        );
    }

    /**
     * @return list<array{0: Widget, 1: Rect}>
     */
    public function placedChildren(Rect $area): iterable
    {
        $button = $this->buttonRect($area);

        return [
            ...$this->drawn()->placedChildren($area),
            ...($this->submit === null || $button === null ? [] : [[$this->submit, $button]]),
        ];
    }

    public function viewport(Rect $area, Widget $child): Rect
    {
        return $child === $this->submit
            ? ($this->buttonRect($area) ?? new Rect($area->x, $area->y, 0, 0))
            : $this->drawn()->viewport($area, $child);
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        $pane = $this->drawn();
        $pane->render($frame, $area, $context);

        $button = $this->buttonRect($area);
        if ($this->submit !== null && $button !== null) {
            $submit = $this->submit;
            $context->withinChild($this->submitChild(), static fn () => $submit->render($frame, $button, $context));
        }

        $this->renderBar($frame, $area, $pane, $context);
    }

    private function drawn(): Panel
    {
        $pane = $this->pane
            ->withChildren($this->rows())
            ->withPadding(Spacing::only(top: 1, right: 1, bottom: $this->submit === null ? 1 : 0, left: 1 + self::GUTTER));

        return $this->submit === null
            ? $pane
            : $pane->withFooter(new FormFoot($this->foot, $this->buttonWidth()), ruled: true, caption: $this->caption(), gap: 1);
    }

    /**
     * @return list<Widget>
     */
    private function rows(): array
    {
        $rows = $this->intro === null ? [] : [$this->intro];
        foreach ($this->fields as $field) {
            $rows[] = self::labelOf($field);
            $rows[] = $field->widget;
            $rows[] = $this->noteOf($field);
        }

        return $rows;
    }

    private static function labelOf(Field $field): Widget
    {
        $name = $field->labelledByItsWidget ? '' : $field->label;
        $spacer = $name === '' || $field->description === '' ? '' : '  ';

        return Paragraph::fromLines([new Line([
            Span::token($name, StyleToken::Base)->bold(),
            Span::token($name !== '' && $field->required ? ' *required' : '', StyleToken::Accent),
            Span::token($spacer . $field->description, StyleToken::Dim),
        ])])->withWrap(Wrap::WordWrap);
    }

    private function noteOf(Field $field): Widget
    {
        $problem = $this->shows($field) ? $field->problem() : null;

        return $problem === null
            ? Text::new('')
            : Paragraph::fromLines([new Line([Span::token($problem, StyleToken::Error)]), new Line([])]);
    }

    private function shows(Field $field): bool
    {
        return $this->attempted
            || ($this->errorsShown === ShowErrors::AfterLeaving && \in_array($field->name, $this->left, true));
    }

    private function caption(): ?string
    {
        if ($this->disabledBecause !== null) {
            return $this->disabledBecause;
        }

        foreach ($this->fields as $field) {
            if ($this->shows($field) && $field->problem() !== null) {
                return self::BLOCKED_CAPTION;
            }
        }

        return null;
    }

    private function buttonRect(Rect $area): ?Rect
    {
        $foot = $this->drawn()->footerArea($area);
        $width = $this->buttonWidth();

        return $this->submit === null || $foot->height < FormFoot::BUTTON_HEIGHT || $foot->width < $width
            ? null
            : new Rect($foot->right() - $width, $foot->y, $width, FormFoot::BUTTON_HEIGHT);
    }

    private function buttonWidth(): int
    {
        return $this->submit?->sizing()->columns->intrinsic() ?? 0;
    }

    private function renderBar(Frame $frame, Rect $area, Panel $pane, RenderContext $context): void
    {
        $at = $this->fieldOf($context->focusedChildIndex() ?? -1);
        if ($at === null) {
            return;
        }

        $first = $this->widgetChild($at) - self::WIDGET_OF_FIELD;
        $placed = [...$pane->placedChildren($area)];
        $label = $placed[$first][1] ?? null;
        $widget = $placed[$first + self::WIDGET_OF_FIELD][1] ?? null;
        if ($label === null || $widget === null) {
            return;
        }

        $content = $pane->contentArea($area);
        $column = $content->x - self::GUTTER;
        for ($row = max($label->y, $content->y); $row < min($widget->bottom(), $content->bottom()); ++$row) {
            $frame->putStyled($column, $row, self::BAR, $context->theme->accent);
        }
    }

    private function submitChild(): int
    {
        return $this->firstFieldChild() + \count($this->fields) * self::CHILDREN_PER_FIELD;
    }

    private function widgetChild(int $field): int
    {
        return $this->firstFieldChild() + $field * self::CHILDREN_PER_FIELD + self::WIDGET_OF_FIELD;
    }

    private function fieldOf(int $child): ?int
    {
        $offset = $child - $this->firstFieldChild();

        return $offset < 0 || $child >= $this->submitChild() ? null : intdiv($offset, self::CHILDREN_PER_FIELD);
    }

    private function firstFieldChild(): int
    {
        return $this->intro === null ? 0 : 1;
    }

    private function indexOf(string $name): ?int
    {
        foreach ($this->fields as $at => $field) {
            if ($field->name === $name) {
                return $at;
            }
        }

        return null;
    }
}
