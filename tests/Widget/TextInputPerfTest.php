<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Widget;

use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\Key;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderContext;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Tests\Support\PerformanceBudget;
use Elmphant\Tui\Widget\Field\TextInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextInputPerfTest extends TestCase
{
    use PerformanceBudget;

    #[Test]
    public function aPastedMegabyteEditsAsQuicklyAsALine(): void
    {
        self::skipUnderXdebug();

        $area = Rect::fromSize(80, 1);
        $context = RenderContext::focused(Theme::dark());
        $edit = static function (TextInput $field) use ($area, $context): void {
            $field->update(new KeyMsg(Key::Left), $area)->model->render(new Frame(80, 1), $area, $context);
        };
        $line = TextInput::new(str_repeat('x', 80));
        $megabyte = TextInput::new(str_repeat('x', 1 << 20));

        self::assertRatioBelow(self::MAX_RATIO, static function () use ($edit, $line, $megabyte): array {
            $short = self::medianMicroseconds(static fn () => $edit($line), 20);
            $long = self::medianMicroseconds(static fn () => $edit($megabyte), 20);
            $ratio = $long / $short;

            return [$ratio, sprintf('A keystroke in a 1 MiB field cost %.0fx one in a line (%.0fµs vs %.0fµs)', $ratio, $long, $short)];
        });
    }

    #[Test]
    public function aPastedMegabyteWithOneAccentMovesTheCaretAsQuicklyAsALine(): void
    {
        self::assertCaretMovesAsQuicklyAsInALine(str_repeat('x', 1 << 20) . 'é');
    }

    #[Test]
    public function aPastedMegabyteOfAccentsMovesTheCaretAsQuicklyAsALine(): void
    {
        self::assertCaretMovesAsQuicklyAsInALine(str_repeat('é', 1 << 19));
    }

    private static function assertCaretMovesAsQuicklyAsInALine(string $value): void
    {
        self::skipUnderXdebug();

        $area = Rect::fromSize(80, 1);
        $context = RenderContext::focused(Theme::dark());
        $line = TextInput::new(str_repeat('é', 80));
        $long = TextInput::new($value);
        $moveLeft = static function (TextInput $field) use ($area, $context): TextInput {
            $moved = $field->update(new KeyMsg(Key::Left), $area)->model;
            $moved->render(new Frame(80, 1), $area, $context);

            return $moved;
        };

        self::assertRatioBelow(self::MAX_RATIO, static function () use ($moveLeft, $line, $long): array {
            $short = self::medianMicroseconds(static fn () => $moveLeft($line), 20);
            $longest = self::medianMicroseconds(static fn () => $moveLeft($long), 20);
            $ratio = $longest / $short;

            return [$ratio, sprintf('Moving the caret in a long field cost %.0fx moving it in a line (%.0fµs vs %.0fµs)', $ratio, $longest, $short)];
        });
    }
}
