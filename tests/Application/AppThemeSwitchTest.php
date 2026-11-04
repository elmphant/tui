<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\App;
use Elmphant\Tui\Core\UpdateResult;
use Elmphant\Tui\Layout\Rect;
use Elmphant\Tui\Message\KeyMsg;
use Elmphant\Tui\Rendering\Span;
use Elmphant\Tui\Rendering\StyleToken;
use Elmphant\Tui\Rendering\Theme;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Widget\Prose\Text;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AppThemeSwitchTest extends TestCase
{
    #[Test]
    public function aKeyThatPicksAnotherThemeRepaintsInIt(): void
    {
        $harness = ModelHarness::for(new ThemeCyclingApp(), 10, 1, Theme::dark());
        $before = $harness->screen()->styleAt(0, 0)->foregroundValue();

        $after = $harness->press('t')->screen()->styleAt(0, 0)->foregroundValue();

        self::assertSame(Theme::dark()->accent->foreground?->value, $before);
        self::assertSame(Theme::light()->accent->foreground?->value, $after);
    }
}

final readonly class ThemeCyclingApp extends App
{
    public function __construct(public bool $light = false)
    {
    }

    public function view(): Widget
    {
        return Text::line(Span::token('x', StyleToken::Accent));
    }

    public function theme(): ?Theme
    {
        return $this->light ? Theme::light() : null;
    }

    protected function onKey(KeyMsg $message, Rect $screen): UpdateResult
    {
        return $message->key === 't'
            ? new UpdateResult(new self(!$this->light))
            : new UpdateResult($this);
    }
}
