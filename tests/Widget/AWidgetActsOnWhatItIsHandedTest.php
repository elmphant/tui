<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Field\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every container hands a key to one child, so a widget that is handed one is
 * the one the keyboard is on. Asking again is how it disagrees with the router.
 */
final class AWidgetActsOnWhatItIsHandedTest extends TestCase
{
    #[Test]
    public function aFieldTypesTheKeyItIsHandedWithoutAskingAboutItsOwnFlag(): void
    {
        $typed = TextInput::new()->update(new KeyMsg('x'), Rect::fromSize(20, 1))->model;

        self::assertInstanceOf(TextInput::class, $typed);
        self::assertSame('x', $typed->value());
    }
}
