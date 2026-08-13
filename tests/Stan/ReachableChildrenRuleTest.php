<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @extends RuleTestCase<ReachableChildrenRule>
 */
final class ReachableChildrenRuleTest extends RuleTestCase
{
    #[Test]
    public function anOptionalChildIsStillAChild(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/OptionalChildWidget.php'],
            [
                [
                    'OptionalChildWidget holds a Widget in $slot but does not implement WidgetContainer, so anything bound inside it is invisible to discovery and cannot take focus.',
                    16,
                ],
            ],
        );
    }

    #[Test]
    public function aWidgetHoldingAWidgetWithoutTheInterfaceReportsTheFieldItHides(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/OpaqueWidget.php'],
            [
                [
                    'OpaqueWidget holds a Widget in $content but does not implement WidgetContainer, so anything bound inside it is invisible to discovery and cannot take focus.',
                    16,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new ReachableChildrenRule();
    }
}
