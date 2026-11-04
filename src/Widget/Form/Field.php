<?php

declare(strict_types=1);

namespace Elmphant\Tui\Widget\Form;

use Closure;
use Elmphant\Tui\Support\Cloneable;
use Elmphant\Tui\Widget\Choice\Checkbox;
use Elmphant\Tui\Widget\Field\EchoMode;
use Elmphant\Tui\Widget\Field\InputDecoration;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Focusable;
use Elmphant\Tui\Widget\HoldsAValue;

final readonly class Field
{
    use Cloneable;

    public const TEXT_WIDTH = 48;

    /**
     * @param list<Closure(string|bool|list<string>):?string> $rules
     */
    private function __construct(
        public string $name,
        public string $label,
        public string $description,
        public Focusable&HoldsAValue $widget,
        public bool $required = false,
        public bool $labelledByItsWidget = false,
        private array $rules = [],
    ) {
    }

    public static function text(string $name, string $label, string $description = ''): self
    {
        return new self($name, $label, $description, self::textInput());
    }

    public static function secret(string $name, string $label, string $description = ''): self
    {
        return new self($name, $label, $description, self::textInput()->withEchoMode(EchoMode::Password));
    }

    /**
     * @param array<array-key, string> $options value => label
     */
    public static function choice(string $name, string $label, array $options, string $description = ''): self
    {
        return new self($name, $label, $description, ChoiceField::new($options));
    }

    public static function flag(string $name, string $label, string $description = ''): self
    {
        return new self($name, $label, $description, Checkbox::new($label), labelledByItsWidget: true);
    }

    public static function of(string $name, string $label, Focusable&HoldsAValue $widget, string $description = ''): self
    {
        return new self($name, $label, $description, $widget);
    }

    public function withRequired(bool $required = true): self
    {
        return $this->cloneWith(required: $required);
    }

    /**
     * Return an error message or null. withRequired() handles empty values.
     *
     * @param Closure(string|bool|list<string>):?string $rule
     */
    public function withRule(Closure $rule): self
    {
        return $this->cloneWith(rules: [...$this->rules, $rule]);
    }

    public function withWidget(Focusable&HoldsAValue $widget): self
    {
        return $this->cloneWith(widget: $widget);
    }

    public function isEmpty(): bool
    {
        $value = $this->widget->value();

        return $value === null || self::isBlank($value);
    }

    public function problem(): ?string
    {
        $value = $this->widget->value();
        if ($value === null || self::isBlank($value)) {
            return $this->required ? $this->label . ' is required' : null;
        }

        foreach ($this->rules as $rule) {
            $problem = $rule($value);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    private static function textInput(): TextInput
    {
        return TextInput::new()->withDecoration(InputDecoration::Underline)->withWidth(self::TEXT_WIDTH);
    }

    /**
     * @param string|bool|list<string> $value
     */
    private static function isBlank(string|bool|array $value): bool
    {
        return $value === false || $value === [] || (\is_string($value) && trim($value) === '');
    }
}
