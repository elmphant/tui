<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan;

use Elmphant\Tui\Stan\SplitIsBoundRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @extends RuleTestCase<SplitIsBoundRule>
 */
final class SplitIsBoundRuleTest extends RuleTestCase
{
    #[Test]
    public function onlyASplitOutsideEveryBindingIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/SplitBindingApp.php'],
            [
                [
                    'Split::row() in SplitBindingApp::view() is not inside Component::bind(), so a drag on its divider is dropped. Bind it and keep state() in the write-back, or use Layout for panes that never move.',
                    23,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new SplitIsBoundRule();
    }
}
