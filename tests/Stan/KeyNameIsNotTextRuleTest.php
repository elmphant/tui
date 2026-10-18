<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Stan;

use Elmphant\Tui\Stan\KeyNameIsNotTextRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @extends RuleTestCase<KeyNameIsNotTextRule>
 */
final class KeyNameIsNotTextRuleTest extends RuleTestCase
{
    #[Test]
    public function aKeyComparedWithItsNameIsReported(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/KeyComparer.php'],
            [
                ["KeyMsg::\$key is never 'esc': a named key arrives as Key::Escape.", 14],
                ["KeyMsg::\$key is never 'Down': a named key arrives as Key::Down.", 26],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new KeyNameIsNotTextRule();
    }
}
