<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Support;

use Elmphant\Tui\Support\Cloneable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CloneableTest extends TestCase
{
    #[Test]
    public function aBuilderChangesItsOwnFieldAndKeepsTheOthers(): void
    {
        $original = new Pin(1, 'home', [1, 2]);

        $moved = $original->withX(9);

        self::assertSame([9, 'home', [1, 2]], [$moved->x, $moved->name, $moved->items]);
        self::assertSame(1, $original->x);
    }

    #[Test]
    public function aBuilderGivenTheValueAlreadyHeldAnswersWithTheSameInstance(): void
    {
        $pin = new Pin(1, 'home', [1, 2]);

        self::assertSame($pin, $pin->withX(1));
        self::assertSame($pin, $pin->withItems([1, 2]));
        self::assertNotSame($pin, $pin->withItems([1, 2, 3]));
    }

    #[Test]
    public function aPrivateFieldOfASubclassSurvivesABuilderOfAnotherField(): void
    {
        $original = new SubclassWithPrivateField(x: 1, secret: 'kept');

        $modified = $original->withX(2);

        self::assertSame(2, $modified->x);
        self::assertSame('kept', $modified->secret());
    }

    #[Test]
    public function aSubclassBuilderCanChangeItsOwnPrivateField(): void
    {
        $original = new SubclassWithPrivateField(x: 1, secret: 'kept');

        $modified = $original->withSecret('changed');

        self::assertSame(1, $modified->x);
        self::assertSame('changed', $modified->secret());
    }
}

final readonly class Pin
{
    use Cloneable;

    /**
     * @param list<int> $items
     */
    public function __construct(public int $x, public string $name, public array $items)
    {
    }

    public function withX(int $x): self
    {
        return $this->cloneWith(x: $x);
    }

    /**
     * @param list<int> $items
     */
    public function withItems(array $items): self
    {
        return $this->cloneWith(items: $items);
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

    public function withX(int $x): self
    {
        return $this->cloneWith(x: $x);
    }

    public function withSecret(string $secret): self
    {
        return $this->cloneWith(secret: $secret);
    }
}
