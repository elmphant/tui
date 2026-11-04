<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Form;

use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\ComponentId;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Input\Actions;
use Elmphant\Tui\Input\BindsForTheMoment;
use Elmphant\Tui\Input\Keybindings;
use Elmphant\Tui\Input\KeybindingsAware;
use Elmphant\Tui\Input\KeybindingsAwareBehavior;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Layout\Sizing;
use Elmphant\Tui\Layout\Spacing;
use Elmphant\Tui\Message\KeyMsg;
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
use Elmphant\Tui\Widget\Interactive;
use Elmphant\Tui\Widget\MeasureHeight;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Structure\Panel;
use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\Wrap;
use LogicException;

/**
 * Derive values from the fields; each field has label, input and error children, followed by a pinned submit button.
 */
final readonly class Form implements BindsForTheMoment, ClipsItsChildren, FollowsTheKeyboard, GraftsItsChildren, KeybindingsAware, MeasureHeight, Navigates
{
    use Cloneable;
    use KeybindingsAwareBehavior;

    private const CHILDREN_PER_FIELD = 3;
    private const WIDGET_OF_FIELD = 1;

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
        private ?Message $cancel = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
    ) {
    }

    /**
     * Empty on purpose: momentaryKeybindings() binds a key only while the form uses it.
     */
    public static function defaultKeybindings(): Keybindings
    {
        return new Keybindings([Actions::CANCEL => [], Actions::NEXT_FIELD => []]);
    }

    public function momentaryKeybindings(): Keybindings
    {
        return new Keybindings([
            ...($this->cancel === null ? [] : [Actions::CANCEL => ['esc']]),
            ...($this->keyboardIn === null ? [] : [Actions::NEXT_FIELD => ['enter']]),
        ]);
    }

    public static function new(string $title = ''): self
    {
        return new self(Panel::new($title));
    }

    public function withField(Field $field): self
    {
        $fields = $this->fields;
        $at = $this->indexOf($field->name);
        $fields[$at ?? \count($fields)] = $field;

        return $this->cloneWith(fields: array_values($fields));
    }

    public function withIntro(?Widget $intro): self
    {
        if ($intro instanceof Interactive) {
            throw new LogicException(sprintf('A form\'s intro is prose; put %s in a Field instead', $intro::class));
        }

        return $this->cloneWith(intro: $intro);
    }

    public function withSubmit(string $label, Message $message): self
    {
        return $this->cloneWith(submit: Button::new($label, $message)->withDisabled($this->disabledBecause !== null));
    }

    public function withFoot(string $foot): self
    {
        return $this->cloneWith(foot: $foot);
    }

    public function withSubmitDisabled(?string $because): self
    {
        return $this->cloneWith(submit: $this->submit?->withDisabled($because !== null), disabledBecause: $because);
    }

    public function withCancel(?Message $message): self
    {
        return $this->cloneWith(cancel: $message);
    }

    public function withErrorsShown(ShowErrors $shown): self
    {
        return $this->cloneWith(errorsShown: $shown);
    }

    public function withSubmitAttempted(): self
    {
        return $this->cloneWith(attempted: true);
    }

    public function field(string $name): ?Field
    {
        $at = $this->indexOf($name);

        return $at === null ? null : $this->fields[$at];
    }

    /**
     * @param array<array-key, string|bool|list<string>|null> $values PHP turns a name such as '0' into an integer key
     */
    public function withValues(array $values): self
    {
        $form = $this;
        foreach ($values as $name => $value) {
            $field = $this->field((string)$name) ?? throw new LogicException(sprintf('The form has no field named "%s"', $name));
            $form = $form->withField($field->withWidget($field->widget->withValue($value)));
        }

        return $form;
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

    public function stopOf(string $name, ComponentId|string $form): ComponentId
    {
        $at = $this->indexOf($name) ?? throw new LogicException(sprintf('The form has no field named "%s"', $name));

        return ComponentId::named($form . '/' . $this->widgetChild($at));
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function measureHeight(int $width): int
    {
        return $this->drawn()->measureHeight($width);
    }

    public function leastHeight(int $width): int
    {
        return $this->drawn(gapAboveTheFoot: 0)->withChildren([Text::new(' ')])->measureHeight($width);
    }

    public function navigation(): Navigation
    {
        return Navigation::Never;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if ($this->cancel !== null && $message instanceof KeyMsg && $this->effectiveKeybindings()->matches($message, Actions::CANCEL)) {
            return new UpdateResult($this, Cmd::message($this->cancel));
        }

        if ($this->keyboardIn !== null && $message instanceof KeyMsg && $this->effectiveKeybindings()->matches($message, Actions::NEXT_FIELD)) {
            return new UpdateResult($this, $this->enteredIn($this->keyboardIn));
        }

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
     * Leaving a field enables its AfterLeaving errors; null means focus left the form.
     */
    public function withKeyboardAt(?int $child): static
    {
        $at = $child === null ? null : $this->fieldOf($child);
        $in = $at === null ? null : $this->fields[$at]->name;
        $left = $this->keyboardIn !== null && $this->keyboardIn !== $in && !\in_array($this->keyboardIn, $this->left, true)
            ? [...$this->left, $this->keyboardIn]
            : $this->left;

        $followed = $child === $this->submitChild() && $this->fields !== [] ? \count($this->fields) - 1 : $at;

        return $this->cloneWith(
            pane: $followed === null
                ? $this->pane->withKeyboardAt(null)
                : $this->pane->withFollowChild($this->widgetChild($followed) - self::WIDGET_OF_FIELD, self::WIDGET_OF_FIELD + 1),
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

    private function enteredIn(string $field): ?Cmd
    {
        $last = $this->fields[\count($this->fields) - 1]->name;

        return $field === $last && $this->submit !== null ? $this->submit->press() : Cmd::focusNext();
    }

    private function drawn(int $gapAboveTheFoot = 1): Panel
    {
        $pane = $this->pane
            ->withChildren($this->rows())
            ->withPadding(Spacing::only(top: 1, right: 1, bottom: $this->submit === null ? 1 : 0, left: 1 + self::GUTTER));

        return $this->submit === null
            ? $pane
            : $pane->withFooter(new FormFoot($this->foot, $this->buttonWidth()), ruled: true, caption: $this->caption(), gap: $gapAboveTheFoot);
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
        $mark = match (true) {
            !$field->required => '',
            $name === '' => '*required',
            default => ' *required',
        };
        $spacer = $name . $mark === '' || $field->description === '' ? '' : '  ';

        return Paragraph::fromLines([new Line([
            Span::token($name, StyleToken::Base)->bold(),
            Span::token($mark, StyleToken::Accent),
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
