<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Api;

use BackedEnum;
use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Widget\Checkbox;
use Elmphant\Tui\Widget\CheckboxGroup;
use Elmphant\Tui\Widget\Feedback\ProgressBar;
use Elmphant\Tui\Widget\Filter\FilterableList;
use Elmphant\Tui\Widget\Filter\FilterableTable;
use Elmphant\Tui\Widget\Listing\ListView;
use Elmphant\Tui\Widget\Paragraph;
use Elmphant\Tui\Widget\Radio;
use Elmphant\Tui\Widget\Select;
use Elmphant\Tui\Widget\Table\StaticTable;
use Elmphant\Tui\Widget\TextInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use UnitEnum;

/**
 * A `withX()` builder that rebuilds its object positionally can drop a later
 * constructor argument, which resets that field to its default. The type still
 * matches, so nothing complains — `StaticTable::withOverflow()` lost `minWidth`
 * this way.
 *
 * Every builder is first applied to move fields off their defaults; a field
 * that falls back to its default afterwards was dropped, not set. Deliberate
 * coupling (a new value moving a cursor) is not a reset, so it does not report.
 *
 * Only scalar fields are compared: collaborators an immutable widget rebuilds on
 * purpose would differ without anything being lost.
 */
final class BuilderLawTest extends TestCase
{
    /**
     * Fields a builder sets on purpose, where the intended value is also the
     * default and so cannot be told from a drop by its value alone.
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
        yield 'FilterableTable' => [FilterableTable::new(['A', 'B'], [['a', 'b'], ['c', 'd']])];
        yield 'StaticTable' => [StaticTable::new(['A'], [['a']])];
        yield 'FilterableList' => [FilterableList::new([])];
        yield 'ListView' => [ListView::fromStrings(['a', 'b'])];
        yield 'Select' => [Select::new(['a', 'b'])];
        yield 'Radio' => [Radio::new(['a', 'b'])];
        yield 'TextInput' => [TextInput::new('text')];
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
