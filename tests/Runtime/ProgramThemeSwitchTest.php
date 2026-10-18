<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Runtime;

use ArrayObject;
use Elmphant\Tui\Core\Cmd;
use Elmphant\Tui\Core\Message;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Context\RenderPass;
use Elmphant\Tui\Rendering\Frame;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Runtime\ChoosesItsTheme;
use Elmphant\Tui\Runtime\Model;
use Elmphant\Tui\Runtime\Program;
use Elmphant\Tui\Testing\VirtualTerminal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class ProgramThemeSwitchTest extends TestCase
{
    #[Test]
    public function aModelThatChoosesAnotherThemeIsDrawnWithIt(): void
    {
        /** @var ArrayObject<int, Theme> $drawn */
        $drawn = new ArrayObject();
        $terminal = new VirtualTerminal(20, 3);
        EventLoop::delay(0.02, static fn () => $terminal->simulateInput('t'));
        $watcher = EventLoop::repeat(0.01, static function () use ($drawn, $terminal): void {
            if (\in_array(Theme::light(), $drawn->getArrayCopy())) {
                $terminal->simulateInput('q');
            }
        });
        $giveUp = EventLoop::delay(5.0, static fn () => $terminal->simulateInput('q'));

        Program::new(new ThemeSwitchingModel($drawn))->withTerminal($terminal)->withTheme(Theme::dark())->start();
        EventLoop::cancel($watcher);
        EventLoop::cancel($giveUp);

        self::assertEquals(Theme::dark(), $drawn[0]);
        self::assertEquals(Theme::light(), $drawn[\count($drawn) - 1]);
    }
}

final readonly class ThemeSwitchingModel implements Model, ChoosesItsTheme
{
    /**
     * @param ArrayObject<int, Theme> $drawn
     */
    public function __construct(private ArrayObject $drawn, private bool $light = false)
    {
    }

    public function init(): ?Cmd
    {
        return null;
    }

    public function update(Message $message, Rect $area): UpdateResult
    {
        if (!$message instanceof KeyMsg) {
            return new UpdateResult($this);
        }

        return match ($message->key) {
            't' => new UpdateResult(new self($this->drawn, true)),
            'q' => new UpdateResult($this, Cmd::quit()),
            default => new UpdateResult($this),
        };
    }

    public function render(Frame $frame, RenderPass $pass): void
    {
        $this->drawn[] = $pass->context->theme;
    }

    public function theme(): ?Theme
    {
        return $this->light ? Theme::light() : null;
    }
}
