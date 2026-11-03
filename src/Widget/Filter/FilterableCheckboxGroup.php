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
use Elmphant\Tui\Message\MouseMsg;
use Elmphant\Tui\Message\PasteMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Search\Matcher;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Choice\CheckboxGroup;
use Elmphant\Tui\Widget\Choice\Options;
use Elmphant\Tui\Widget\Dispatch\ChildDispatcher;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Popup\DropdownContent;
use Elmphant\Tui\Widget\PublishesHints;
use Elmphant\Tui\Widget\ValueItCannotHold;

/**
 * Track checked options by value because filtering changes row indices.
 */
final readonly class FilterableCheckboxGroup implements DropdownContent, KeybindingsAware, PublishesHints, TakesText, BindsForTheMoment
{
    use Cloneable;
    use KeybindingsAwareBehavior;
    use SearchMode;

    private const OWN_ACTIONS = [Actions::CURSOR_UP, Actions::CURSOR_DOWN, Actions::TOGGLE];

    /**
     * @param list<string> $options
     * @param list<string> $checked
     */
    private function __construct(
        private array $options,
        private TextInput $field,
        private array $checked = [],
        private int $cursor = 0,
        private ?Matcher $matcher = null,
        private ?Keybindings $customKeybindings = null,
        private ?Keybindings $globalKeybindings = null,
        private bool $searching = false,
    ) {
    }

    public static function defaultKeybindings(): Keybindings
    {
        return CheckboxGroup::defaultKeybindings()->append(FilterBar::keybindings());
    }

    public function hints(): array
    {
        return $this->searchHints([
            'move' => [Actions::CURSOR_UP, Actions::CURSOR_DOWN],
            'toggle' => Actions::TOGGLE,
            ...($this->field->value() === '' ? [] : ['clear filter' => Actions::CANCEL]),
        ]);
    }

    public function withMatcher(Matcher $matcher): self
    {
        return $this->cloneWith(matcher: $matcher);
    }

    /**
     * @param list<string> $options
     */
    public static function new(array $options): self
    {
        Options::assertDistinct($options, 'FilterableCheckboxGroup');

        return new self($options, TextInput::new()->withPlaceholder(FilterBar::PLACEHOLDER));
    }

    /**
     * @return list<string>
     */
    public function value(): array
    {
        return $this->checkedValues();
    }

    public function withValue(string|bool|array|null $value): static
    {
        return \is_array($value) || $value === null
            ? $this->cloneWith(checked: array_values(array_intersect($this->options, $value ?? [])))
            : throw ValueItCannotHold::given($this, $value);
    }

    /**
     * @return list<string>
     */
    public function checkedValues(): array
    {
        return array_values(array_filter(
            $this->options,
            fn (string $option): bool => \in_array($option, $this->checked, true),
        ));
    }

    /**
     * Count selections across all rows; the host decides what an empty selection means.
     */
    public function caption(): string
    {
        return \count($this->checkedValues()) . ' of ' . \count($this->options);
    }

    public function hasChoice(): bool
    {
        return $this->checkedValues() !== [];
    }

    /**
     * Measure every option so filtering cannot change popup width on each keystroke.
     */
    public function popupWidth(): int
    {
        return CheckboxGroup::new($this->options)->popupWidth();
    }

    public function popupHeight(int $width): int
    {
        return FilterBar::HEIGHT + FilterBar::GAP_BELOW + \count($this->matching());
    }

    /**
     * Resolve clicks against the drawn options, which may differ from the current filtered rows.
     */
    public function optionAt(MouseMsg $message, Rect $area): ?string
    {
        return $this->boxes()->optionAt($message, $this->boxesArea($area));
    }

    public function hasOption(string $option): bool
    {
        return \in_array($option, $this->options, true);
    }

    public function activated(string $option): static
    {
        if (!$this->hasOption($option)) {
            return $this;
        }

        return $this->cloneWith(checked: \in_array($option, $this->checked, true)
            ? array_values(array_filter($this->checked, static fn (string $name): bool => $name !== $option))
            : [...$this->checked, $option]);
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        $dispatcher = new ChildDispatcher($this->globalKeybindings);

        // Route pointer input to the visible option rows even before they receive focus.
        if ($message instanceof MouseMsg) {
            return $this->dispatchedToTheBoxes($dispatcher, $message, $area);
        }

        if (!$message instanceof KeyMsg && !$message instanceof PasteMsg) {
            return new UpdateResult($this);
        }

        $toggled = $message instanceof KeyMsg ? $this->searchToggledBy($message) : null;
        if ($toggled !== null) {
            return new UpdateResult($toggled);
        }

        if ($message instanceof KeyMsg && $this->effectiveKeybindings()->matches($message, Actions::CANCEL)) {
            return new UpdateResult(
                $this->field->value() === '' ? $this : $this->cloneWith(field: $this->field->withValue(''), cursor: 0),
            );
        }

        if ($message instanceof KeyMsg && $this->isForTheBoxes($message)) {
            return $this->dispatchedToTheBoxes($dispatcher, $message, $area);
        }

        if ($message instanceof KeyMsg && !$this->takesText()) {
            return new UpdateResult($this);
        }

        $field = $dispatcher->dispatchOne($this->field, $message, $this->fieldArea($area))->child;

        return new UpdateResult($this->cloneWith(field: $field, cursor: 0));
    }

    public function sizing(): Sizing
    {
        return Sizing::of(Constraint::flex(), Constraint::flex());
    }

    public function render(Frame $frame, Rect $area, RenderContext $context): void
    {
        if ($area->width < 1 || $area->height < 1) {
            return;
        }

        $field = fn () => FilterBar::render($frame, $this->fieldArea($area), $context, $this->field);
        $this->fieldHoldsTheKeys($context) ? $field() : $context->withoutFocus($field);

        $body = $this->boxesArea($area);
        if ($body->height > 0) {
            $this->boxes()->render($frame, $body, $context);
        }
    }

    /**
     * @return UpdateResult<static>
     */
    private function dispatchedToTheBoxes(ChildDispatcher $dispatcher, Message $message, Rect $area): UpdateResult
    {
        $boxes = $dispatcher->dispatchOne($this->boxes(), $message, $this->boxesArea($area))->child;

        return new UpdateResult($this->cloneWith(checked: $this->checkedAfter($boxes), cursor: $boxes->cursor()));
    }

    private function isForTheBoxes(KeyMsg $message): bool
    {
        foreach (self::OWN_ACTIONS as $action) {
            if ($this->effectiveKeybindings()->matches($message, $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Derive visible boxes from the query and selected values so they cannot disagree.
     */
    private function boxes(): CheckboxGroup
    {
        $matching = $this->matching();

        return CheckboxGroup::new($matching)
            ->withChecked(array_values(array_filter(
                array_keys($matching),
                fn (int $index): bool => \in_array($matching[$index], $this->checked, true),
            )))
            ->withCursor($this->cursor);
    }

    /**
     * Preserve selections hidden by the query when applying visible checkbox changes.
     *
     * @return list<string>
     */
    private function checkedAfter(CheckboxGroup $boxes): array
    {
        return [...array_diff($this->checked, $this->matching()), ...$boxes->checkedValues()];
    }

    private function fieldArea(Rect $area): Rect
    {
        return FilterBar::barArea($area);
    }

    private function boxesArea(Rect $area): Rect
    {
        return FilterBar::bodyArea($area, FilterBar::GAP_BELOW);
    }

    /**
     * @return list<string>
     */
    private function matching(): array
    {
        return array_map(
            fn (int $index): string => $this->options[$index],
            FilterBar::matching($this->matcher, $this->field->value(), $this->options),
        );
    }
}
