<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @extends RuleTestCase<WidgetDoesNotPublishAnAxisPolicyRule>
 */
final class WidgetDoesNotPublishAnAxisPolicyRuleTest extends RuleTestCase
{
    #[Test]
    public function aWidgetHandingOutOneAxisIsReportedPerMethod(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/AxisPolicyPublisher.php'],
            [
                [
                    'AxisPolicyPublisher::constraint() hands out a Constraint, which describes one axis without saying which. A widget answers with a Sizing; per-axis values belong to the object that owns the axis.',
                    16,
                ],
                [
                    'AxisPolicyPublisher::columnWidth() hands out a Constraint, which describes one axis without saying which. A widget answers with a Sizing; per-axis values belong to the object that owns the axis.',
                    21,
                ],
            ],
        );
    }

    #[Test]
    public function theObjectThatOwnsAnAxisMayPublishIt(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/AxisOwningValue.php'], []);
    }

    protected function getRule(): Rule
    {
        return new WidgetDoesNotPublishAnAxisPolicyRule();
    }
}
