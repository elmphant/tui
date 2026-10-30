<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Api;

use BackedEnum;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Widget\Choice\Checkbox;
use Elmphant\Tui\Widget\Choice\CheckboxGroup;
use Elmphant\Tui\Widget\Choice\Radio;
use Elmphant\Tui\Widget\Choice\Select;
use Elmphant\Tui\Widget\Feedback\ProgressBar;
use Elmphant\Tui\Widget\Field\TextInput;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Filter\FilterableStaticTable;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Prose\Paragraph;
use Elmphant\Tui\Widget\Table\StaticTable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use UnitEnum;

/**
 * Set scalar fields away from defaults before testing builders, so omitted constructor arguments show up as resets.
 * Exclude intentional field coupling and rebuilt objects from this check.
 */
final class BuilderLawTest extends TestCase
{
    /**
     * Builders may intentionally reset these fields to defaults; their values alone cannot distinguish that from a dropped argument.
     *
     * @var array<string, list<string>>
     */
    private const DELIBERATE = [
        // Moving the cursor resumes following it; withScrollOffset() stops.
        'ListView::withCursor' => ['followCursor'],
    ];

    #[Test]
    #[DataProvider('builders')]
    public function aBuilderDoesNotDropAFieldItIsNotAbout(object $widget): void
    {
        $defaults = self::scalarFields($widget);
        $loaded = self::loaded($widget);
        $carried = self::scalarFields($loaded);
        $drops = [];

        foreach (self::synthesisableBuilders($loaded) as [$method, $argument]) {
            $after = self::scalarFields($method->invoke($loaded, $argument));

            $exempt = self::DELIBERATE[(new ReflectionClass($widget))->getShortName() . '::' . $method->getName()] ?? [];

            foreach ($carried as $field => $value) {
                if (self::isAbout($method->getName(), $field) || in_array($field, $exempt, true) || $value === ($defaults[$field] ?? null)) {
                    continue;
                }

                if (($after[$field] ?? null) === ($defaults[$field] ?? null)) {
                    $drops[] = sprintf(
                        '%s::%s() dropped $%s back to its default',
                        (new ReflectionClass($widget))->getShortName(),
                        $method->getName(),
                        $field,
                    );
                }
            }
        }

        sort($drops);

        self::assertSame([], $drops, "A builder must carry the fields it is not about:\n" . implode("\n", $drops));
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function builders(): iterable
    {
        yield 'FilterableStaticTable' => [FilterableStaticTable::new(['A', 'B'], [['a', 'b'], ['c', 'd']])];
        yield 'StaticTable' => [StaticTable::new(['A'], [['a']])];
        yield 'FilterableList' => [FilterableList::new([])];
        yield 'ListView' => [ListView::fromStrings(['a', 'b'])];
        yield 'Select' => [Select::new(['a', 'b'])];
        yield 'Radio' => [Radio::new(['a', 'b'])];
        yield 'TextInput' => [TextInput::new()->withPlaceholder('text')];
        yield 'Checkbox' => [Checkbox::new('label')];
        yield 'CheckboxGroup' => [CheckboxGroup::new(['a', 'b'])];
        yield 'ProgressBar' => [ProgressBar::new()];
        yield 'Paragraph' => [Paragraph::fromText('text')];
        yield 'Constraint' => [Constraint::flex()];
    }

    /**
     * The widget with every synthesisable builder applied, so its fields sit
     * away from the defaults a dropped argument would fall back to.
     */
    private static function loaded(object $widget): object
    {
        foreach (self::synthesisableBuilders($widget) as [$method, $argument]) {
            $widget = $method->invoke($widget, $argument);
        }

        return $widget;
    }

    private static function isAbout(string $method, string $field): bool
    {
        return str_starts_with($field, lcfirst(substr($method, 4)));
    }

    /**
     * @return list<array{ReflectionMethod, mixed}>
     */
    private static function synthesisableBuilders(object $widget): array
    {
        $builders = [];

        foreach ((new ReflectionClass($widget))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'with') || $method->isStatic() || $method->getNumberOfParameters() !== 1) {
                continue;
            }

            $sample = self::sampleFor($method);

            if ($sample !== null) {
                $builders[] = [$method, $sample[0]];
            }
        }

        return $builders;
    }

    /**
     * @return array{mixed}|null
     */
    private static function sampleFor(ReflectionMethod $method): ?array
    {
        $type = $method->getParameters()[0]->getType();

        if (!$type instanceof ReflectionNamedType) {
            return null;
        }

        $sample = match ($type->getName()) {
            'int' => 1,
            'string' => 'sample',
            'bool' => true,
            'float' => 0.5,
            default => self::enumCase($type->getName()),
        };

        return $sample === null ? null : [$sample];
    }

    private static function enumCase(string $type): ?UnitEnum
    {
        if (!enum_exists($type)) {
            return null;
        }

        $cases = (new ReflectionEnum($type))->getCases();

        return $cases === [] ? null : $cases[array_key_last($cases)]->getValue();
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function scalarFields(object $widget): array
    {
        $fields = [];

        foreach ((new ReflectionClass($widget))->getProperties() as $property) {
            if ($property->isStatic() || !$property->isInitialized($widget)) {
                continue;
            }

            $value = $property->getValue($widget);

            if (is_scalar($value) || $value === null || $value instanceof BackedEnum) {
                $fields[$property->getName()] = $value instanceof BackedEnum ? $value->value : $value;
            }
        }

        return $fields;
    }
}
