<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan;

use Elmphant\Tui\Stan\NamedRebuildRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @extends RuleTestCase<NamedRebuildRule>
 */
final class NamedRebuildRuleTest extends RuleTestCase
{
    #[Test]
    public function aRebuildNamingEveryParameterPasses(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/NamedRebuilder.php'], []);
    }

    #[Test]
    public function aPositionalRebuildReportsTheParametersItLeavesToOrder(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/PositionalRebuilder.php'],
            [
                [
                    'withLabel() rebuilds PositionalRebuilder passing $label, $width, $wrap by position. Reordering the constructor would silently reassign them. Name every parameter, or use cloneWith().',
                    18,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new NamedRebuildRule();
    }
}
