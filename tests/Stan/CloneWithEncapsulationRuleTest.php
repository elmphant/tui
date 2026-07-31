<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<CloneWithEncapsulationRule>
 */
final class CloneWithEncapsulationRuleTest extends RuleTestCase
{
    public function testAllowsSelfCloneCalls(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/SelfCloneCaller.php'], []);
    }

    public function testRejectsForeignCloneCalls(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/ForeignCloneCaller.php'],
            [[
                'cloneWith() on a foreign object is an internal keyed write; use the object\'s typed withX() method.',
                11,
            ]],
        );
    }

    public function testAllowsFrameworkDispatchPrimitives(): void
    {
        $this->analyse([__DIR__ . '/Fixtures/AllowListedCaller.php'], []);
    }

    protected function getRule(): Rule
    {
        return new CloneWithEncapsulationRule([
            Fixtures\AllowListedCaller::class,
        ]);
    }
}
