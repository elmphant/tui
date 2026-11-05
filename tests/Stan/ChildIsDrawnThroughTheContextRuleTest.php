<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan;

use Elmphant\Tui\Stan\ChildIsDrawnThroughTheContextRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @extends RuleTestCase<ChildIsDrawnThroughTheContextRule>
 */
final class ChildIsDrawnThroughTheContextRuleTest extends RuleTestCase
{
    #[Test]
    public function onlyAWidgetCallingRenderOnAnotherWidgetIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/ChildDrawingWidget.php'],
            [
                [
                    'ChildDrawingWidget::render() calls render() on another widget. Draw it with $context->draw(), or $context->drawChild() for a child the keyboard can reach, so every child is drawn in one place.',
                    30,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new ChildIsDrawnThroughTheContextRule();
    }
}
