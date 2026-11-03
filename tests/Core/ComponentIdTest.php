<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\ComponentId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComponentIdTest extends TestCase
{
    #[Test]
    public function anIdBelowAnAncestorIsNamedAsSeenFromInsideIt(): void
    {
        $field = ComponentId::named('log/form/0');

        self::assertEquals(
            [ComponentId::named('form/0'), ComponentId::named('0')],
            [$field->below(ComponentId::named('log')), $field->below(ComponentId::named('log/form'))],
        );
    }

    #[Test]
    public function anIdThatIsNotBelowTheAncestorHasNoNameInsideIt(): void
    {
        $field = ComponentId::named('log/form');

        self::assertSame(
            [null, null, null],
            [$field->below(ComponentId::named('log/form')), $field->below(ComponentId::named('lo')), $field->below(ComponentId::named('other'))],
        );
    }
}
