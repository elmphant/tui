<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Widget\Bar\Header;
use Elmphant\Tui\Widget\Field\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BarSlotRoutesInputTest extends TestCase
{
    #[Test]
    public function aKeyReachesTheMarkedSlotAndNoOther(): void
    {
        $header = Header::new()
            ->withLeft(TextInput::new())
            ->withRight(TextInput::new()->withFocus(true));

        $typed = $header->update(new KeyMsg('x'), Rect::fromSize(60, 1))->model;

        self::assertInstanceOf(Header::class, $typed);
        self::assertSame(['', 'x'], self::slotValues($typed), 'the right slot is the marked one');
    }

    /**
     * @return list<string>
     */
    private static function slotValues(Header $header): array
    {
        $values = [];

        foreach ($header->children() as $child) {
            if ($child instanceof TextInput) {
                $values[] = $child->value();
            }
        }

        return $values;
    }
}
