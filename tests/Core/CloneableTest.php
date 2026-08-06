<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\Cloneable;
use Error;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypeError;

final class CloneableTest extends TestCase
{
    #[Test]
    public function cloneWithOverridesSingleProperty(): void
    {
        $original = new class(x: 1, y: 2, name: 'test') {
            use Cloneable;

            public function __construct(
                public int $x = 0,
                public int $y = 0,
                public string $name = '',
            ) {
            }
        };
        $modified = $original->cloneWith(x: 99);
        self::assertSame(99, $modified->x);
        self::assertSame(2, $modified->y);
        self::assertSame('test', $modified->name);
    }

    #[Test]
    public function cloneWithOverridesMultipleProperties(): void
    {
        $original = new class(x: 1, y: 2, name: 'test') {
            use Cloneable;

            public function __construct(
                public int $x = 0,
                public int $y = 0,
                public string $name = '',
            ) {
            }
        };
        $modified = $original->cloneWith(x: 50, name: 'changed');
        self::assertSame(50, $modified->x);
        self::assertSame(2, $modified->y);
        self::assertSame('changed', $modified->name);
    }

    #[Test]
    public function cloneWithReturnsNewInstance(): void
    {
        $original = new class(x: 1) {
            use Cloneable;

            public function __construct(public int $x = 0)
            {
            }
        };
        $modified = $original->cloneWith(x: 2);
        self::assertNotSame($original, $modified);
        self::assertSame(1, $original->x);
    }

    #[Test]
    public function cloneWithNoOverridesReturnsSameInstance(): void
    {
        $original = new class(x: 5) {
            use Cloneable;

            public function __construct(public int $x = 0)
            {
            }
        };
        $noOp = $original->cloneWith();
        self::assertSame($original, $noOp);
        self::assertSame(5, $noOp->x);
    }

    #[Test]
    public function cloneWithWorksWithArraysAndNullables(): void
    {
        $original = new class(items: [1, 2], label: null) {
            use Cloneable;

            public function __construct(
                public array $items = [],
                public ?string $label = null,
            ) {
            }
        };
        $modified = $original->cloneWith(items: [3, 4, 5], label: 'hello');
        self::assertSame([3, 4, 5], $modified->items);
        self::assertSame('hello', $modified->label);
    }

    #[Test]
    public function cloneWithInvalidPropertyThrowsError(): void
    {
        $point = new class(1, 2) {
            use Cloneable;

            public function __construct(public readonly int $x, public readonly int $y)
            {
            }
        };
        $this->expectException(Error::class);
        $point->cloneWith(nonexistent: 42);
    }

    #[Test]
    public function cloneWithWrongTypeThrowsTypeError(): void
    {
        $point = new class(1, 2) {
            use Cloneable;

            public function __construct(public readonly int $x, public readonly int $y)
            {
            }
        };
        $this->expectException(TypeError::class);
        $point->cloneWith(x: 'not an int');
    }

    #[Test]
    public function cloneWithReturnsSameInstanceWhenOverridesMatchCurrentValues(): void
    {
        $original = new class(x: 5, name: 'hi') {
            use Cloneable;

            public function __construct(public int $x = 0, public string $name = '')
            {
            }
        };
        $noOp = $original->cloneWith(x: 5, name: 'hi');
        self::assertSame($original, $noOp, 'cloneWith() must return the same instance when no field actually changes');
    }

    #[Test]
    public function cloneWithReallocatesWhenAnyFieldDiffers(): void
    {
        $original = new class(x: 5, name: 'hi') {
            use Cloneable;

            public function __construct(public int $x = 0, public string $name = '')
            {
            }
        };
        $changed = $original->cloneWith(x: 5, name: 'bye');
        self::assertNotSame($original, $changed);
        self::assertSame(5, $changed->x);
        self::assertSame('bye', $changed->name);
    }

    #[Test]
    public function aPrivateFieldDeclaredBelowTheTraitHostSurvivesACopy(): void
    {
        $original = new SubclassWithPrivateField(x: 1, secret: 'kept');

        $modified = $original->cloneWith(x: 2);

        self::assertSame(2, $modified->x);
        self::assertSame('kept', $modified->secret());
    }

    #[Test]
    public function aPrivateFieldBelowTheTraitHostCanItselfBeOverridden(): void
    {
        $original = new SubclassWithPrivateField(x: 1, secret: 'kept');

        $modified = $original->cloneWith(secret: 'changed');

        self::assertSame(1, $modified->x);
        self::assertSame('changed', $modified->secret());
    }
}

abstract readonly class CloneableTraitHost
{
    use Cloneable;
}

final readonly class SubclassWithPrivateField extends CloneableTraitHost
{
    public function __construct(
        public int $x,
        private string $secret,
    ) {
    }

    public function secret(): string
    {
        return $this->secret;
    }
}
