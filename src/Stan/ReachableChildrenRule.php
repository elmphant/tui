<?php

declare(strict_types=1);

namespace Elmphant\Tui\Stan;

use Elmphant\Tui\Widget\Widget;
use Elmphant\Tui\Widget\WidgetContainer;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClassPropertyNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Discovery and focus reach a child through WidgetContainer. A widget that
 * renders one it never exposes leaves anything bound inside it unreachable.
 *
 * @implements Rule<ClassPropertyNode>
 */
final class ReachableChildrenRule implements Rule
{
    /**
     * @param list<class-string> $allowList widgets whose child is reached some other way
     */
    public function __construct(private array $allowList = [])
    {
    }

    public function getNodeType(): string
    {
        return ClassPropertyNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (!$class->implementsInterface(Widget::class) || $class->implementsInterface(WidgetContainer::class)) {
            return [];
        }

        if (in_array($class->getName(), $this->allowList, true)) {
            return [];
        }

        $declared = $node->getPhpDocType() ?? $node->getNativeType();

        if ($declared === null || !self::holdsWidget($declared)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s holds a Widget in $%s but does not implement WidgetContainer, so anything bound inside it is invisible to discovery and cannot take focus.',
                $class->getNativeReflection()->getShortName(),
                $node->getName(),
            ))
                ->identifier('elmphant.widget.unreachableChild')
                ->build(),
        ];
    }

    private static function holdsWidget(Type $type): bool
    {
        $type = TypeCombinator::removeNull($type);

        foreach ($type->getObjectClassNames() as $held) {
            if ((new ObjectType(Widget::class))->isSuperTypeOf(new ObjectType($held))->yes()) {
                return true;
            }
        }

        return $type->isIterable()->yes() && self::holdsWidget($type->getIterableValueType());
    }
}
