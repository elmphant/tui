<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan;

use Elmphant\Tui\Stan\CompleteRebuildRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @extends RuleTestCase<CompleteRebuildRule>
 */
final class CompleteRebuildRuleTest extends RuleTestCase
{
    #[Test]
    public function aRebuildNamingEveryParameterPasses(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/NamedRebuilder.php'], []);
    }

    #[Test]
    public function aPositionalRebuildCoveringEveryParameterPasses(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/PositionalRebuilder.php'], []);
    }

    #[Test]
    public function aRebuildOmittingAParameterReportsTheFieldItDrops(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/LossyRebuilder.php'],
            [
                [
                    'withLabel() rebuilds LossyRebuilder without $wrap. An omitted parameter takes its constructor default, resetting the field. Pass every parameter, or use cloneWith().',
                    18,
                ],
                [
                    'withWidth() rebuilds LossyRebuilder without $label, $wrap. An omitted parameter takes its constructor default, resetting the field. Pass every parameter, or use cloneWith().',
                    23,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new CompleteRebuildRule();
    }
}
