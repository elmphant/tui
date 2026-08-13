<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan;

use Elmphant\Tui\Layout\Constraint;
use Elmphant\Tui\Widget\Widget;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * A Constraint describes one axis and does not say which, so a widget handing
 * one out asks its caller to guess. Widgets answer with a Sizing; a per-axis
 * value belongs to whatever object owns that axis.
 *
 * @implements Rule<InClassMethodNode>
 */
final class WidgetDoesNotPublishAnAxisPolicyRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (!$class->implementsInterface(Widget::class)) {
            return [];
        }

        $method = $node->getMethodReflection();

        if ($method->isPrivate()) {
            return [];
        }

        $returned = $method->getOnlyVariant()->getReturnType();

        if (!(new ObjectType(Constraint::class))->isSuperTypeOf($returned)->yes()) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s::%s() hands out a Constraint, which describes one axis without saying which. A widget answers with a Sizing; per-axis values belong to the object that owns the axis.',
                $class->getNativeReflection()->getShortName(),
                $method->getName(),
            ))
                ->identifier('elmphant.widget.publishedAxisPolicy')
                ->build(),
        ];
    }
}
