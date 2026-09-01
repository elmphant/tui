<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan;

use Elmphant\Tui\Stan\FocusIsAskedWhileDrawingRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @extends RuleTestCase<FocusIsAskedWhileDrawingRule>
 */
final class FocusIsAskedWhileDrawingRuleTest extends RuleTestCase
{
    #[Test]
    public function aWidgetDrawingFromItsOwnFlagIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/FlagReadingRenderer.php'],
            [
                [
                    'Elmphant\Tui\Tests\Stan\Fixtures\FlagReadingRenderer::render() draws from its own focus flag. Where the keyboard is belongs to the tree, so ask $context through drawsFocused() — a flag is a message behind by the time a frame is drawn.',
                    45,
                ],
            ],
        );
    }

    #[Test]
    public function askingTheContextIsHowTheTreeIsRead(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/FocusAskedOfTheContext.php'], []);
    }

    protected function getRule(): Rule
    {
        return new FocusIsAskedWhileDrawingRule();
    }
}
