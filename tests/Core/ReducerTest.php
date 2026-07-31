<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Core;

use Elmphant\Tui\Core\Model;
use Elmphant\Tui\Core\Reducer;
use Elmphant\Tui\Widget\Interactive;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReducerTest extends TestCase
{
    #[Test]
    public function rootModelsAndInteractiveWidgetsShareOneReducerContract(): void
    {
        self::assertTrue(is_subclass_of(Model::class, Reducer::class));
        self::assertTrue(is_subclass_of(Interactive::class, Reducer::class));
    }
}
