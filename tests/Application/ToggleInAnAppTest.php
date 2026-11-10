<?php

declare(strict_types=1);

namespace Elmphant\Tui\Tests\Application;

use Elmphant\Tui\Application\AppActions;
use Elmphant\Tui\Application\Component;
use Elmphant\Tui\Testing\ModelHarness;
use Elmphant\Tui\Tests\Application\Support\CopyableTestApp;
use Elmphant\Tui\Widget\Choice\Toggle;
use Elmphant\Tui\Widget\Widget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToggleInAnAppTest extends TestCase
{
    #[Test]
    public function theKeyHelpNamesEachLetterByItsLabel(): void
    {
        $screen = ModelHarness::for(new ToggleApp(Toggle::new()), 60, 30)->init()->press('?')->screen()->plainText();

        self::assertMatchesRegularExpression('/Yes\s+y/u', $screen);
        self::assertMatchesRegularExpression('/No\s+n/u', $screen);
    }

    #[Test]
    public function anAppActionOnTheSameLetterRunsInsteadOfTheToggle(): void
    {
        $harness = ModelHarness::for(new ToggleApp(Toggle::new()->withOn(true), withNextAction: true), 60, 30)->init()->press('n');

        self::assertSame(1, $harness->model()->nexts);
        self::assertTrue($harness->model()->toggle->isOn(), 'the app hears n first');
    }
}

final readonly class ToggleApp extends CopyableTestApp
{
    public function __construct(public Toggle $toggle, public bool $withNextAction = false, public int $nexts = 0)
    {
    }

    public function view(): Widget
    {
        return Component::bind('toggle', $this->toggle, static fn (self $app, Toggle $toggle): self => new self($toggle, $app->withNextAction, $app->nexts));
    }

    protected function actions(): AppActions
    {
        return $this->withNextAction
            ? parent::actions()->with('next', ['n'], static fn (self $app): self => new self($app->toggle, true, $app->nexts + 1), 'next')
            : parent::actions();
    }
}
