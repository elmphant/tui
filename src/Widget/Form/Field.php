<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Form;

use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Choice\Checkbox;
use Elmphant\Tui\Widget\Field\EchoMode;
use Elmphant\Tui\Widget\Field\InputDecoration;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\HoldsAValue;

/**
 * One named field of a Form: what it is called, what it is for, and the widget
 * the reader fills it in through. What it holds is asked of that widget.
 */
final readonly class Field
{
    use Cloneable;

    private function __construct(
        public string $name,
        public string $label,
        public string $description,
        public Focusable&HoldsAValue $widget,
        public bool $required = false,
        public bool $labelledByItsWidget = false,
    ) {
    }

    public static function text(string $name, string $label, string $description = ''): self
    {
        return new self($name, $label, $description, TextInput::new()->withDecoration(InputDecoration::Underline));
    }

    /**
     * @param array<array-key, string> $options value => label
     */
    public static function choice(string $name, string $label, array $options, string $description = ''): self
    {
        return new self($name, $label, $description, ChoiceField::new($options));
    }

    /**
     * On or off. The box carries the label, so the row above it carries only the
     * description.
     */
    public static function flag(string $name, string $label, string $description = ''): self
    {
        return new self($name, $label, $description, Checkbox::new($label), labelledByItsWidget: true);
    }

    public static function of(string $name, string $label, Focusable&HoldsAValue $widget, string $description = ''): self
    {
        return new self($name, $label, $description, $widget);
    }

    public function required(bool $required = true): self
    {
        return $this->cloneWith(required: $required);
    }

    /**
     * Typed in as dots: only a text field has anything to hide.
     */
    public function secret(): self
    {
        return $this->widget instanceof TextInput
            ? $this->withWidget($this->widget->withEchoMode(EchoMode::Password))
            : $this;
    }

    public function withWidget(Focusable&HoldsAValue $widget): self
    {
        return $this->cloneWith(widget: $widget);
    }

    public function isEmpty(): bool
    {
        $value = $this->widget->value();

        return $value === null || $value === false || $value === [] || (\is_string($value) && trim($value) === '');
    }

    public function problem(): ?string
    {
        return $this->required && $this->isEmpty() ? $this->label . ' is required' : null;
    }
}
